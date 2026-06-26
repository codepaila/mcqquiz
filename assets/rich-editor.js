/* assets/rich-editor.js
 * Lightweight WYSIWYG editor that wraps an existing <textarea>.
 *
 * Usage:
 *   import { mountRichEditor } from './assets/rich-editor.js';
 *   mountRichEditor(document.querySelector('textarea[name="content"]'));
 *
 * The textarea is hidden but kept in the DOM with its name attribute,
 * so existing FormData / form submit logic works unchanged — the editor
 * just keeps it synced with the rendered HTML.
 *
 * Pasted HTML is preserved (the contenteditable surface renders it).
 * Toolbar buttons use document.execCommand (deprecated but universally
 * supported in current browsers — fine for an admin tool).
 */

const TOOLBAR_HTML = `
<div class="re-toolbar" role="toolbar" aria-label="Formatting">
  <button type="button" class="re-btn" data-cmd="undo" title="Undo (Ctrl+Z)">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M3 13a9 9 0 1 0 3-7"/></svg>
  </button>
  <button type="button" class="re-btn" data-cmd="redo" title="Redo (Ctrl+Y)">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7v6h-6"/><path d="M21 13a9 9 0 1 1-3-7"/></svg>
  </button>
  <span class="re-sep"></span>

  <select class="re-select" data-cmd="formatBlock" title="Heading / paragraph">
    <option value="P">Paragraph</option>
    <option value="H2">Heading 2</option>
    <option value="H3">Heading 3</option>
    <option value="H4">Heading 4</option>
    <option value="BLOCKQUOTE">Quote</option>
    <option value="PRE">Code block</option>
  </select>
  <span class="re-sep"></span>

  <button type="button" class="re-btn" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
  <button type="button" class="re-btn" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
  <button type="button" class="re-btn" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
  <button type="button" class="re-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
  <span class="re-sep"></span>

  <label class="re-color" title="Text color">
    <span class="re-color-swatch" id="re-color-display">A</span>
    <input type="color" data-cmd="foreColor" value="#1A1814">
  </label>
  <label class="re-color" title="Highlight color">
    <span class="re-color-swatch re-color-hl" id="re-hl-display">H</span>
    <input type="color" data-cmd="hiliteColor" value="#fff39a">
  </label>
  <span class="re-sep"></span>

  <button type="button" class="re-btn" data-cmd="insertUnorderedList" title="Bulleted list">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>
  </button>
  <button type="button" class="re-btn" data-cmd="insertOrderedList" title="Numbered list">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
  </button>
  <button type="button" class="re-btn" data-cmd="outdent" title="Decrease indent">&laquo;</button>
  <button type="button" class="re-btn" data-cmd="indent" title="Increase indent">&raquo;</button>
  <span class="re-sep"></span>

  <button type="button" class="re-btn" data-cmd="createLink" title="Insert link">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>
  </button>
  <button type="button" class="re-btn" data-cmd="unlink" title="Remove link">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.84 12.25l1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M5.17 11.75l-1.72 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
  </button>
  <button type="button" class="re-btn" data-cmd="formatBlock-PRE" title="Inline code"><code>&lt;/&gt;</code></button>
  <span class="re-sep"></span>

  <button type="button" class="re-btn" data-cmd="removeFormat" title="Clear formatting">T<sub>x</sub></button>
  <span class="re-spacer"></span>
  <button type="button" class="re-btn re-btn-toggle" data-cmd="toggle-html" title="View / edit raw HTML">&lt;/&gt; HTML</button>
</div>
`;

