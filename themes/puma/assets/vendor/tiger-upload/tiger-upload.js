/*!
 * TigerUpload — a headless, framework-agnostic file-upload engine with pub/sub progress.
 * MIT © WebTigers. https://github.com/WebTigers/TigerUpload
 *
 * The engine owns the heavy lifting — full-page/scoped drop, a file input, per-file XHR uploads
 * with REAL progress (XMLHttpRequest.upload; fetch() still can't report upload progress), instant
 * image previews (object URLs) — and BROADCASTS lifecycle events to any number of subscribers.
 * It renders nothing: each subscriber draws the files + progress however it likes (a DataTable of
 * temp rows, a portfolio of cards, a plain list). One upload, N renderers.
 *
 *   var up = TigerUpload.create({ url:'/api', field:'archive',
 *       params:{ module:'system', service:'modules', method:'upload' }, accept:['.zip'] });
 *   up.dropOnWindow({ label:'Drop your .zip' });   // full-page overlay
 *   up.fromInput(document.getElementById('file'));  // click-to-browse
 *   up.subscribe({ onAdd, onProgress, onSuccess, onError, onComplete });
 *
 * The default response parser understands the Tiger /api envelope ({result,data,redirect,messages});
 * pass `parseResponse` to use it against any other backend.
 */
