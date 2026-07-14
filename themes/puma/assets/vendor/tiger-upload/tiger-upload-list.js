/*!
 * TigerUpload.list — an OPTIONAL default renderer for TigerUpload.
 * MIT © WebTigers. https://github.com/WebTigers/TigerUpload
 *
 * A ready-made subscriber that renders a thumbnail + progress list into a container — so a simple
 * screen gets upload UI for free. Fancy screens (a DataTable, a portfolio grid) skip this and write
 * their own subscriber against the same events. Load it AFTER tiger-upload.js; it registers as
 * TigerUpload.list.
 *
 *   var up = TigerUpload.create({ url:'/api', field:'file', accept:['image/*'] });
 *   TigerUpload.list('#drop-list', up, { prepend:true, onSuccess:function(item,el){ ... } });
 *   up.dropOnElement('#drop-list'); up.fromInput('#file');
 *
 * Callbacks (all optional) let the host enrich each row: onAdd/onProgress/onSuccess/onError/onReject
 * receive (item, el). Styles are self-contained (.tu-*), themeable via CSS vars.
 */
(function (global) {
  'use strict';
  var TU = global.TigerUpload;
  if (!TU) { if (global.console) { global.console.error('TigerUpload.list: load tiger-upload.js first'); } return; }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtBytes(n) {
    if (n == null) { return ''; }
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(1) : n) + ' ' + u[i];
  }
  function ext(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ''); return m ? m[1].toUpperCase().slice(0, 4) : 'FILE'; }

  function list(container, uploader, opts) {
    opts = opts || {};
    if (typeof container === 'string') { container = document.querySelector(container); }
    if (!container) { return null; }
    ensureStyles();

    var els = {};   // item.id -> row element

    function build(item) {
      var el = document.createElement('div');
      el.className = 'tu-row';
      el.setAttribute('data-tu-id', item.id);
      var thumb = item.previewUrl
        ? '<img class="tu-thumb" src="' + item.previewUrl + '" alt="">'
        : '<div class="tu-thumb tu-thumb--file">' + esc(ext(item.name)) + '</div>';
      el.innerHTML =
        thumb +
        '<div class="tu-body">' +
          '<div class="tu-name">' + esc(item.name) + '</div>' +
          '<div class="tu-meta"><span>' + fmtBytes(item.size) + '</span><span class="tu-status"></span></div>' +
          '<div class="tu-bar"><div class="tu-bar__fill"></div></div>' +
        '</div>' +
        '<button type="button" class="tu-x" aria-label="Remove">&times;</button>';
      el.querySelector('.tu-x').addEventListener('click', function () { uploader.remove(item.id); });
      return el;
    }
    function bar(el, pct) { el.querySelector('.tu-bar__fill').style.width = (pct || 0) + '%'; }
    function status(el, txt, cls) { var s = el.querySelector('.tu-status'); s.textContent = txt; s.className = 'tu-status' + (cls ? ' ' + cls : ''); }
    function place(el) { if (opts.prepend && container.firstChild) { container.insertBefore(el, container.firstChild); } else { container.appendChild(el); } }
    function drop(item) { var el = els[item.id]; if (el && el.parentNode) { el.parentNode.removeChild(el); } delete els[item.id]; }

    var unsub = uploader.subscribe({
      onAdd: function (item) {
        var el = build(item); els[item.id] = el; place(el);
        status(el, 'Queued', 'tu-muted');
        if (opts.onAdd) { opts.onAdd(item, el); }
      },
      onProgress: function (item) {
        var el = els[item.id]; if (!el) { return; }
        el.classList.add('is-uploading'); bar(el, item.percent); status(el, item.percent + '%');
        if (opts.onProgress) { opts.onProgress(item, el); }
      },
      onSuccess: function (item) {
        var el = els[item.id]; if (!el) { return; }
        el.classList.remove('is-uploading'); el.classList.add('is-done'); bar(el, 100); status(el, 'Done', 'tu-ok');
        if (opts.onSuccess) { opts.onSuccess(item, el); }
      },
      onError: function (item) {
        var el = els[item.id]; if (!el) { return; }
        el.classList.remove('is-uploading'); el.classList.add('is-error'); status(el, item.error || 'Failed', 'tu-err');
        if (!el.querySelector('.tu-retry')) {
          var b = document.createElement('button');
          b.type = 'button'; b.className = 'tu-retry'; b.textContent = 'Retry';
          b.addEventListener('click', function () { if (b.parentNode) { b.parentNode.removeChild(b); } uploader.retry(item.id); });
          el.querySelector('.tu-body').appendChild(b);
        }
        if (opts.onError) { opts.onError(item, el); }
      },
      onReject: function (info) {
        if (opts.onReject) { opts.onReject(info); return; }
        var el = document.createElement('div');
        el.className = 'tu-row is-error';
        el.innerHTML = '<div class="tu-thumb tu-thumb--file">!</div><div class="tu-body">' +
          '<div class="tu-name">' + esc(info.name) + '</div>' +
          '<div class="tu-meta"><span class="tu-status tu-err">' + esc(info.error) + '</span></div></div>';
        place(el);
      },
      onCancel: function (item) { drop(item); },
      onRemove: function (item) { drop(item); }
    });

    return { unsubscribe: unsub, elements: els, container: container };
  }

  function ensureStyles() {
    if (document.getElementById('tiger-upload-list-styles')) { return; }
    var s = document.createElement('style');
    s.id = 'tiger-upload-list-styles';
    s.textContent =
      '.tu-row{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border:1px solid var(--tu-border,rgba(128,128,128,.25));border-radius:.6rem;margin-bottom:.5rem;background:var(--tu-bg,rgba(128,128,128,.04))}' +
      '.tu-thumb{width:44px;height:44px;border-radius:.4rem;object-fit:cover;flex:0 0 auto;background:var(--tu-thumb-bg,rgba(128,128,128,.15))}' +
      '.tu-thumb--file{display:flex;align-items:center;justify-content:center;font:600 .7rem/1 system-ui,sans-serif;color:var(--tu-muted,#64748b)}' +
      '.tu-body{flex:1 1 auto;min-width:0}' +
      '.tu-name{font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.tu-meta{display:flex;gap:.5rem;font-size:.78rem;color:var(--tu-muted,#64748b);margin:.1rem 0 .35rem}' +
      '.tu-bar{height:5px;border-radius:3px;background:var(--tu-track,rgba(128,128,128,.2));overflow:hidden}' +
      '.tu-bar__fill{height:100%;width:0;border-radius:3px;background:var(--tu-accent,#3b82f6);transition:width .15s ease}' +
      '.tu-row.is-done .tu-bar__fill{background:var(--tu-ok,#22c55e)}' +
      '.tu-row.is-error .tu-bar__fill{background:var(--tu-err,#ef4444)}' +
      '.tu-status.tu-ok{color:var(--tu-ok,#22c55e)}.tu-status.tu-err{color:var(--tu-err,#ef4444)}.tu-status.tu-muted{opacity:.7}' +
      '.tu-x{flex:0 0 auto;border:0;background:none;font-size:1.25rem;line-height:1;cursor:pointer;color:var(--tu-muted,#94a3b8);padding:.1rem .35rem}' +
      '.tu-x:hover{color:var(--tu-err,#ef4444)}' +
      '.tu-retry{margin-top:.25rem;font-size:.75rem;border:1px solid var(--tu-border,rgba(128,128,128,.35));background:none;border-radius:.35rem;padding:.1rem .5rem;cursor:pointer;color:inherit}';
    document.head.appendChild(s);
  }

  TU.list = list;

})(typeof window !== 'undefined' ? window : this);