const STYLE_HTML = `
<style data-re-styles>
  .re-wrap {
    border:1px solid var(--border, #d8d2c4); border-radius:8px;
    background:var(--surface, #fff);
    overflow:hidden;
    display:flex; flex-direction:column;
  }
  .re-toolbar {
    display:flex; flex-wrap:wrap; align-items:center; gap:2px;
    padding:6px 8px;
    background:var(--paper-soft, #f7f3ec);
    border-bottom:1px solid var(--border, #d8d2c4);
  }
  .re-btn {
    min-width:28px; height:28px;
    display:inline-flex; align-items:center; justify-content:center;
    padding:0 6px; border:none; background:transparent;
    color:var(--text, #1A1814); cursor:pointer;
    border-radius:5px; font-family:'Inter', sans-serif; font-size:0.85rem;
    transition:background .12s, color .12s;
  }
  .re-btn:hover { background:rgba(184,83,28,0.10); color:var(--accent, #b8531c); }
  .re-btn.is-active { background:rgba(184,83,28,0.16); color:var(--accent, #b8531c); }
  .re-btn b, .re-btn i, .re-btn u, .re-btn s { font-style:inherit; }
  .re-btn i { font-style:italic; font-family:Georgia, serif; }
  .re-sep { width:1px; height:18px; background:var(--border, #d8d2c4); margin:0 4px; flex-shrink:0; }
  .re-spacer { flex:1; }
  .re-select {
    height:28px; padding:0 6px; border:1px solid var(--border, #d8d2c4); border-radius:5px;
    background:var(--surface, #fff); color:var(--text, #1A1814);
    font-family:'Inter', sans-serif; font-size:0.82rem; cursor:pointer;
  }
  .re-color {
    position:relative; display:inline-flex; align-items:center;
    width:28px; height:28px; border-radius:5px; cursor:pointer;
    overflow:hidden;
  }
  .re-color:hover { background:rgba(184,83,28,0.10); }
  .re-color-swatch {
    display:inline-flex; align-items:center; justify-content:center;
    width:100%; height:100%;
    font-family:'Inter', sans-serif; font-weight:700; font-size:0.85rem;
    color:var(--text);
    border-bottom:3px solid #1A1814;
    box-sizing:border-box; padding-bottom:2px;
    pointer-events:none;
  }
  .re-color-swatch.re-color-hl { background:#fff39a; border-bottom-color:#fff39a; }
  .re-color input[type="color"] {
    position:absolute; inset:0; opacity:0; cursor:pointer; padding:0; border:none;
  }
  .re-body {
    min-height:280px; max-height:560px; overflow-y:auto;
    padding:14px 18px;
    font-family:'Source Serif Pro', Georgia, serif;
    font-size:15px; line-height:1.7; color:var(--text, #1A1814);
    outline:none;
  }
  .re-body[contenteditable="true"]:empty::before {
    content:attr(data-placeholder); color:var(--text-muted, #8c8473); pointer-events:none;
  }
  .re-body h1, .re-body h2 { font-family:'Inter', sans-serif; font-size:1.25rem; font-weight:700; color:var(--text); margin:18px 0 10px; padding-bottom:4px; border-bottom:1px solid var(--border); }
  .re-body h3 { font-family:'Inter', sans-serif; font-size:1.05rem; font-weight:700; margin:16px 0 8px; }
  .re-body h4 { font-family:'Inter', sans-serif; font-size:0.95rem; font-weight:700; margin:14px 0 6px; }
  .re-body p { margin:0 0 12px; }
  .re-body ul, .re-body ol { margin:8px 0 14px 24px; padding:0; }
  .re-body li { margin:4px 0; }
  .re-body a { color:var(--accent, #b8531c); }
  .re-body blockquote { margin:14px 0; padding:8px 14px; background:rgba(184,83,28,0.05); border-left:3px solid var(--accent); font-style:italic; }
  .re-body pre { background:#1a1814; color:#f7f3ec; padding:12px 14px; border-radius:6px; overflow-x:auto; font-family:'IBM Plex Mono', monospace; font-size:0.86em; margin:12px 0; }
  .re-body code { background:var(--paper-soft); padding:1px 5px; border-radius:3px; font-family:'IBM Plex Mono', monospace; font-size:0.86em; border:1px solid var(--border); }
  .re-body table { border-collapse:collapse; margin:12px 0; }
  .re-body td, .re-body th { padding:6px 10px; border:1px solid var(--border); }
  .re-source {
    width:100%; min-height:280px; max-height:560px;
    border:none; outline:none; resize:vertical;
    padding:14px 18px; box-sizing:border-box;
    font-family:'IBM Plex Mono', monospace; font-size:13px; line-height:1.55;
    background:var(--paper-soft, #f7f3ec); color:var(--text);
  }
  .re-statusbar {
    display:flex; justify-content:space-between; align-items:center;
    padding:4px 10px;
    background:var(--paper-soft, #f7f3ec);
    border-top:1px solid var(--border, #d8d2c4);
    font-family:'IBM Plex Mono', monospace; font-size:0.7rem; color:var(--text-muted, #8c8473);
  }
</style>
`;

let stylesInjected = false;
function injectStyles() {
  if (stylesInjected || document.querySelector('style[data-re-styles]')) return;
  document.head.insertAdjacentHTML('beforeend', STYLE_HTML);
  stylesInjected = true;
}