(function (global) {
  'use strict';

  var IMAGE_RX = /^image\//;
  var seq = 0;
  function uid() { return 'up_' + (++seq).toString(36) + Math.floor(seq * 2654435761 % 1e6).toString(36); }

  /* ---- default response parser: the Tiger /api envelope ---- */
  function tigerParse(xhr) {
    var json = null;
    try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
    var ok2xx = xhr.status >= 200 && xhr.status < 300;
    if (ok2xx && json && json.result === 1) {
      return { ok: true, data: json.data, redirect: json.redirect || null, message: firstMessage(json) };
    }
    var err = firstMessage(json) || (json && json.message) || ('Upload failed (HTTP ' + (xhr.status || 0) + ').');
    return { ok: false, error: err };
  }
  function firstMessage(json) {
    return (json && json.messages && json.messages[0] && json.messages[0].message) || '';
  }

  /* ---- the uploader ---- */
  function Uploader(opts) {
    opts = opts || {};
    this.opts = {
      url:           opts.url || '/api',
      field:         opts.field || 'file',
      params:        opts.params || {},
      csrf:          opts.csrf || null,
      csrfField:     opts.csrfField || '_csrf',
      accept:        normAccept(opts.accept),
      maxSize:       opts.maxSize || 0,            // bytes; 0 = no limit
      multiple:      opts.multiple !== false,      // default: many
      concurrency:   opts.concurrency || 3,
      auto:          opts.auto !== false,          // default: upload on add
      withCredentials: !!opts.withCredentials,
      headers:       opts.headers || { 'X-Requested-With': 'XMLHttpRequest' },
      parseResponse: opts.parseResponse || tigerParse
    };
    this.items    = [];
    this._subs    = [];
    this._queue   = [];
    this._active  = 0;
    this._sources = [];    // teardown fns for attached drop/input sources
  }

  Uploader.prototype = {

    /* --- pub/sub: a subscriber is an object of lifecycle callbacks; returns an unsubscribe fn --- */
    subscribe: function (sub) {
      this._subs.push(sub);
      var self = this;
      return function () { var i = self._subs.indexOf(sub); if (i > -1) { self._subs.splice(i, 1); } };
    },
    _emit: function (name, a, b) {
      for (var i = 0; i < this._subs.length; i++) {
        var fn = this._subs[i][name];
        if (typeof fn === 'function') {
          try { fn.call(this._subs[i], a, b); }
          catch (e) { if (global.console) { global.console.error('TigerUpload subscriber error', e); } }
        }
      }
    },

    /* --- add files (FileList | File[] | File). Rejected files fire onReject, not onAdd. --- */
    add: function (files) {
      var list = toArray(files);
      if (!this.opts.multiple && list.length > 1) { list = [list[list.length - 1]]; }
      for (var i = 0; i < list.length; i++) { this._addOne(list[i]); }
      if (this.opts.auto) { this.start(); }
      return this;
    },

    _addOne: function (file) {
      var reason = this._reject(file);
      if (reason) {
        this._emit('onReject', { name: file.name, size: file.size, type: file.type || '', error: reason });
        return null;
      }
      if (!this.opts.multiple) { this._clearQueued(); }   // single-file: replace any staged file
      var item = {
        id: uid(), file: file, name: file.name, size: file.size, type: file.type || '',
        isImage: IMAGE_RX.test(file.type || ''), previewUrl: null,
        status: 'queued', loaded: 0, total: file.size, percent: 0,
        response: null, redirect: null, message: null, error: null, _xhr: null
      };
      if (item.isImage) { try { item.previewUrl = objectUrl().createObjectURL(file); } catch (e) {} }
      this.items.push(item);
      this._queue.push(item);
      this._emit('onAdd', item);
      return item;
    },

    _reject: function (file) {
      if (this.opts.maxSize && file.size > this.opts.maxSize) {
        return 'File is too large (max ' + Math.round(this.opts.maxSize / 1048576) + ' MB).';
      }
      var acc = this.opts.accept;
      if (acc && acc.length) {
        var name = (file.name || '').toLowerCase(), type = (file.type || '').toLowerCase(), ok = false;
        for (var i = 0; i < acc.length; i++) {
          var a = acc[i];
          if (a.charAt(0) === '.') { if (name.length >= a.length && name.slice(-a.length) === a) { ok = true; break; } }
          else if (a.slice(-2) === '/*') { if (type.indexOf(a.slice(0, -1)) === 0) { ok = true; break; } }
          else if (type === a) { ok = true; break; }
        }
        if (!ok) { return 'That file type isn’t allowed.'; }
      }
      return null;
    },

    /* --- start / pump the queue up to the concurrency limit --- */
    start: function () {
      while (this._active < this.opts.concurrency && this._queue.length) {
        this._upload(this._queue.shift());
      }
      return this;
    },

    _upload: function (item) {
      var self = this;
      item.status = 'uploading';
      this._active++;

      var xhr = new XMLHttpRequest();
      item._xhr = xhr;
      xhr.open('POST', this.opts.url, true);
      xhr.withCredentials = this.opts.withCredentials;
      var h = this.opts.headers || {};
      for (var k in h) { if (h.hasOwnProperty(k)) { try { xhr.setRequestHeader(k, h[k]); } catch (e) {} } }

      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) {
          item.loaded = e.loaded; item.total = e.total;
          item.percent = e.total ? Math.round(e.loaded / e.total * 100) : 0;
        }
        self._emit('onProgress', item);
      };
      xhr.onload = function () {
        self._active--;
        var parsed = self.opts.parseResponse(xhr);
        if (parsed && parsed.ok) {
          item.status = 'done'; item.percent = 100;
          item.response = parsed.data; item.redirect = parsed.redirect || null; item.message = parsed.message || null;
          self._emit('onSuccess', item);
        } else {
          item.status = 'error'; item.error = (parsed && parsed.error) || 'Upload failed.';
          self._emit('onError', item);
        }
        self._pump();
      };
      xhr.onerror   = function () { self._fail(item, 'Network error during upload.'); };
      xhr.ontimeout = function () { self._fail(item, 'Upload timed out.'); };
      xhr.onabort   = function () { self._active--; item.status = 'canceled'; self._emit('onCancel', item); self._pump(); };

      var fd = new FormData();
      var p = this.opts.params || {};
      for (var pk in p) { if (p.hasOwnProperty(pk)) { fd.append(pk, p[pk]); } }
      var token = this.csrfToken();
      if (token) { fd.append(this.opts.csrfField, token); }
      fd.append(this.opts.field, item.file, item.name);
      xhr.send(fd);
    },

    _fail: function (item, msg) {
      this._active--; item.status = 'error'; item.error = msg;
      this._emit('onError', item); this._pump();
    },
    _pump: function () {
      if (this._queue.length) { this.start(); }
      else if (this._active === 0) { this._emit('onComplete', this.items.slice()); }
    },

    /* --- per-item controls --- */
    retry: function (id) {
      var it = this._byId(id);
      if (it && (it.status === 'error' || it.status === 'canceled')) {
        it.status = 'queued'; it.error = null; it.percent = 0; it.loaded = 0;
        this._queue.push(it); this._emit('onAdd', it);
        if (this.opts.auto) { this.start(); }
      }
      return this;
    },
    cancel: function (id) {
      var it = this._byId(id);
      if (it && it.status === 'uploading' && it._xhr) { it._xhr.abort(); }
      else if (it && it.status === 'queued') { this._dequeue(it); it.status = 'canceled'; this._emit('onCancel', it); }
      return this;
    },
    remove: function (id) {
      var it = this._byId(id);
      if (!it) { return this; }
      this.cancel(id);
      this._revoke(it);
      var i = this.items.indexOf(it); if (i > -1) { this.items.splice(i, 1); }
      this._emit('onRemove', it);
      return this;
    },
    clear: function () { for (var i = this.items.length - 1; i >= 0; i--) { this.remove(this.items[i].id); } return this; },

    csrfToken: function () {
      if (this.opts.csrf) { return this.opts.csrf; }
      if (!global.document) { return null; }
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta && meta.content) { return meta.content; }
      var inp = document.querySelector('input[name="' + this.opts.csrfField + '"]');
      return inp ? inp.value : null;
    },

    /* ---------- sources ---------- */

    /** A <input type="file">: its selected files are added on change (then the input is reset). */
    fromInput: function (input) {
      if (!input) { return this; }
      var self = this;
      var on = function () { if (input.files && input.files.length) { self.add(input.files); input.value = ''; } };
      input.addEventListener('change', on);
      this._sources.push(function () { input.removeEventListener('change', on); });
      return this;
    },

    /** A scoped drop zone. Stops propagation so it never double-fires with dropOnWindow. */
    dropOnElement: function (el, o) {
      o = o || {}; var self = this;
      if (typeof el === 'string') { el = document.querySelector(el); }
      if (!el) { return this; }
      var cls = o.activeClass || 'tiger-upload-over';
      var over  = function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); e.stopPropagation(); if (e.dataTransfer) { e.dataTransfer.dropEffect = 'copy'; } el.classList.add(cls); };
      var leave = function () { el.classList.remove(cls); };
      var drop  = function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); e.stopPropagation(); el.classList.remove(cls);
        var f = e.dataTransfer && e.dataTransfer.files; if (o.onDrop) { o.onDrop(f); } if (f && f.length) { self.add(f); } };
      el.addEventListener('dragenter', over); el.addEventListener('dragover', over);
      el.addEventListener('dragleave', leave); el.addEventListener('drop', drop);
      this._sources.push(function () {
        el.removeEventListener('dragenter', over); el.removeEventListener('dragover', over);
        el.removeEventListener('dragleave', leave); el.removeEventListener('drop', drop);
      });
      return this;
    },

    /**
     * Full-page drop: window-level listeners + a fixed, full-viewport overlay shown only while a
     * FILE drag is over the window. A dragenter/dragleave DEPTH counter prevents the flicker that
     * naive dragleave-hides suffer (child elements fire enter/leave as you cross them). preventDefault
     * on dragover/drop stops the browser navigating to a dropped file. `onDrop(files)` fires once per
     * drop (before files are added) — e.g. to switch to an upload tab.
     */
    dropOnWindow: function (o) {
      o = o || {}; var self = this;
      if (!global.document || !global.window) { return this; }
      ensureOverlayStyles();
      var overlay = buildOverlay(o.label || 'Drop files to upload', o.sublabel || '');
      document.body.appendChild(overlay);

      var depth = 0;
      var show = function () { overlay.classList.add('is-active'); };
      var hide = function () { depth = 0; overlay.classList.remove('is-active'); };
      var onEnter = function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); depth++; show(); };
      var onOver  = function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); if (e.dataTransfer) { e.dataTransfer.dropEffect = 'copy'; } };
      var onLeave = function (e) { if (!hasFiles(e)) { return; } depth = Math.max(0, depth - 1); if (depth === 0) { hide(); } };
      var onDrop  = function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); hide();
        var f = e.dataTransfer && e.dataTransfer.files; if (o.onDrop) { o.onDrop(f); } if (f && f.length) { self.add(f); } };

      window.addEventListener('dragenter', onEnter);
      window.addEventListener('dragover', onOver);
      window.addEventListener('dragleave', onLeave);
      window.addEventListener('drop', onDrop);
      this._sources.push(function () {
        window.removeEventListener('dragenter', onEnter); window.removeEventListener('dragover', onOver);
        window.removeEventListener('dragleave', onLeave); window.removeEventListener('drop', onDrop);
        if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
      });
      return this;
    },

    /** Tear down all sources + revoke previews. */
    destroy: function () {
      for (var i = 0; i < this._sources.length; i++) { try { this._sources[i](); } catch (e) {} }
      this._sources = []; this.clear(); this._subs = [];
      return this;
    },

    /* ---- internals ---- */
    _byId: function (id) { for (var i = 0; i < this.items.length; i++) { if (this.items[i].id === id) { return this.items[i]; } } return null; },
    _dequeue: function (it) { var i = this._queue.indexOf(it); if (i > -1) { this._queue.splice(i, 1); } },
    _clearQueued: function () {
      for (var i = this.items.length - 1; i >= 0; i--) {
        if (this.items[i].status === 'queued') { this._revoke(this.items[i]); this.items.splice(i, 1); }
      }
      this._queue = [];
    },
    _revoke: function (it) { if (it.previewUrl) { try { objectUrl().revokeObjectURL(it.previewUrl); } catch (e) {} it.previewUrl = null; } }
  };

  /* ---- helpers ---- */
  function toArray(f) {
    if (!f) { return []; }
    if (typeof f.length === 'number' && typeof f !== 'string' && f.name == null) { return Array.prototype.slice.call(f); }
    if (f.name != null && f.size != null) { return [f]; }   // a single File
    return Array.prototype.slice.call(f);
  }
  function normAccept(a) {
    if (!a) { return null; }
    if (typeof a === 'string') { a = a.split(','); }
    var out = [];
    for (var i = 0; i < a.length; i++) { var s = String(a[i]).trim().toLowerCase(); if (s) { out.push(s); } }
    return out.length ? out : null;
  }
  function hasFiles(e) {
    var dt = e.dataTransfer; if (!dt || !dt.types) { return false; }
    for (var i = 0; i < dt.types.length; i++) {
      if (dt.types[i] === 'Files' || dt.types[i] === 'application/x-moz-file') { return true; }
    }
    return false;
  }
  function objectUrl() { return global.URL || global.webkitURL; }

  /* ---- the full-page overlay (self-contained styles; themeable via CSS vars) ---- */
  function ensureOverlayStyles() {
    if (!global.document || document.getElementById('tiger-upload-styles')) { return; }
    var s = document.createElement('style');
    s.id = 'tiger-upload-styles';
    s.textContent = OVERLAY_CSS;
    document.head.appendChild(s);
  }
  function buildOverlay(label, sub) {
    var d = document.createElement('div');
    d.className = 'tiger-upload-overlay';
    d.setAttribute('aria-hidden', 'true');
    d.innerHTML =
      '<div class="tiger-upload-overlay__inner">' +
        '<svg class="tiger-upload-overlay__icon" viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
          '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>' +
        '<div class="tiger-upload-overlay__label"></div>' +
        '<div class="tiger-upload-overlay__sub"></div>' +
      '</div>';
    d.querySelector('.tiger-upload-overlay__label').textContent = label;
    d.querySelector('.tiger-upload-overlay__sub').textContent = sub;
    return d;
  }
  var OVERLAY_CSS =
    '.tiger-upload-overlay{position:fixed;inset:0;z-index:2000;display:none;pointer-events:none;' +
      'align-items:center;justify-content:center;padding:2rem;' +
      'background:var(--tiger-upload-overlay-bg,rgba(15,23,42,.72));' +
      'backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);' +
      'color:var(--tiger-upload-overlay-fg,#fff);' +
      'animation:tiger-upload-fade .12s ease-out}' +
    '.tiger-upload-overlay.is-active{display:flex}' +
    '.tiger-upload-overlay__inner{pointer-events:none;text-align:center;max-width:32rem;' +
      'border:2.5px dashed var(--tiger-upload-accent,#3b82f6);border-radius:1rem;' +
      'padding:2.5rem 3rem;background:rgba(255,255,255,.04)}' +
    '.tiger-upload-overlay__icon{color:var(--tiger-upload-accent,#3b82f6);margin-bottom:.75rem}' +
    '.tiger-upload-overlay__label{font-size:1.5rem;font-weight:600;line-height:1.2}' +
    '.tiger-upload-overlay__sub{margin-top:.35rem;opacity:.75;font-size:.95rem}' +
    '@keyframes tiger-upload-fade{from{opacity:0}to{opacity:1}}';

  /* ---- export ---- */
  var TigerUpload = { create: function (o) { return new Uploader(o); }, Uploader: Uploader, tigerParse: tigerParse };
  if (typeof module !== 'undefined' && module.exports) { module.exports = TigerUpload; }
  global.TigerUpload = TigerUpload;

})(typeof window !== 'undefined' ? window : this);
