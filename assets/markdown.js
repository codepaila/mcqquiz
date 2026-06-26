/* assets/markdown.js
 * Lightweight Markdown toolbar + renderer for plain <textarea> fields.
 *
 * Two exports:
 *   mountMarkdownToolbar(textarea) — inserts a button row above the
 *     textarea that wraps/prefixes the current selection with Markdown
 *     syntax (bold, italic, link, headings, lists, quote). The textarea
 *     keeps storing raw Markdown text — no contenteditable, no hidden
 *     sync field, so existing FormData / api.js submit logic is untouched.
 *
 *   renderMarkdown(text) — converts a raw Markdown string into safe HTML
 *     for display. Escapes all HTML first, so user input can never inject
 *     tags; only the specific Markdown patterns below are turned into tags.
 *
 * Supported syntax (intentionally a small, predictable subset):
 *   **bold**           -> <strong>
 *   *italic* / _italic_ -> <em>
 *   # / ## / ### Heading -> <h1>/<h2>/<h3>   (must start the line)
 *   - item / * item     -> <ul><li>          (consecutive lines grouped)
 *   1. item             -> <ol><li>          (consecutive lines grouped)
 *   > quote             -> <blockquote>
 *   [text](url)         -> <a href="url" target="_blank" rel="noopener noreferrer">
 *   blank line           -> paragraph break
 *   single newline       -> <br>
 */

const TOOLBAR_HTML = `
<div class="md-toolbar" role="toolbar" aria-label="Markdown formatting">
  <button type="button" class="md-btn" data-md="bold" title="Bold (**text**)"><b>B</b></button>
  <button type="button" class="md-btn" data-md="italic" title="Italic (*text*)"><i>I</i></button>
  <span class="md-sep"></span>
  <button type="button" class="md-btn" data-md="h2" title="Heading">H2</button>
  <button type="button" class="md-btn" data-md="h3" title="Subheading">H3</button>
  <span class="md-sep"></span>
  <button type="button" class="md-btn" data-md="ul" title="Bulleted list">&bull; List</button>
  <button type="button" class="md-btn" data-md="ol" title="Numbered list">1. List</button>
  <button type="button" class="md-btn" data-md="quote" title="Quote">&ldquo;&rdquo;</button>
  <span class="md-sep"></span>
  <button type="button" class="md-btn" data-md="link" title="Insert link">&#128279;</button>
  <span class="md-spacer"></span>
  <button type="button" class="md-btn md-btn-toggle" data-md="preview" title="Toggle preview">Preview</button>
</div>
`;

const STYLE_HTML = `
<style data-md-styles>
  .md-field-wrap { border:1px solid var(--paper-edge, #d8d2c4); border-radius:var(--radius, 8px); overflow:hidden; }
  .md-toolbar {
    display:flex; flex-wrap:wrap; align-items:center; gap:2px;
    padding:6px 8px;
    background:var(--paper-soft, #f7f3ec);
    border-bottom:1px solid var(--paper-edge, #d8d2c4);
  }
  .md-btn {
    min-width:30px; height:28px;
    display:inline-flex; align-items:center; justify-content:center;
    padding:0 7px; border:none; background:transparent;
    color:var(--ink, #1A1814); cursor:pointer;
    border-radius:5px; font-family:inherit; font-size:0.78rem; font-weight:600;
    transition:background .12s, color .12s;
  }
  .md-btn:hover { background:rgba(184,83,28,0.10); color:var(--accent, #b8531c); }
  .md-btn.is-active { background:rgba(184,83,28,0.16); color:var(--accent, #b8531c); }
  .md-sep { width:1px; height:18px; background:var(--paper-edge, #d8d2c4); margin:0 4px; flex-shrink:0; }
  .md-spacer { flex:1; }
  .md-field-wrap textarea {
    display:block; width:100%; border:none; border-radius:0;
    box-sizing:border-box;
  }
  .md-field-wrap textarea:focus { outline:none; box-shadow:none; }
  .md-preview {
    display:none; padding:14px 16px; min-height:120px;
    font-size:0.9rem; line-height:1.65; color:var(--ink, #1A1814);
    background:var(--paper, #fff);
  }
  .md-preview h1, .md-preview h2, .md-preview h3 { margin:0.6em 0 0.3em; font-weight:700; }
  .md-preview p { margin:0 0 0.7em; }
  .md-preview ul, .md-preview ol { margin:0.3em 0 0.7em 1.4em; padding:0; }
  .md-preview li { margin:0.2em 0; }
  .md-preview blockquote {
    margin:0.5em 0; padding:6px 12px; border-left:3px solid var(--accent, #b8531c);
    background:rgba(184,83,28,0.05); font-style:italic;
  }
  .md-preview a { color:var(--accent, #b8531c); }
  .md-hint {
    font-size:0.72rem; color:var(--ink-muted, #8c8473); padding:5px 10px 0;
  }
</style>
`;

