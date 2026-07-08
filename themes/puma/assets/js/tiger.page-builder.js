/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * tiger.page-builder.js — boots the GrapesJS visual builder for one CMS page and saves
 * through the Tiger /api (Cms_Service_Page::saveDesign). Zero build step: GrapesJS + the
 * webpage preset are vendored UMD bundles. Config arrives on window.TIGER_BUILDER:
 *   { pageId, api, project|null, seedHtml }
 * The canvas restores losslessly from the project blob when present, else it imports the
 * page's current body HTML as a starting point.
 */
(function () {
  'use strict';

  var cfg = window.TIGER_BUILDER || {};
  if (!window.grapesjs || !document.getElementById('gjs')) { return; }

  var preset = window['grapesjs-preset-webpage'];

  var editor = grapesjs.init({
    container: '#gjs',
    height: '100%',
    fromElement: false,
    storageManager: false,            // Tiger owns persistence, via /api
    // Choosing/uploading an image opens the shared Tiger Media Library (TigerMediaPicker
    // over Media_Service_Media) instead of GrapesJS's default uploader — so page media is
    // real TigerMedia (public URL / CDN), never a base64 blob inlined into the body.
    assetManager: {
      custom: {
        open: function (props) {
          if (!window.TigerMediaPicker) { return; }
          window.TigerMediaPicker.open({
            kind: 'image',
            onSelect: function (item) {
              if (item && item.url) {
                try { editor.AssetManager.add({ src: item.url, name: item.filename }); } catch (e) {}
                try { props.select({ src: item.url, name: item.filename }, true); } catch (e) {}
              }
              if (props && props.close) { props.close(); }
            }
          });
        },
        close: function () {}
      }
    },
    plugins: preset ? [preset] : [],
    pluginsOpts: preset ? { 'grapesjs-preset-webpage': { modalImportTitle: 'Import HTML' } } : {}
  });

  // Seed the canvas: prefer the lossless project blob, else import the body HTML.
  try {
    if (cfg.project) {
      editor.loadProjectData(cfg.project);
    } else if (cfg.seedHtml) {
      editor.setComponents(cfg.seedHtml);
    }
  } catch (e) {
    if (cfg.seedHtml) { try { editor.setComponents(cfg.seedHtml); } catch (e2) {} }
  }

  function setStatus(text, cls) {
    var el = document.getElementById('tb-status');
    if (el) { el.textContent = text || ''; el.className = 'tb-status ' + (cls || ''); }
  }

  var saving = false;
  var dirty  = false;

  function save() {
    if (saving) { return; }
    saving = true;
    setStatus('Saving…', 'saving');

    var body = new URLSearchParams();
    body.set('module', 'cms');
    body.set('service', 'page');
    body.set('method', 'saveDesign');
    body.set('page_id', cfg.pageId || '');
    body.set('html', editor.getHtml());
    body.set('css', editor.getCss());
    body.set('project', JSON.stringify(editor.getProjectData()));

    fetch(cfg.api || '/api', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.result) { dirty = false; setStatus('Saved ✓', 'ok'); }
        else { setStatus('Save failed', 'err'); }
      })
      .catch(function () { setStatus('Save failed', 'err'); })
      .finally(function () { saving = false; });
  }

  var saveBtn = document.getElementById('tb-save');
  if (saveBtn) { saveBtn.addEventListener('click', save); }

  // Ctrl/Cmd+S saves without leaving the builder.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 's') { e.preventDefault(); save(); }
  });

  // Track unsaved edits; warn before closing. GrapesJS fires 'update' after its initial
  // seed, so arm the flag only once the editor has settled.
  editor.on('load', function () {
    setTimeout(function () { editor.on('update', function () { dirty = true; setStatus('Unsaved changes', ''); }); }, 400);
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  window.TigerBuilder = { editor: editor, save: save };
})();
