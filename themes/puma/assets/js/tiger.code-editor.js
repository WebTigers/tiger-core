/*! SPDX-License-Identifier: BSD-3-Clause · © 2026 WebTigers · Tiger™/WebTigers™ are trademarks */
/**
 * TigerCodeEditor — a drop-in code editor for Tiger admin surfaces.
 *
 * Wraps vendored CodeMirror 5 (syntax highlight + code folding + bracket match, zero-build)
 * so any <textarea> becomes a colored, foldable editor. Reused by the CMS page editor, the
 * blog article "code" view, and (later) the PHP-snippets runtime — the same component WP Code
 * uses conceptually. Follows light/dark via Bootstrap's data-bs-theme.
 *
 * Usage:
 *   var cm = TigerCodeEditor.from(textarea, { mode: 'phtml' });   // returns the CodeMirror
 *   TigerCodeEditor.setMode(cm, 'css');                           // switch language live
 *   TigerCodeEditor.syncAll();                                    // CM -> textarea before AJAX save
 *   // or declaratively: <textarea data-code-editor data-mode="html">…</textarea>
 */
(function (window, document) {
  'use strict';

  // App language -> CodeMirror mode (MIME/name). phtml = PHP-in-HTML.
  var MODES = {
    html: 'htmlmixed', htmlmixed: 'htmlmixed',
    phtml: 'application/x-httpd-php', php: 'application/x-httpd-php',
    markdown: 'markdown', md: 'markdown',
    css: 'css',
    js: 'javascript', javascript: 'javascript',
    xml: 'xml',
    text: null, plain: null
  };

  var instances = [];

  function modeFor(m) {
    m = (m || 'htmlmixed').toLowerCase();
    return MODES.hasOwnProperty(m) ? MODES[m] : m;
  }
  function isDark() {
    return document.documentElement.getAttribute('data-bs-theme') === 'dark';
  }
  function themeName() { return isDark() ? 'material-darker' : 'default'; }

  var TigerCodeEditor = {
    /** Enhance a textarea into a CodeMirror editor; returns the instance (also textarea._cm). */
    from: function (textarea, opts) {
      if (!textarea || textarea._cm) { return textarea && textarea._cm; }
      if (typeof CodeMirror === 'undefined') { return null; }
      opts = opts || {};

      var ro = !!opts.readOnly;
      var cm = CodeMirror.fromTextArea(textarea, {
        mode: modeFor(opts.mode || textarea.getAttribute('data-mode') || 'htmlmixed'),
        theme: themeName(),
        readOnly: ro,                       // view-only surfaces (e.g. a module snippet's source)
        lineNumbers: true,
        lineWrapping: opts.wrap !== false,
        matchBrackets: true,
        autoCloseBrackets: !ro,
        styleActiveLine: !ro,
        foldGutter: true,
        indentUnit: 2,
        tabSize: 2,
        gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
        extraKeys: {
          'Ctrl-/': 'toggleComment', 'Cmd-/': 'toggleComment',
          'Ctrl-Q': function (c) { c.foldCode(c.getCursor()); }
        }
      });
      if (opts.minHeight) { cm.getWrapperElement().style.minHeight = opts.minHeight; }
      textarea._cm = cm;
      cm._textarea = textarea;
      instances.push(cm);
      return cm;
    },

    /** Switch an instance's language mode live (e.g. the CMS format select). */
    setMode: function (cm, mode) {
      if (cm) { cm.setOption('mode', modeFor(mode)); }
    },

    /** Flush every editor back to its <textarea> — call before serializing a form for AJAX. */
    syncAll: function () {
      instances.forEach(function (cm) { cm.save(); });
    },

    /** Fold / unfold every top-level block in an instance. */
    foldAll: function (cm) { this._fold(cm, true); },
    unfoldAll: function (cm) { this._fold(cm, false); },
    _fold: function (cm, fold) {
      if (!cm) { return; }
      cm.operation(function () {
        for (var l = cm.firstLine(); l <= cm.lastLine(); l++) {
          cm.foldCode({ line: l, ch: 0 }, null, fold ? 'fold' : 'unfold');
        }
      });
    },

    /** Auto-enhance any textarea[data-code-editor] on the page. */
    init: function () {
      var list = document.querySelectorAll('textarea[data-code-editor]');
      for (var i = 0; i < list.length; i++) { TigerCodeEditor.from(list[i]); }
    }
  };

  // Follow the app's light/dark toggle (Bootstrap data-bs-theme on <html>).
  if (window.MutationObserver) {
    new MutationObserver(function () {
      var t = themeName();
      instances.forEach(function (cm) { cm.setOption('theme', t); });
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
  }

  window.TigerCodeEditor = TigerCodeEditor;

  if (document.readyState !== 'loading') { TigerCodeEditor.init(); }
  else { document.addEventListener('DOMContentLoaded', TigerCodeEditor.init); }

})(window, document);