export function mountRichEditor(textarea, opts = {}) {
  if (!textarea || textarea.dataset.reMounted === '1') return null;
  textarea.dataset.reMounted = '1';
  injectStyles();

  const initialHtml  = textarea.value || '';
  const placeholder  = opts.placeholder || 'Start writing\u2026';

  // Build the editor surface
  const wrap = document.createElement('div');
  wrap.className = 're-wrap';
  wrap.innerHTML = TOOLBAR_HTML + `
    <div class="re-body" id="re-body-${Math.random().toString(36).slice(2,8)}"
         contenteditable="true" data-placeholder="${placeholder}"></div>
    <textarea class="re-source" style="display:none;"></textarea>
    <div class="re-statusbar">
      <span class="re-mode">Visual</span>
      <span class="re-char-count">0 chars</span>
    </div>
  `;

  const body   = wrap.querySelector('.re-body');
  const source = wrap.querySelector('.re-source');
  const mode   = wrap.querySelector('.re-mode');
  const counter = wrap.querySelector('.re-char-count');

  // Set initial content. innerHTML preserves the formatting.
  body.innerHTML = initialHtml;
  source.value   = initialHtml;

  // Hide the original textarea (don't remove it — form submit needs its name+value).
  // Also strip `required` because a display:none element with `required` blocks
  // form submission with a "not focusable" browser error. We rely on backend
  // validation (which already requires non-empty content) for the same effect.
  if (textarea.hasAttribute('required')) {
    textarea.dataset.reRequired = '1';
    textarea.removeAttribute('required');
  }
  textarea.style.display = 'none';
  textarea.insertAdjacentElement('beforebegin', wrap);

  // Keep the hidden textarea in sync with the editor body
  function syncToTextarea() {
    const html = body.innerHTML.trim();
    textarea.value = html;
    counter.textContent = body.innerText.length + ' chars';
  }
  syncToTextarea();
  body.addEventListener('input', syncToTextarea);

  // Toolbar wiring
  wrap.querySelectorAll('[data-cmd]').forEach(btn => {
    const cmd = btn.dataset.cmd;

    if (cmd === 'toggle-html') {
      btn.addEventListener('click', () => {
        const showSource = source.style.display === 'none';
        if (showSource) {
          source.value = body.innerHTML;
          source.style.display = 'block';
          body.style.display = 'none';
          btn.classList.add('is-active');
          mode.textContent = 'HTML';
        } else {
          body.innerHTML = source.value;
          source.style.display = 'none';
          body.style.display = 'block';
          btn.classList.remove('is-active');
          mode.textContent = 'Visual';
          syncToTextarea();
        }
      });
      return;
    }

    // Inline-code shortcut: wrap selection in <code>
    if (cmd === 'formatBlock-PRE') {
      btn.addEventListener('click', () => {
        body.focus();
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount || sel.isCollapsed) return;
        const range = sel.getRangeAt(0);
        const code = document.createElement('code');
        try { range.surroundContents(code); }
        catch { /* selection crosses element boundaries — bail silently */ }
        syncToTextarea();
      });
      return;
    }

    if (btn.tagName === 'SELECT') {
      btn.addEventListener('change', () => {
        body.focus();
        document.execCommand('formatBlock', false, btn.value);
        syncToTextarea();
      });
      return;
    }

    if (btn.tagName === 'INPUT' && btn.type === 'color') {
      btn.addEventListener('input', () => {
        body.focus();
        document.execCommand(cmd, false, btn.value);
        if (cmd === 'foreColor') {
          const sw = document.getElementById('re-color-display');
          if (sw) sw.style.borderBottomColor = btn.value;
        }
        if (cmd === 'hiliteColor') {
          const sw = document.getElementById('re-hl-display');
          if (sw) { sw.style.background = btn.value; sw.style.borderBottomColor = btn.value; }
        }
        syncToTextarea();
      });
      return;
    }

    if (cmd === 'createLink') {
      btn.addEventListener('click', () => {
        const url = prompt('Enter the URL:', 'https://');
        if (!url) return;
        body.focus();
        document.execCommand('createLink', false, url);
        syncToTextarea();
      });
      return;
    }

    // Generic execCommand button
    btn.addEventListener('click', () => {
      body.focus();
      document.execCommand(cmd, false, null);
      syncToTextarea();
    });
  });

  // Highlight active buttons as the cursor moves
  function refreshActiveStates() {
    wrap.querySelectorAll('.re-btn[data-cmd]').forEach(btn => {
      const cmd = btn.dataset.cmd;
      if (['bold','italic','underline','strikeThrough'].includes(cmd)) {
        try { btn.classList.toggle('is-active', document.queryCommandState(cmd)); }
        catch {}
      }
    });
    // Block format select
    try {
      const block = (document.queryCommandValue('formatBlock') || '').toUpperCase();
      const sel = wrap.querySelector('select[data-cmd="formatBlock"]');
      if (sel) {
        const matchable = block.replace(/[<>]/g, '');
        const valid = ['H2','H3','H4','BLOCKQUOTE','PRE','P'];
        sel.value = valid.includes(matchable) ? matchable : 'P';
      }
    } catch {}
  }
  body.addEventListener('keyup', refreshActiveStates);
  body.addEventListener('mouseup', refreshActiveStates);

  return { wrap, body, syncToTextarea };
}