let stylesInjected = false;
function injectStyles() {
  if (stylesInjected || document.querySelector('style[data-md-styles]')) return;
  document.head.insertAdjacentHTML('beforeend', STYLE_HTML);
  stylesInjected = true;
}

/**
 * Mount a Markdown formatting toolbar above an existing <textarea>.
 * The textarea keeps its name/value — form submission is unaffected.
 * @param {HTMLTextAreaElement} textarea
 * @param {object} opts  { hint: boolean } — show a small "Markdown supported" hint line
 * @returns {{ wrap: HTMLElement }}
 */
export function mountMarkdownToolbar(textarea, opts = {}) {
  if (!textarea || textarea.dataset.mdMounted === '1') return null;
  textarea.dataset.mdMounted = '1';
  injectStyles();

  const wrap = document.createElement('div');
  wrap.className = 'md-field-wrap';

  const toolbar = document.createElement('div');
  toolbar.innerHTML = TOOLBAR_HTML;
  const toolbarEl = toolbar.firstElementChild;

  const preview = document.createElement('div');
  preview.className = 'md-preview';

  textarea.insertAdjacentElement('beforebegin', wrap);
  wrap.appendChild(toolbarEl);
  wrap.appendChild(textarea);
  wrap.appendChild(preview);

  if (opts.hint !== false) {
    const hint = document.createElement('div');
    hint.className = 'md-hint';
    hint.textContent = 'Markdown supported — **bold**, *italic*, # heading, - list, [link](url)';
    wrap.appendChild(hint);
  }

  function wrapSelection(before, after = before) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const val = textarea.value;
    const selected = val.slice(start, end);
    const replacement = before + selected + after;
    textarea.value = val.slice(0, start) + replacement + val.slice(end);
    const cursor = selected ? start + replacement.length : start + before.length;
    textarea.focus();
    textarea.setSelectionRange(cursor, cursor);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function prefixLines(prefix, numbered = false) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const val = textarea.value;
    const lineStart = val.lastIndexOf('\n', start - 1) + 1;
    let lineEnd = val.indexOf('\n', end);
    if (lineEnd === -1) lineEnd = val.length;
    const block = val.slice(lineStart, lineEnd);
    const lines = block.split('\n');
    const newBlock = lines.map((line, i) => {
      const p = numbered ? `${i + 1}. ` : prefix;
      return line.startsWith(p) ? line : p + line;
    }).join('\n');
    textarea.value = val.slice(0, lineStart) + newBlock + val.slice(lineEnd);
    textarea.focus();
    textarea.setSelectionRange(lineStart, lineStart + newBlock.length);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  toolbarEl.querySelectorAll('[data-md]').forEach(btn => {
    const cmd = btn.dataset.md;
    btn.addEventListener('click', () => {
      switch (cmd) {
        case 'bold':   wrapSelection('**'); break;
        case 'italic': wrapSelection('*'); break;
        case 'h2':     prefixLines('## '); break;
        case 'h3':     prefixLines('### '); break;
        case 'ul':     prefixLines('- '); break;
        case 'ol':     prefixLines('', true); break;
        case 'quote':  prefixLines('> '); break;
        case 'link': {
          const url = prompt('Enter the URL:', 'https://');
          if (!url) return;
          const start = textarea.selectionStart;
          const end = textarea.selectionEnd;
          const selected = textarea.value.slice(start, end) || 'link text';
          wrapSelectionCustom(`[${selected}](${url})`, start, end);
          break;
        }
        case 'preview': {
          const showing = preview.style.display === 'block';
          if (showing) {
            preview.style.display = 'none';
            textarea.style.display = 'block';
            btn.classList.remove('is-active');
            btn.textContent = 'Preview';
            if (textarea.dataset.mdRequired === '1') textarea.setAttribute('required', '');
          } else {
            preview.innerHTML = renderMarkdown(textarea.value) || '<p style="color:var(--ink-muted,#8c8473);">Nothing to preview yet.</p>';
            preview.style.display = 'block';
            textarea.style.display = 'none';
            btn.classList.add('is-active');
            btn.textContent = 'Edit';
            if (textarea.hasAttribute('required')) {
              textarea.dataset.mdRequired = '1';
              textarea.removeAttribute('required');
            }
          }
          break;
        }
      }
    });
  });

  function wrapSelectionCustom(replacement, start, end) {
    const val = textarea.value;
    textarea.value = val.slice(0, start) + replacement + val.slice(end);
    const cursor = start + replacement.length;
    textarea.focus();
    textarea.setSelectionRange(cursor, cursor);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  return { wrap };
}

/* --- Renderer --------------------------------------------------------- */

function escapeHtmlLocal(s) {
  if (s == null) return '';
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/**
 * Render a raw Markdown string to safe HTML.
 * All input is HTML-escaped first, so only the patterns below ever
 * become real tags — arbitrary HTML in the source can't get through.
 * @param {string} src
 * @returns {string} HTML
 */
export function renderMarkdown(src) {
  if (!src) return '';
  const escaped = escapeHtmlLocal(src).replace(/\r\n/g, '\n');
  const lines = escaped.split('\n');

  const htmlBlocks = [];
  let i = 0;

  function inline(text) {
    return text
      // links: [text](url) — only allow http(s)/mailto for safety
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g,
        '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
      // bold
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      // italic (single * or _, not part of already-consumed **)
      .replace(/(?:^|[^*])\*([^*\n]+)\*(?!\*)/g, (m, p1) => m[0] === '*' ? m : `${p1 === m ? '' : m[0]}<em>${p1}</em>`)
      .replace(/_([^_\n]+)_/g, '<em>$1</em>');
  }

  while (i < lines.length) {
    const line = lines[i];

    if (!line.trim()) { i++; continue; }

    // Headings
    const h = line.match(/^(#{1,3})\s+(.*)$/);
    if (h) {
      const level = h[1].length;
      htmlBlocks.push(`<h${level}>${inline(h[2].trim())}</h${level}>`);
      i++; continue;
    }

    // Blockquote (consecutive > lines)
    if (/^&gt;\s?/.test(line)) {
      const buf = [];
      while (i < lines.length && /^&gt;\s?/.test(lines[i])) {
        buf.push(inline(lines[i].replace(/^&gt;\s?/, '')));
        i++;
      }
      htmlBlocks.push(`<blockquote>${buf.join('<br>')}</blockquote>`);
      continue;
    }

    // Unordered list (consecutive - or * lines)
    if (/^[-*]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^[-*]\s+/.test(lines[i])) {
        items.push(`<li>${inline(lines[i].replace(/^[-*]\s+/, ''))}</li>`);
        i++;
      }
      htmlBlocks.push(`<ul>${items.join('')}</ul>`);
      continue;
    }

    // Ordered list (consecutive "N. " lines)
    if (/^\d+\.\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^\d+\.\s+/.test(lines[i])) {
        items.push(`<li>${inline(lines[i].replace(/^\d+\.\s+/, ''))}</li>`);
        i++;
      }
      htmlBlocks.push(`<ol>${items.join('')}</ol>`);
      continue;
    }

    // Paragraph (consecutive plain lines, single \n -> <br>)
    const buf = [];
    while (i < lines.length && lines[i].trim() &&
           !/^(#{1,3})\s+/.test(lines[i]) &&
           !/^&gt;\s?/.test(lines[i]) &&
           !/^[-*]\s+/.test(lines[i]) &&
           !/^\d+\.\s+/.test(lines[i])) {
      buf.push(inline(lines[i]));
      i++;
    }
    htmlBlocks.push(`<p>${buf.join('<br>')}</p>`);
  }

  return htmlBlocks.join('\n');
}
