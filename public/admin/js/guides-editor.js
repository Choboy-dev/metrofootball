import { admFetch, esc } from './admin-shared.js?v=20260902adm4';
import { animate } from '/js/motion.js?v=20260901perf';

const params = new URLSearchParams(location.search);
const postId = parseInt(params.get('id') || '0', 10);

const state = {
  id: postId || 0,
  title: '',
  slug: '',
  description: '',
  category: 'Basics',
  schema_type: 'Article',
  featured_image: '',
  status: 'draft',
  blocks: [defaultBlock('paragraph')],
  meta: { schema_types: [], categories: [] },
  selectedIndex: -1,
  saving: false,
  inlineImageId: null,
  inserterIndex: 0,
  aiBusy: false,
  aiAttachments: [],
};

let activeEditable = null;
let activeBlockIndex = -1;
let savedLinkRange = null;
let syncingImageSidebar = false;
let imageSidebarBound = false;

const INSERTER_OPTIONS = [
  { type: 'paragraph', label: 'Paragraph', short: 'P' },
  { type: 'heading', label: 'Heading', short: 'H', data: { level: 2 } },
  { type: 'quote', label: 'Quote', short: 'Q' },
  { type: 'table', label: 'Table', short: 'Tbl', data: { rows: 3, cols: 3, header: true } },
  { type: 'image', label: 'Image', short: 'Img' },
  { type: 'youtube', label: 'YouTube video', short: 'YT' },
  { type: 'callout', label: 'Callout box', short: '!' },
  { type: 'separator', label: 'Separator', short: '—' },
];

function uid() {
  return 'b' + Math.random().toString(36).slice(2, 10);
}

const GUIDE_BLOCK_CLIP_PREFIX = 'metrofootball-guide-block:v1:';
const GUIDE_BLOCK_LS_KEY = 'mf_guide_block_clipboard';

function cloneBlockForPaste(block) {
  const copy = JSON.parse(JSON.stringify(block));
  copy.id = uid();
  if (copy.type === 'table') normalizeTableBlock(copy);
  return copy;
}

function serializeGuideBlockClipboard(block) {
  return GUIDE_BLOCK_CLIP_PREFIX + JSON.stringify(block);
}

function parseGuideBlockClipboard(text) {
  const raw = String(text || '').replace(/^\uFEFF/, '').trim();
  if (!raw.startsWith(GUIDE_BLOCK_CLIP_PREFIX)) return null;
  try {
    const data = JSON.parse(raw.slice(GUIDE_BLOCK_CLIP_PREFIX.length));
    if (!data || typeof data !== 'object' || !data.type) return null;
    return data;
  } catch {
    return null;
  }
}

function findGuideBlockPayload(text) {
  const s = String(text || '').replace(/^\uFEFF/, '');
  if (!s) return null;
  const idx = s.indexOf(GUIDE_BLOCK_CLIP_PREFIX);
  if (idx < 0) return null;
  // Take from prefix to end; ignore any leading junk from rich paste.
  return parseGuideBlockClipboard(s.slice(idx).trim());
}

function clearStoredGuideBlockClipboard() {
  try {
    localStorage.removeItem(GUIDE_BLOCK_LS_KEY);
  } catch {
    // ignore
  }
}

function clipboardHasImagePayload(e) {
  if (getClipboardImageFile(e)) return true;
  const items = e?.clipboardData?.items;
  if (items) {
    for (const item of items) {
      if (String(item.type || '').startsWith('image/')) return true;
    }
  }
  const files = e?.clipboardData?.files;
  if (files?.length) {
    return [...files].some((f) => String(f.type || '').startsWith('image/'));
  }
  return false;
}

function extractGuideBlockFromPasteEvent(e) {
  // Image / file pastes must never be replaced by a previously copied block.
  if (clipboardHasImagePayload(e)) return null;

  const candidates = [];
  try {
    candidates.push(e?.clipboardData?.getData('text/plain') || '');
  } catch {
    // ignore
  }
  try {
    const html = e?.clipboardData?.getData('text/html') || '';
    if (html) {
      const tmp = document.createElement('div');
      tmp.innerHTML = html;
      candidates.push(tmp.textContent || '');
    }
  } catch {
    // ignore
  }

  for (const c of candidates) {
    const found = findGuideBlockPayload(c);
    if (found) return found;
  }

  // localStorage is only a recovery aid when the live clipboard still contains our
  // block marker but failed to parse. Never use it for empty clipboards (images).
  const clipboardLooksLikeBlock = candidates.some((c) => String(c || '').includes(GUIDE_BLOCK_CLIP_PREFIX));
  if (clipboardLooksLikeBlock) {
    try {
      return findGuideBlockPayload(localStorage.getItem(GUIDE_BLOCK_LS_KEY) || '');
    } catch {
      // ignore
    }
  }
  return null;
}

async function copyGuideBlock(index) {
  const block = state.blocks[index];
  if (!block) return;
  const payload = serializeGuideBlockClipboard(block);
  let wrote = false;
  try {
    await navigator.clipboard.writeText(payload);
    wrote = true;
  } catch {
    // Fallback below for browsers that block clipboard write.
  }
  try {
    localStorage.setItem(GUIDE_BLOCK_LS_KEY, payload);
    wrote = true;
  } catch {
    // ignore storage failures
  }
  if (!wrote) {
    toast('Could not copy block');
    return;
  }
  toast('Block copied. Press Ctrl+V / ⌘V to paste.');
}

function isEmptyReplaceableBlock(block) {
  if (!block) return false;
  if (block.type === 'paragraph' || block.type === 'quote' || block.type === 'callout') {
    const text = String(block.text || '')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\u00a0/g, ' ')
      .trim();
    return text === '';
  }
  if (block.type === 'heading') {
    return String(block.text || '').trim() === '';
  }
  return false;
}

function insertCopiedGuideBlock(rawBlock, targetIndex) {
  const block = cloneBlockForPaste(rawBlock);
  let at;
  const idx = Number.isFinite(targetIndex) ? targetIndex : state.selectedIndex;
  if (idx >= 0 && idx < state.blocks.length && isEmptyReplaceableBlock(state.blocks[idx])) {
    state.blocks[idx] = block;
    at = idx;
  } else {
    const after = idx < 0 ? state.blocks.length - 1 : idx;
    at = Math.max(0, Math.min(state.blocks.length, after + 1));
    state.blocks.splice(at, 0, block);
  }
  state.selectedIndex = at;
  renderBlocks();
  if (block.type === 'table') {
    requestAnimationFrame(() => focusTableCell(at, 0, 0));
  } else if (block.type === 'paragraph' || block.type === 'heading' || block.type === 'quote' || block.type === 'callout') {
    requestAnimationFrame(() => focusBlockEditable(at));
  }
  toast('Block pasted');
  return true;
}

function tryPasteGuideBlockFromEvent(e, targetIndex = state.selectedIndex) {
  const raw = extractGuideBlockFromPasteEvent(e);
  if (!raw) return false;
  try {
    return insertCopiedGuideBlock(raw, targetIndex);
  } catch (err) {
    console.error(err);
    toast('Could not paste block');
    // Still treat as handled so the raw code is not dumped into a paragraph.
    return true;
  }
}

function tryPasteGuideBlockFromText(text, targetIndex = state.selectedIndex) {
  const raw = findGuideBlockPayload(text);
  if (!raw) return false;
  try {
    return insertCopiedGuideBlock(raw, targetIndex);
  } catch (err) {
    console.error(err);
    toast('Could not paste block');
    return true;
  }
}

function salvageGuideBlockFromTextValue(text, blockIndex) {
  const raw = findGuideBlockPayload(text);
  if (!raw || blockIndex < 0 || blockIndex >= state.blocks.length) return false;
  try {
    state.blocks[blockIndex] = cloneBlockForPaste(raw);
    state.selectedIndex = blockIndex;
    renderBlocks();
    if (state.blocks[blockIndex]?.type === 'table') {
      requestAnimationFrame(() => focusTableCell(blockIndex, 0, 0));
    }
    toast('Block pasted');
    return true;
  } catch (err) {
    console.error(err);
    return false;
  }
}

function salvageGuideBlocksInState() {
  let changed = false;
  state.blocks = state.blocks.map((block) => {
    if (!block || (block.type !== 'paragraph' && block.type !== 'quote' && block.type !== 'callout' && block.type !== 'heading')) {
      return block;
    }
    const text = String(block.text || '').replace(/<[^>]+>/g, ' ');
    if (!text.includes(GUIDE_BLOCK_CLIP_PREFIX)) return block;
    const raw = findGuideBlockPayload(text);
    if (!raw) return block;
    changed = true;
    return cloneBlockForPaste(raw);
  });
  return changed;
}

function defaultBlock(type, extra = {}) {
  const id = uid();
  switch (type) {
    case 'heading':
      return { id, type, level: extra.level || 2, text: '' };
    case 'quote':
      return { id, type, text: '', cite: '' };
    case 'image':
      return { id, type, url: '', alt: '', caption: '' };
    case 'youtube':
      return { id, type, url: '', videoId: '', caption: '' };
    case 'list':
      return { id, type, style: extra.style || 'ul', items: extra.items || [''] };
    case 'callout':
      return { id, type, title: '', text: '' };
    case 'separator':
      return { id, type };
    case 'table': {
      const cols = Math.max(1, Math.min(12, Number(extra.cols) || 3));
      const rows = Math.max(1, Math.min(30, Number(extra.rows) || 3));
      const grid = Array.from({ length: rows }, () => Array.from({ length: cols }, () => ''));
      return {
        id,
        type: 'table',
        header: extra.header !== false,
        stickyCols: Math.max(0, Math.min(6, Number(extra.stickyCols) || 0)),
        colWidths: Array.from({ length: cols }, () => 0),
        rowHeights: Array.from({ length: rows }, () => 0),
        rows: grid,
      };
    }
    default:
      return { id, type: 'paragraph', text: '' };
  }
}

function normalizeTableBlock(block) {
  if (!block || block.type !== 'table') return block;
  let rows = Array.isArray(block.rows) ? block.rows : [];
  if (!rows.length && block.html) {
    rows = tableRowsFromHtml(block.html);
  }
  if (!rows.length) {
    rows = Array.from({ length: 3 }, () => ['', '', '']);
  }
  const colCount = Math.max(1, ...rows.map((r) => (Array.isArray(r) ? r.length : 0)));
  block.rows = rows.map((row) => {
    const cells = Array.isArray(row) ? row.map((c) => String(c ?? '')) : [];
    while (cells.length < colCount) cells.push('');
    return cells.slice(0, Math.min(12, colCount));
  });
  block.header = block.header !== false;
  block.stickyCols = Math.max(0, Math.min(6, Number(block.stickyCols) || 0));
  ensureTableSizeArrays(block);
  return block;
}

const TABLE_COL_DEFAULT = 104;
const TABLE_COL_MIN = 48;
const TABLE_COL_MAX = 640;
const TABLE_ROW_MIN = 28;
const TABLE_ROW_MAX = 480;
const TABLE_EDGE = 6;

function ensureTableSizeArrays(block) {
  const cols = tableColCount(block);
  const rows = Array.isArray(block.rows) ? block.rows.length : 0;
  const widths = Array.isArray(block.colWidths)
    ? block.colWidths.map((n) => Math.max(0, Math.min(TABLE_COL_MAX, Number(n) || 0)))
    : [];
  const heights = Array.isArray(block.rowHeights)
    ? block.rowHeights.map((n) => Math.max(0, Math.min(TABLE_ROW_MAX, Number(n) || 0)))
    : [];
  while (widths.length < cols) widths.push(0);
  while (heights.length < rows) heights.push(0);
  block.colWidths = widths.slice(0, cols);
  block.rowHeights = heights.slice(0, rows);
  return block;
}

function tableColWidthPx(block, c) {
  const w = Number(block?.colWidths?.[c]) || 0;
  return w > 0 ? w : TABLE_COL_DEFAULT;
}

function tableRowHeightPx(block, r) {
  const h = Number(block?.rowHeights?.[r]) || 0;
  return h > 0 ? h : 0;
}

function stickyLeftPx(block, c) {
  let left = 0;
  for (let i = 0; i < c; i += 1) left += tableColWidthPx(block, i);
  return left;
}

function clampTableCol(n) {
  return Math.max(TABLE_COL_MIN, Math.min(TABLE_COL_MAX, Math.round(Number(n) || TABLE_COL_DEFAULT)));
}

function clampTableRow(n) {
  return Math.max(TABLE_ROW_MIN, Math.min(TABLE_ROW_MAX, Math.round(Number(n) || TABLE_ROW_MIN)));
}

function tableRowsFromHtml(html) {
  const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
  const table = doc.querySelector('table');
  if (!table) return [];
  return [...table.querySelectorAll('tr')].map((tr) => (
    [...tr.children]
      .filter((c) => c.tagName === 'TD' || c.tagName === 'TH')
      .map((c) => cellInnerHtml(c).replace(/<br\s*\/?>/gi, '').trim())
  )).filter((row) => row.length);
}

function tableColCount(block) {
  const rows = block?.rows || [];
  return Math.max(1, ...rows.map((r) => (Array.isArray(r) ? r.length : 0)), 1);
}

function slugify(value) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/-+/g, '-');
}

function youtubeId(url) {
  const m = String(url || '').match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{6,})/);
  return m ? m[1] : '';
}

function estimateReadMins() {
  const text = state.blocks
    .map((b) => {
      if (b.type === 'list') return (b.items || []).join(' ');
      if (b.type === 'table') {
        return (b.rows || []).flat().join(' ');
      }
      return [b.text, b.title, b.caption, b.alt, b.cite].filter(Boolean).join(' ');
    })
    .join(' ');
  const words = (text.match(/[\p{L}']+/gu) || []).length;
  return Math.max(1, Math.ceil(words / 210) || 1);
}

function resizeTitleField() {
  const el = document.querySelector('[data-ge-title]');
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = `${el.scrollHeight}px`;
}

function syncTopbarOffset() {
  const topbar = document.querySelector('.ge-topbar');
  if (!topbar) return;
  document.documentElement.style.setProperty('--ge-topbar-height', `${topbar.offsetHeight}px`);
}

function escAttr(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;');
}

function sanitizeInlineHtml(html) {
  const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
  const root = doc.body.firstElementChild;
  if (!root) return '';

  const allowed = new Set(['STRONG', 'B', 'EM', 'I', 'A', 'BR', 'IMG', 'U', 'S', 'STRIKE', 'DEL', 'SPAN']);

  function clean(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      return node.textContent;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
      return '';
    }

    const tag = node.tagName;
    if (tag === 'BR') return '<br>';

    if (tag === 'A') {
      const href = node.getAttribute('href')
        || node.getAttribute('data-href')
        || node.getAttribute('data-saferedirecturl')
        || '';
      const inner = [...node.childNodes].map(clean).join('');
      return href ? `<a href="${escAttr(href)}">${inner}</a>` : inner;
    }

    if (tag === 'IMG') {
      const src = node.getAttribute('src') || '';
      if (!src) return '';
      const alt = node.getAttribute('alt') || '';
      return `<img src="${escAttr(src)}" alt="${escAttr(alt)}">`;
    }

    const map = {
      STRONG: 'strong',
      B: 'strong',
      EM: 'em',
      I: 'em',
      U: 'u',
      S: 's',
      STRIKE: 's',
      DEL: 's',
    };

    if (map[tag]) {
      return `<${map[tag]}>${[...node.childNodes].map(clean).join('')}</${map[tag]}>`;
    }

    if (allowed.has(tag) || tag === 'DIV') {
      return [...node.childNodes].map(clean).join('');
    }

    return [...node.childNodes].map(clean).join('');
  }

  return clean(root).trim();
}

function cellInnerHtml(cell) {
  const html = sanitizeInlineHtml(cell.innerHTML);
  return html || '<br>';
}

function serializeGuideTable(tableEl) {
  if (!tableEl || tableEl.tagName !== 'TABLE') return '';
  const rows = [...tableEl.querySelectorAll('tr')];
  if (!rows.length) return '';

  const bodyRows = [];
  let headHtml = '';

  rows.forEach((tr, index) => {
    const cells = [...tr.children].filter((c) => c.tagName === 'TD' || c.tagName === 'TH');
    if (!cells.length) return;
    const isHead = cells.every((c) => c.tagName === 'TH') || (index === 0 && cells.some((c) => c.tagName === 'TH'));
    const tag = isHead && index === 0 && !headHtml ? 'th' : 'td';
    const rowHtml = `<tr>${cells.map((c) => `<${tag}>${cellInnerHtml(c)}</${tag}>`).join('')}</tr>`;
    if (tag === 'th' && !headHtml) {
      headHtml = `<thead>${rowHtml}</thead>`;
    } else {
      bodyRows.push(rowHtml.replace(/<\/?th>/g, (m) => m.replace('th', 'td')));
    }
  });

  if (!bodyRows.length && !headHtml) return '';
  const body = bodyRows.length ? `<tbody>${bodyRows.join('')}</tbody>` : '';
  return `<table class="ge-table">${headHtml}${body}</table>`;
}

function buildGuideTableHtml(rows, cols, withHeader = true) {
  const r = Math.max(1, Math.min(12, rows | 0));
  const c = Math.max(1, Math.min(12, cols | 0));
  const empty = '<br>';
  let html = '<table class="ge-table">';
  if (withHeader) {
    html += `<thead><tr>${Array.from({ length: c }, () => `<th>${empty}</th>`).join('')}</tr></thead>`;
    const bodyRows = Math.max(1, r - 1);
    html += `<tbody>${Array.from({ length: bodyRows }, () => `<tr>${Array.from({ length: c }, () => `<td>${empty}</td>`).join('')}</tr>`).join('')}</tbody>`;
  } else {
    html += `<tbody>${Array.from({ length: r }, () => `<tr>${Array.from({ length: c }, () => `<td>${empty}</td>`).join('')}</tr>`).join('')}</tbody>`;
  }
  html += '</table>';
  return html;
}

function isStructuredHtml(html) {
  return /<(p|ul|ol|h[1-6]|blockquote|div|li|table)\b/i.test(html);
}

function looksLikePlainList(text) {
  return /(?:^|\n)\s*(?:[-*•●◦▪‣–]|\d+[.)])\s+/m.test(text);
}

function plainTextToBlocks(text) {
  const lines = text.split(/\r?\n/);
  const blocks = [];
  let bulletItems = [];
  let numberItems = [];

  const flushBullets = () => {
    if (!bulletItems.length) return;
    const block = defaultBlock('paragraph');
    block.text = `<ul>${bulletItems.map((i) => `<li>${sanitizeInlineHtml(i)}</li>`).join('')}</ul>`;
    blocks.push(block);
    bulletItems = [];
  };

  const flushNumbers = () => {
    if (!numberItems.length) return;
    const block = defaultBlock('paragraph');
    block.text = `<ol>${numberItems.map((i) => `<li>${sanitizeInlineHtml(i)}</li>`).join('')}</ol>`;
    blocks.push(block);
    numberItems = [];
  };

  for (const line of lines) {
    const bullet = line.match(/^\s*(?:[-*•●◦▪‣–])\s+(.+)$/);
    const numbered = line.match(/^\s*\d+[.)]\s+(.+)$/);
    if (bullet) {
      flushNumbers();
      bulletItems.push(bullet[1].trim());
      continue;
    }
    if (numbered) {
      flushBullets();
      numberItems.push(numbered[1].trim());
      continue;
    }
    flushBullets();
    flushNumbers();
    const trimmed = line.trim();
    if (trimmed) {
      const block = defaultBlock('paragraph');
      block.text = trimmed;
      blocks.push(block);
    }
  }

  flushBullets();
  flushNumbers();
  return blocks.length ? blocks : [defaultBlock('paragraph')];
}

function listItemsFromElement(listEl) {
  return [...listEl.children]
    .filter((el) => el.tagName === 'LI')
    .map((li) => sanitizeInlineHtml(li.innerHTML))
    .filter(Boolean);
}

function htmlToBlocks(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const blocks = [];

  const pushParagraph = (inner) => {
    const text = sanitizeInlineHtml(inner);
    if (!text) return;
    const block = defaultBlock('paragraph');
    block.text = text;
    blocks.push(block);
  };

  const processNode = (node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      const t = node.textContent.trim();
      if (t) pushParagraph(t);
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    const tag = node.tagName;
    if (/^H[1-6]$/.test(tag)) {
      const text = node.textContent.trim();
      if (text) {
        const block = defaultBlock('heading');
        block.level = parseInt(tag.slice(1), 10);
        block.text = text;
        blocks.push(block);
      }
      return;
    }

    if (tag === 'P') {
      pushParagraph(node.innerHTML);
      return;
    }

    if (tag === 'UL' || tag === 'OL') {
      const items = listItemsFromElement(node);
      if (items.length) {
        const block = defaultBlock('paragraph');
        block.text = `<${tag.toLowerCase()}>${items.map((i) => `<li>${i}</li>`).join('')}</${tag.toLowerCase()}>`;
        blocks.push(block);
      }
      return;
    }

    if (tag === 'BLOCKQUOTE') {
      const text = sanitizeInlineHtml(node.innerHTML);
      if (text) {
        const block = defaultBlock('quote');
        block.text = text;
        blocks.push(block);
      }
      return;
    }

    if (tag === 'IMG') {
      const src = node.getAttribute('src') || '';
      if (src) {
        const block = defaultBlock('image');
        block.url = src;
        block.alt = node.getAttribute('alt') || '';
        blocks.push(block);
      }
      return;
    }

    if (tag === 'HR') {
      blocks.push(defaultBlock('separator'));
      return;
    }

    if (tag === 'TABLE') {
      const rows = [...node.querySelectorAll('tr')].map((tr) => (
        [...tr.children]
          .filter((c) => c.tagName === 'TD' || c.tagName === 'TH')
          .map((c) => cellInnerHtml(c).replace(/<br\s*\/?>/gi, '').trim())
      )).filter((row) => row.length);
      if (rows.length) {
        const hasHead = Boolean(node.querySelector('thead th, tr th'));
        const block = defaultBlock('table', {
          rows: rows.length,
          cols: Math.max(...rows.map((r) => r.length)),
          header: hasHead,
        });
        block.rows = rows;
        block.header = hasHead;
        blocks.push(block);
      }
      return;
    }

    if (tag === 'LI' || tag === 'THEAD' || tag === 'TBODY' || tag === 'TFOOT' || tag === 'TR' || tag === 'TD' || tag === 'TH') {
      return;
    }

    if (['DIV', 'SECTION', 'ARTICLE', 'BODY', 'MAIN', 'SPAN'].includes(tag)) {
      [...node.childNodes].forEach(processNode);
      return;
    }

    pushParagraph(node.innerHTML);
  };

  [...doc.body.childNodes].forEach(processNode);

  if (!blocks.length) {
    const plain = doc.body.textContent.trim();
    if (plain) return plainTextToBlocks(plain);
  }

  return blocks.length ? blocks : [defaultBlock('paragraph')];
}

function listBlockToParagraph(block) {
  if (block.type !== 'list') return block;
  const tag = block.style === 'ol' ? 'ol' : 'ul';
  const items = (block.items || []).map((i) => String(i).trim()).filter(Boolean);
  const next = defaultBlock('paragraph');
  next.id = block.id || uid();
  next.text = items.length
    ? `<${tag}>${items.map((i) => `<li>${i}</li>`).join('')}</${tag}>`
    : '';
  return next;
}

function normalizeBlocks(blocks) {
  return blocks.map(listBlockToParagraph);
}

function insertHtmlAtCursor(html) {
  if (!activeEditable) return;
  ensureEditableSelection(activeEditable);
  document.execCommand('insertHTML', false, html);
  activeEditable.dispatchEvent(new Event('input', { bubbles: true }));
}

function openTableSizePicker() {
  const pop = document.querySelector('[data-ge-table-picker]');
  const btn = document.querySelector('[data-cmd="insertTable"]');
  if (!pop || !btn) {
    insertGuideTable(3, 3, true);
    return;
  }
  pop.hidden = false;
  pop.setAttribute('aria-hidden', 'false');
  positionTablePicker(btn, pop);
  highlightTableGrid(3, 3);
  document.querySelector('[data-ge-table-header]')?.focus();
}

function closeTableSizePicker() {
  const pop = document.querySelector('[data-ge-table-picker]');
  if (!pop) return;
  pop.hidden = true;
  pop.setAttribute('aria-hidden', 'true');
}

function positionTablePicker(btn, pop) {
  const rect = btn.getBoundingClientRect();
  const wrap = btn.closest('.ge-toolbar-wrap') || document.body;
  const wrapRect = wrap.getBoundingClientRect();
  pop.style.left = `${Math.max(8, rect.left - wrapRect.left)}px`;
  pop.style.top = `${rect.bottom - wrapRect.top + 8}px`;
}

function highlightTableGrid(rows, cols) {
  const grid = document.querySelector('[data-ge-table-grid]');
  const label = document.querySelector('[data-ge-table-size-label]');
  if (!grid) return;
  grid.querySelectorAll('[data-r][data-c]').forEach((cell) => {
    const r = Number(cell.getAttribute('data-r'));
    const c = Number(cell.getAttribute('data-c'));
    cell.classList.toggle('is-on', r <= rows && c <= cols);
  });
  if (label) label.textContent = `${rows} × ${cols}`;
  grid.dataset.rows = String(rows);
  grid.dataset.cols = String(cols);
}

function insertGuideTable(rows, cols, withHeader) {
  const index = state.selectedIndex >= 0 ? state.selectedIndex + 1 : state.blocks.length;
  const block = defaultBlock('table', { rows, cols, header: withHeader });
  state.blocks.splice(index, 0, block);
  state.selectedIndex = index;
  closeTableSizePicker();
  renderBlocks();
  requestAnimationFrame(() => focusTableCell(index, 0, 0));
}

function focusTableCell(blockIndex, row, col) {
  const wrap = document.querySelectorAll('.ge-block-wrap')[blockIndex];
  const cell = wrap?.querySelector(`[data-table-cell][data-r="${row}"][data-c="${col}"]`);
  if (!cell) return;
  cell.focus();
  const range = document.createRange();
  range.selectNodeContents(cell);
  range.collapse(true);
  const sel = window.getSelection();
  sel?.removeAllRanges();
  sel?.addRange(range);
}

function cellFromSelection() {
  const sel = window.getSelection();
  if (!sel || !sel.anchorNode || !activeEditable) return null;
  const node = sel.anchorNode.nodeType === Node.ELEMENT_NODE ? sel.anchorNode : sel.anchorNode.parentElement;
  if (!node || !activeEditable.contains(node)) return null;
  return node.closest('th, td');
}

function tableFromSelection() {
  return cellFromSelection()?.closest('table') || null;
}

function syncTableEditBar() {
  const bar = document.querySelector('[data-ge-table-edit]');
  if (!bar) return;
  const table = tableFromSelection();
  if (!table || !activeEditable?.contains(table)) {
    bar.hidden = true;
    return;
  }
  bar.hidden = false;
}

function insertTableRow(before) {
  const cell = cellFromSelection();
  const table = cell?.closest('table');
  const row = cell?.closest('tr');
  if (!table || !row) return;
  const cols = row.children.length;
  const tag = row.parentElement?.tagName === 'THEAD' ? 'th' : 'td';
  const neo = document.createElement('tr');
  for (let i = 0; i < cols; i++) {
    const td = document.createElement(tag === 'th' && before && row.parentElement?.tagName === 'THEAD' ? 'th' : 'td');
    td.innerHTML = '<br>';
    neo.appendChild(td);
  }
  if (before) row.parentElement.insertBefore(neo, row);
  else row.parentElement.insertBefore(neo, row.nextSibling);
  activeEditable?.dispatchEvent(new Event('input', { bubbles: true }));
}

function insertTableColumn(before) {
  const cell = cellFromSelection();
  const table = cell?.closest('table');
  const row = cell?.closest('tr');
  if (!table || !row || !cell) return;
  const index = [...row.children].indexOf(cell);
  if (index < 0) return;
  table.querySelectorAll('tr').forEach((tr) => {
    const ref = tr.children[index];
    if (!ref) return;
    const tag = ref.tagName === 'TH' ? 'th' : 'td';
    const neo = document.createElement(tag);
    neo.innerHTML = '<br>';
    if (before) tr.insertBefore(neo, ref);
    else tr.insertBefore(neo, ref.nextSibling);
  });
  activeEditable?.dispatchEvent(new Event('input', { bubbles: true }));
}

function deleteTableRow() {
  const cell = cellFromSelection();
  const table = cell?.closest('table');
  const row = cell?.closest('tr');
  if (!table || !row) return;
  const section = row.parentElement;
  row.remove();
  if (section && !section.querySelector('tr')) section.remove();
  if (!table.querySelector('tr')) {
    table.remove();
  }
  activeEditable?.dispatchEvent(new Event('input', { bubbles: true }));
  syncTableEditBar();
}

function deleteTableColumn() {
  const cell = cellFromSelection();
  const table = cell?.closest('table');
  const row = cell?.closest('tr');
  if (!table || !row || !cell) return;
  const index = [...row.children].indexOf(cell);
  if (index < 0) return;
  table.querySelectorAll('tr').forEach((tr) => {
    tr.children[index]?.remove();
  });
  if (![...table.querySelectorAll('tr')].some((tr) => tr.children.length)) {
    table.remove();
  }
  activeEditable?.dispatchEvent(new Event('input', { bubbles: true }));
  syncTableEditBar();
}

function toggleTableHeader() {
  const table = tableFromSelection();
  if (!table) return;
  const thead = table.querySelector('thead');
  if (thead) {
    const first = thead.querySelector('tr');
    if (first) {
      first.querySelectorAll('th').forEach((th) => {
        const td = document.createElement('td');
        td.innerHTML = th.innerHTML || '<br>';
        th.replaceWith(td);
      });
      let tbody = table.querySelector('tbody');
      if (!tbody) {
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
      }
      tbody.insertBefore(first, tbody.firstChild);
    }
    thead.remove();
  } else {
    const firstRow = table.querySelector('tr');
    if (!firstRow) return;
    firstRow.querySelectorAll('td').forEach((td) => {
      const th = document.createElement('th');
      th.innerHTML = td.innerHTML || '<br>';
      td.replaceWith(th);
    });
    const theadEl = document.createElement('thead');
    theadEl.appendChild(firstRow);
    table.insertBefore(theadEl, table.firstChild);
  }
  if (!table.classList.contains('ge-table')) table.classList.add('ge-table');
  activeEditable?.dispatchEvent(new Event('input', { bubbles: true }));
}

function bindTableUi() {
  const grid = document.querySelector('[data-ge-table-grid]');
  if (grid && !grid.dataset.ready) {
    grid.dataset.ready = '1';
    let html = '';
    for (let r = 1; r <= 8; r++) {
      for (let c = 1; c <= 8; c++) {
        html += `<button type="button" class="ge-table-picker__cell" data-r="${r}" data-c="${c}" aria-label="${r} by ${c}"></button>`;
      }
    }
    grid.innerHTML = html;
    grid.addEventListener('mouseover', (e) => {
      const cell = e.target.closest('[data-r][data-c]');
      if (!cell) return;
      highlightTableGrid(Number(cell.dataset.r), Number(cell.dataset.c));
    });
    grid.addEventListener('click', (e) => {
      const cell = e.target.closest('[data-r][data-c]');
      if (!cell) return;
      const withHeader = Boolean(document.querySelector('[data-ge-table-header]')?.checked);
      insertGuideTable(Number(cell.dataset.r), Number(cell.dataset.c), withHeader);
    });
  }

  document.querySelector('[data-ge-table-create]')?.addEventListener('click', () => {
    const gridEl = document.querySelector('[data-ge-table-grid]');
    const rows = Number(gridEl?.dataset.rows || 3);
    const cols = Number(gridEl?.dataset.cols || 3);
    const withHeader = Boolean(document.querySelector('[data-ge-table-header]')?.checked);
    insertGuideTable(rows, cols, withHeader);
  });

  document.querySelector('[data-ge-table-cancel]')?.addEventListener('click', closeTableSizePicker);

  document.querySelector('[data-ge-table-edit]')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-table-cmd]');
    if (!btn) return;
    e.preventDefault();
    const cmd = btn.getAttribute('data-table-cmd');
    if (cmd === 'row-before') insertTableRow(true);
    if (cmd === 'row-after') insertTableRow(false);
    if (cmd === 'col-before') insertTableColumn(true);
    if (cmd === 'col-after') insertTableColumn(false);
    if (cmd === 'del-row') deleteTableRow();
    if (cmd === 'del-col') deleteTableColumn();
    if (cmd === 'header') toggleTableHeader();
  });

  document.addEventListener('selectionchange', () => {
    syncTableEditBar();
  });

  document.addEventListener('mousedown', (e) => {
    const pop = document.querySelector('[data-ge-table-picker]');
    if (!pop || pop.hidden) return;
    if (e.target.closest('[data-ge-table-picker]') || e.target.closest('[data-cmd="insertTable"]')) return;
    closeTableSizePicker();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeTableSizePicker();
    if (e.key !== 'Tab') return;
    const cell = cellFromSelection();
    if (!cell || !activeEditable?.contains(cell)) return;
    const cells = [...(cell.closest('table')?.querySelectorAll('th, td') || [])];
    const idx = cells.indexOf(cell);
    if (idx < 0) return;
    e.preventDefault();
    const next = e.shiftKey ? cells[idx - 1] : cells[idx + 1];
    if (!next) return;
    const range = document.createRange();
    range.selectNodeContents(next);
    range.collapse(false);
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
  });
}

function ensureEditableSelection(editable) {
  if (!editable) return;
  editable.focus();
  const sel = window.getSelection();
  if (!sel) return;
  if (sel.rangeCount === 0 || !editable.contains(sel.anchorNode)) {
    const range = document.createRange();
    range.selectNodeContents(editable);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);
  }
}

function pasteInlineImageFile(file, onInput) {
  const editable = activeEditable;
  if (!editable || !file) return;

  const token = `ge-img-${Date.now()}`;
  const marker = `<span class="ge-inline-image-loading" contenteditable="false" data-ge-img-load="${token}" aria-live="polite"><span class="ge-inline-image-loading__spinner" aria-hidden="true"></span><span class="ge-inline-image-loading__text">Uploading image…</span></span>`;

  ensureEditableSelection(editable);
  document.execCommand('insertHTML', false, marker);
  if (!editable.querySelector(`[data-ge-img-load="${token}"]`)) {
    editable.innerHTML = marker;
  }

  uploadImage(file)
    .then((url) => {
      const loading = editable.querySelector(`[data-ge-img-load="${token}"]`);
      const imgHtml = `<img src="${escAttr(url)}" alt="">`;
      if (loading) {
        loading.outerHTML = imgHtml;
      } else {
        ensureEditableSelection(editable);
        document.execCommand('insertHTML', false, imgHtml);
      }
      onInput(editable.innerHTML);
      updateContinueZone();
      const img = editable.querySelector(`img[src="${escAttr(url)}"]`) || editable.querySelector('img:last-of-type');
      if (img && activeBlockIndex >= 0) {
        wrapInlineImageWithChrome(img, activeBlockIndex);
        onInput(editable.innerHTML);
        selectInlineImage(img, activeBlockIndex);
      }
      toast('Image added');
    })
    .catch((err) => {
      editable.querySelector(`[data-ge-img-load="${token}"]`)?.remove();
      onInput(editable.innerHTML);
      toast(err.message || 'Upload failed');
    });
}

function syncBlockFormatSelect() {
  const sel = document.querySelector('[data-ge-block-format]');
  if (!sel) return;
  if (state.selectedIndex < 0) {
    sel.value = 'paragraph';
    return;
  }
  const block = state.blocks[state.selectedIndex];
  if (block.type === 'quote') sel.value = 'quote';
  else if (block.type === 'heading') sel.value = `h${block.level || 2}`;
  else sel.value = 'paragraph';
}

function applyBlockFormat(value) {
  if (value === 'quote') {
    convertSelectedTo('quote');
    return;
  }
  if (value === 'paragraph') {
    convertSelectedTo('paragraph');
    return;
  }
  if (value.startsWith('h')) {
    setHeadingLevel(parseInt(value.slice(1), 10));
  }
}

function handleToolbarCommand(cmd) {
  if (cmd === 'undo') {
    runFormatCommand('undo');
    return;
  }
  if (cmd === 'redo') {
    runFormatCommand('redo');
    return;
  }
  if (cmd === 'link') {
    openLinkModal();
    return;
  }
  if (cmd === 'insertUnorderedList' || cmd === 'insertOrderedList') {
    runFormatCommand(cmd);
    return;
  }
  if (cmd === 'insertChecklist') {
    if (activeEditable) {
      activeEditable.focus();
      document.execCommand('insertHTML', false, '<ul class="ge-checklist"><li></li></ul>');
      activeEditable.dispatchEvent(new Event('input', { bubbles: true }));
    }
    return;
  }
  if (cmd === 'insertTable') {
    openTableSizePicker();
    return;
  }
  if (cmd === 'insertImage') {
    document.querySelector('[data-ge-inline-image-file]')?.click();
    return;
  }
  if (['bold', 'italic', 'underline'].includes(cmd)) {
    runFormatCommand(cmd);
  }
}

function insertBlocksAt(index, newBlocks) {
  if (!newBlocks.length) return;
  const at = index >= 0 ? index : state.blocks.length - 1;
  state.blocks.splice(at, 1, ...newBlocks);
  state.selectedIndex = at + newBlocks.length - 1;
  renderBlocks();
}

function runFormatCommand(cmd, value = null) {
  if (activeEditable) {
    activeEditable.focus();
    document.execCommand(cmd, false, value);
    activeEditable.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  }
  return false;
}

function normalizeLinkUrl(raw) {
  const url = String(raw || '').trim();
  if (!url) return '';
  if (url.startsWith('/') || /^https?:\/\//i.test(url) || /^mailto:/i.test(url)) {
    return url;
  }
  return `https://${url}`;
}

function saveLinkSelection() {
  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0 || !activeEditable) {
    savedLinkRange = null;
    return;
  }
  const range = sel.getRangeAt(0);
  if (!activeEditable.contains(range.commonAncestorContainer)) {
    savedLinkRange = null;
    return;
  }
  savedLinkRange = range.cloneRange();
}

function restoreLinkSelection() {
  if (!savedLinkRange) return false;
  const sel = window.getSelection();
  if (!sel) return false;
  sel.removeAllRanges();
  sel.addRange(savedLinkRange);
  return true;
}

function getLinkHrefFromSavedSelection() {
  if (!savedLinkRange || !activeEditable) return '';
  let node = savedLinkRange.commonAncestorContainer;
  if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;
  const anchor = node?.closest?.('a');
  if (anchor && activeEditable.contains(anchor)) {
    return anchor.getAttribute('href') || '';
  }
  return '';
}

function showLinkModal(href = '') {
  const modal = document.querySelector('[data-ge-link-modal]');
  const input = document.querySelector('[data-ge-link-url]');
  const removeBtn = document.querySelector('[data-ge-link-remove]');
  if (!modal || !input) return;

  input.value = href;
  if (removeBtn) removeBtn.hidden = !href;

  modal.hidden = false;
  requestAnimationFrame(() => {
    input.focus();
    input.select();
  });
}

function selectLinkAnchor(anchor) {
  const range = document.createRange();
  range.selectNodeContents(anchor);
  savedLinkRange = range.cloneRange();
  const sel = window.getSelection();
  if (!sel) return;
  sel.removeAllRanges();
  sel.addRange(savedLinkRange);
}

function openLinkModalFromAnchor(anchor, editable, blockIndex) {
  activeEditable = editable;
  activeBlockIndex = blockIndex;
  if (blockIndex >= 0) {
    state.selectedIndex = blockIndex;
    document.querySelectorAll('.ge-block-wrap').forEach((wrap, i) => {
      wrap.classList.toggle('is-selected', i === blockIndex);
    });
  }
  editable.focus();
  selectLinkAnchor(anchor);
  showLinkModal(anchor.getAttribute('href') || '');
}

function openLinkModal() {
  if (!activeEditable) {
    toast('Click in the text you want to link first');
    return;
  }

  activeEditable.focus();
  saveLinkSelection();

  const href = getLinkHrefFromSavedSelection();
  const editingExisting = Boolean(href);

  if (!savedLinkRange || (savedLinkRange.collapsed && !editingExisting)) {
    toast('Highlight the word or sentence you want to link first');
    return;
  }

  showLinkModal(href);
}

function closeLinkModal() {
  const modal = document.querySelector('[data-ge-link-modal]');
  if (modal) modal.hidden = true;
  savedLinkRange = null;
}

function applyLinkFromModal() {
  const input = document.querySelector('[data-ge-link-url]');
  if (!input || !activeEditable) return;

  const href = normalizeLinkUrl(input.value);
  if (!href) {
    toast('Enter a link URL');
    input.focus();
    return;
  }

  if (!restoreLinkSelection()) {
    toast('Could not apply the link. Try selecting the text again.');
    closeLinkModal();
    return;
  }

  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0 || sel.getRangeAt(0).collapsed) {
    toast('Highlight the word or sentence you want to link first');
    return;
  }

  const range = sel.getRangeAt(0);
  let node = range.commonAncestorContainer;
  if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;
  const existing = node?.closest?.('a');

  if (existing && activeEditable.contains(existing)) {
    existing.setAttribute('href', href);
    existing.removeAttribute('target');
    activeEditable.dispatchEvent(new Event('input', { bubbles: true }));
    closeLinkModal();
    return;
  }

  const anchor = document.createElement('a');
  anchor.href = href;
  anchor.setAttribute('data-ge-link', '1');

  try {
    range.surroundContents(anchor);
  } catch {
    const fragment = range.extractContents();
    anchor.appendChild(fragment);
    range.insertNode(anchor);
  }

  sel.removeAllRanges();
  const after = document.createRange();
  after.selectNodeContents(anchor);
  after.collapse(false);
  sel.addRange(after);

  activeEditable.dispatchEvent(new Event('input', { bubbles: true }));
  closeLinkModal();
}

function removeLinkFromModal() {
  if (!activeEditable || !restoreLinkSelection()) {
    closeLinkModal();
    return;
  }

  let node = savedLinkRange?.commonAncestorContainer;
  if (node?.nodeType === Node.TEXT_NODE) node = node.parentElement;
  const existing = node?.closest?.('a');
  if (existing && activeEditable.contains(existing)) {
    const parent = existing.parentNode;
    while (existing.firstChild) {
      parent.insertBefore(existing.firstChild, existing);
    }
    parent.removeChild(existing);
    activeEditable.dispatchEvent(new Event('input', { bubbles: true }));
  }

  closeLinkModal();
}

function bindLinkModal() {
  document.querySelector('[data-ge-link-cancel]')?.addEventListener('click', closeLinkModal);
  document.querySelector('[data-ge-link-remove]')?.addEventListener('click', removeLinkFromModal);
  document.querySelector('[data-ge-link-apply]')?.addEventListener('click', applyLinkFromModal);
  document.querySelector('[data-ge-link-url]')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      applyLinkFromModal();
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      closeLinkModal();
    }
  });
  document.querySelector('[data-ge-link-modal]')?.addEventListener('click', (e) => {
    if (e.target.matches('[data-ge-link-modal]')) closeLinkModal();
  });

  document.addEventListener('keydown', (e) => {
    if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'k') return;
    if (document.activeElement?.matches('[data-ge-title], input, textarea, select')) return;
    if (!activeEditable) return;
    e.preventDefault();
    openLinkModal();
  });
}

async function boot() {
  try {
    await admFetch('me.php');
  } catch (err) {
    if (err.status === 401 || err.status === 403) {
      location.replace('/admin/login');
      return;
    }
    throw err;
  }

  ensureSidebarPanels();
  bindChrome();
  syncTopbarOffset();
  window.addEventListener('resize', syncTopbarOffset, { passive: true });
  renderBlocks();

  try {
    if (state.id > 0) {
      const data = await admFetch(`guides.php?id=${state.id}`);
      applyPost(data.post);
      state.meta = data.meta || state.meta;
      renderBlocks();
    } else {
      const data = await admFetch('guides.php');
      state.meta = data.meta || state.meta;
    }
    populateMetaFields();
  } catch (err) {
    console.error(err);
    populateMetaFields();
    toast(err.message || 'Could not load guide settings');
  }

  updateReadMins();
  resizeTitleField();
  syncTopbarOffset();
}

function applyPost(post) {
  state.id = post.id;
  state.title = post.title || '';
  state.slug = post.slug || '';
  state.description = post.description || '';
  state.category = post.category || 'Basics';
  state.schema_type = post.schema_type || 'Article';
  state.featured_image = post.featured_image || '';
  state.status = post.status || 'draft';
  state.blocks = (post.blocks && post.blocks.length)
    ? normalizeBlocks(post.blocks.map((b) => {
        const block = { ...b, id: b.id || uid() };
        return block.type === 'table' ? normalizeTableBlock(block) : block;
      }))
    : [defaultBlock('paragraph')];
  salvageGuideBlocksInState();

  document.querySelector('[data-ge-title]').value = state.title;
  document.querySelector('[data-ge-slug]').value = state.slug;
  const slugPreview = document.querySelector('[data-ge-slug-preview]');
  if (slugPreview) slugPreview.textContent = state.slug || 'your-slug';
  document.querySelector('[data-ge-description]').value = state.description;
  document.querySelector('[data-ge-category]').value = state.category;
  document.querySelector('[data-ge-schema]').value = state.schema_type;
  document.querySelector('[data-ge-status]').textContent = state.status === 'published' ? 'Published' : 'Draft';
  const featuredUrl = document.querySelector('[data-ge-featured-url]');
  if (featuredUrl) featuredUrl.value = state.featured_image;
  updateFeaturedPreview();
  resizeTitleField();
}

function populateMetaFields() {
  const cat = document.querySelector('[data-ge-category]');
  const schema = document.querySelector('[data-ge-schema]');
  if (cat && state.meta.categories) {
    cat.innerHTML = state.meta.categories.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    cat.value = state.category;
  }
  if (schema && state.meta.schema_types) {
    schema.innerHTML = state.meta.schema_types.map((s) => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
    schema.value = state.schema_type;
  }
}

function bindChrome() {
  document.querySelector('[data-ge-back]')?.addEventListener('click', () => {
    location.href = '/admin/guides.html';
  });

  document.querySelector('[data-ge-save-draft]')?.addEventListener('click', () => save('draft'));
  document.querySelector('[data-ge-publish]')?.addEventListener('click', () => save('published'));

  document.querySelector('[data-ge-title]')?.addEventListener('input', (e) => {
    state.title = e.target.value.replace(/\n/g, ' ');
    if (e.target.value !== state.title) {
      e.target.value = state.title;
    }
    resizeTitleField();
    if (!state.slug || document.querySelector('[data-ge-slug]').dataset.touched !== '1') {
      state.slug = slugify(state.title);
      document.querySelector('[data-ge-slug]').value = state.slug;
    }
    updateReadMins();
  });

  document.querySelector('[data-ge-title]')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') e.preventDefault();
  });

  document.querySelector('[data-ge-slug]')?.addEventListener('input', (e) => {
    e.target.dataset.touched = '1';
    state.slug = slugify(e.target.value);
    e.target.value = state.slug;
  });

  document.querySelector('[data-ge-description]')?.addEventListener('input', (e) => {
    state.description = e.target.value;
  });

  document.querySelector('[data-ge-category]')?.addEventListener('change', (e) => {
    state.category = e.target.value;
  });

  document.querySelector('[data-ge-schema]')?.addEventListener('change', (e) => {
    state.schema_type = e.target.value;
  });

  document.querySelector('[data-ge-featured-upload]')?.addEventListener('click', () => {
    document.querySelector('[data-ge-featured-file]').click();
  });

  document.querySelector('[data-ge-featured-file]')?.addEventListener('change', async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
      const url = await uploadImage(file);
      state.featured_image = url;
      document.querySelector('[data-ge-featured-url]').value = url;
      updateFeaturedPreview();
      toast('Featured image uploaded');
    } catch (err) {
      toast(err.message || 'Upload failed');
    }
    e.target.value = '';
  });

  document.querySelector('[data-ge-featured-url]')?.addEventListener('input', (e) => {
    state.featured_image = e.target.value.trim();
    updateFeaturedPreview();
  });

  document.querySelector('[data-ge-featured-clear]')?.addEventListener('click', () => {
    state.featured_image = '';
    document.querySelector('[data-ge-featured-url]').value = '';
    updateFeaturedPreview();
  });

  document.querySelector('[data-ge-modal-close]')?.addEventListener('click', closeInserter);
  document.querySelector('[data-ge-inserter-modal]')?.addEventListener('click', (e) => {
    if (e.target.matches('[data-ge-inserter-modal]')) closeInserter();
  });
  bindAskAiModal();

  bindToolbar();
  bindTableUi();
  bindLinkModal();
  bindImageSidebar();
  bindImageGear();
}

function bindToolbar() {
  const toolbar = document.querySelector('[data-ge-toolbar]');
  const formatSelect = document.querySelector('[data-ge-block-format]');
  const inlineImageFile = document.querySelector('[data-ge-inline-image-file]');

  toolbar?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-cmd]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    handleToolbarCommand(btn.getAttribute('data-cmd'));
  });

  formatSelect?.addEventListener('change', () => {
    applyBlockFormat(formatSelect.value);
    syncBlockFormatSelect();
  });

  inlineImageFile?.addEventListener('change', async () => {
    const file = inlineImageFile.files?.[0];
    if (!file) return;
    try {
      const url = await uploadImage(file);
      insertHtmlAtCursor(`<img src="${escAttr(url)}" alt="">`);
      const img = activeEditable?.querySelector(`img[src="${escAttr(url)}"]`)
        || activeEditable?.querySelector('img:last-of-type');
      if (img && activeBlockIndex >= 0) {
        wrapInlineImageWithChrome(img, activeBlockIndex);
        activeEditable.dispatchEvent(new Event('input', { bubbles: true }));
        selectInlineImage(img, activeBlockIndex);
      }
    } catch (err) {
      toast(err.message || 'Upload failed');
    }
    inlineImageFile.value = '';
  });
}

function convertSelectedTo(type) {
  const idx = state.selectedIndex;
  if (idx < 0) return;
  const block = state.blocks[idx];
  const text = block.text || '';
  if (type === 'quote' && block.type !== 'quote') {
    state.blocks[idx] = { ...defaultBlock('quote'), text, id: block.id };
    renderBlocks();
  }
  if (type === 'paragraph' && block.type !== 'paragraph') {
    state.blocks[idx] = { ...defaultBlock('paragraph'), text, id: block.id };
    renderBlocks();
  }
}

function setHeadingLevel(level) {
  const idx = state.selectedIndex;
  if (idx < 0) return;
  const block = state.blocks[idx];
  if (block.type !== 'heading') {
    block.type = 'heading';
  }
  block.level = level;
  renderBlocks();
  syncBlockFormatSelect();
}

function updateFeaturedPreview() {
  const el = document.querySelector('[data-ge-featured-preview]');
  if (!el) return;
  if (state.featured_image) {
    el.classList.remove('is-empty');
    el.style.backgroundImage = `url("${state.featured_image}")`;
    el.textContent = '';
  } else {
    el.classList.add('is-empty');
    el.style.backgroundImage = '';
    el.textContent = 'No featured image';
  }
}

function updateReadMins() {
  const el = document.querySelector('[data-ge-read-mins]');
  if (el) el.textContent = `${estimateReadMins()} min read (estimated)`;
}

function syncImageCaptionInEditor(index) {
  const wrap = document.querySelectorAll('.ge-block-wrap')[index];
  const block = state.blocks[index];
  if (!wrap || !block || block.type !== 'image') return;

  const figure = wrap.querySelector('.ge-image-figure');
  if (!figure) return;

  let captionEl = figure.querySelector('[data-ge-image-caption-preview]');
  const caption = (block.caption || '').trim();

  if (!caption) {
    captionEl?.remove();
    return;
  }

  if (!captionEl) {
    captionEl = document.createElement('figcaption');
    captionEl.className = 'ge-image-caption';
    captionEl.dataset.geImageCaptionPreview = '';
    figure.appendChild(captionEl);
  }

  captionEl.textContent = caption;
}

function updateImageBlockAltInEditor(index) {
  const wrap = document.querySelectorAll('.ge-block-wrap')[index];
  const block = state.blocks[index];
  const img = wrap?.querySelector('.ge-image-dropzone__img');
  if (img && block?.type === 'image') img.alt = block.alt || '';
}

function refreshImageBlockDropzone(index) {
  const wrap = document.querySelectorAll('.ge-block-wrap')[index];
  const block = state.blocks[index];
  if (!wrap || !block || block.type !== 'image') return;

  const dropzone = wrap.querySelector('.ge-image-dropzone');
  if (!dropzone) return;

  dropzone.classList.toggle('has-image', Boolean(block.url));
  dropzone.setAttribute('aria-label', block.url ? 'Replace image' : 'Add image');

  if (block.url) {
    dropzone.innerHTML = `
      ${imageBlockGearHtml()}
      <img class="ge-image-dropzone__img" src="${esc(block.url)}" alt="${escAttr(block.alt || '')}">
      <div class="ge-image-dropzone__overlay" aria-hidden="true">
        <span class="ge-image-dropzone__overlay-text">Replace image</span>
      </div>
    `;
    return;
  }

  dropzone.innerHTML = `
    <div class="ge-image-dropzone__empty">
      <span class="ge-image-dropzone__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
          <rect x="3" y="5" width="18" height="14" rx="2"/>
          <circle cx="9" cy="10" r="1.5" fill="currentColor" stroke="none"/>
          <path d="m3 17 5.5-5.5 3 3 3.5-4.5 6 7"/>
        </svg>
      </span>
      <span class="ge-image-dropzone__title">Add an image</span>
      <span class="ge-image-dropzone__hint">Drop a file here, paste with ⌘V, or click to browse</span>
    </div>
  `;
}

const IMAGE_GEAR_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';

function createImageGearButton() {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'ge-image-gear';
  btn.dataset.geImageGear = '';
  btn.contentEditable = 'false';
  btn.setAttribute('aria-label', 'Image settings');
  btn.innerHTML = IMAGE_GEAR_SVG;
  return btn;
}

function blockIndexFromWrap(wrap) {
  if (!wrap?.dataset.index) return -1;
  const index = parseInt(wrap.dataset.index, 10);
  return Number.isFinite(index) ? index : -1;
}

function openImageSettingsFromGear(gear) {
  const blockWrap = gear.closest('.ge-block-wrap');
  const blockIndex = blockIndexFromWrap(blockWrap);

  if (gear.closest('.ge-image-dropzone')) {
    clearInlineImageSelection();
    if (blockIndex >= 0) selectBlock(blockIndex);
    return;
  }

  const fig = gear.closest('figure.ge-inline-figure');
  const img = fig?.querySelector('img:not(.ge-image-dropzone__img)');
  if (img && blockIndex >= 0) {
    selectInlineImage(img, blockIndex);
  }
}

function handleImageGearPointer(e) {
  const gear = e.target.closest('[data-ge-image-gear]');
  if (!gear) return;
  e.preventDefault();
  e.stopPropagation();
}

function handleImageGearClick(e) {
  const gear = e.target.closest('[data-ge-image-gear]');
  if (!gear) return;
  e.preventDefault();
  e.stopPropagation();
  e.stopImmediatePropagation();
  openImageSettingsFromGear(gear);
}

function bindImageGear() {
  document.addEventListener('pointerdown', handleImageGearPointer, true);
  document.addEventListener('click', handleImageGearClick, true);
}

function wrapInlineImageWithChrome(img, blockIndex) {
  if (!img || blockIndex < 0 || img.closest('.ge-image-block')) return null;
  ensureInlineImageId(img);

  let fig = img.closest('figure.ge-inline-figure');
  if (!fig) {
    fig = document.createElement('figure');
    fig.className = 'ge-inline-figure';
    fig.contentEditable = 'false';
    fig.dataset.geInlineFigure = '';
    img.parentNode.insertBefore(fig, img);
    fig.appendChild(img);
  }

  if (!fig.querySelector('[data-ge-image-gear]')) {
    fig.insertBefore(createImageGearButton(), fig.firstChild);
  }

  return fig;
}

function decorateInlineImages(editable, blockIndex) {
  if (!editable || blockIndex < 0) return;
  editable.querySelectorAll('img').forEach((img) => {
    if (img.classList.contains('ge-image-dropzone__img')) return;
    wrapInlineImageWithChrome(img, blockIndex);
  });
}

function imageBlockGearHtml() {
  return `<button type="button" class="ge-image-gear ge-image-gear--block" data-ge-image-gear aria-label="Image settings">${IMAGE_GEAR_SVG}</button>`;
}

function clearInlineImageSelection() {
  state.inlineImageId = null;
  document.querySelectorAll('.ge-inline-img.is-selected').forEach((el) => {
    el.classList.remove('is-selected');
  });
}

function ensureInlineImageId(img) {
  if (!img.dataset.geImgId) {
    const id = uid();
    img.dataset.geImgId = id;
    img.setAttribute('data-ge-img-id', id);
  }
  img.classList.add('ge-inline-img');
  return img.dataset.geImgId;
}

function getActiveInlineImage() {
  if (!state.inlineImageId || state.selectedIndex < 0) return null;
  const wrap = document.querySelectorAll('.ge-block-wrap')[state.selectedIndex];
  if (!wrap) return null;
  const id = state.inlineImageId.replace(/"/g, '\\"');
  return wrap.querySelector(`img.ge-inline-img[data-ge-img-id="${id}"]`)
    || wrap.querySelector(`img[data-ge-img-id="${id}"]`);
}

function getImageSidebarMode() {
  const block = state.selectedIndex >= 0 ? state.blocks[state.selectedIndex] : null;
  if (block?.type === 'image') return 'block';
  if (state.inlineImageId && getActiveInlineImage()) return 'inline';
  return null;
}

function persistInlineEditableHtml() {
  if (!activeEditable || state.selectedIndex < 0) return;
  // After a re-render activeEditable can point at a detached editor from another block.
  if (!activeEditable.isConnected) return;
  const wrap = activeEditable.closest('.ge-block-wrap');
  if (!wrap || parseInt(wrap.dataset.index, 10) !== state.selectedIndex) return;
  const block = state.blocks[state.selectedIndex];
  if (!block || block.text === undefined) return;
  block.text = activeEditable.innerHTML;
  updateReadMins();
}

function getInlineImageCaption(img) {
  const fig = img.closest('figure.ge-inline-figure');
  return fig?.querySelector('figcaption.ge-image-caption')?.textContent || '';
}

function setInlineImageCaption(img, caption) {
  const trimmed = (caption || '').trim();
  const blockIndex = state.selectedIndex;
  if (blockIndex >= 0) wrapInlineImageWithChrome(img, blockIndex);

  let fig = img.closest('figure.ge-inline-figure');

  if (!trimmed) {
    fig?.querySelector('figcaption.ge-image-caption')?.remove();
    return;
  }

  if (!fig) {
    fig = document.createElement('figure');
    fig.className = 'ge-inline-figure';
    fig.contentEditable = 'false';
    fig.dataset.geInlineFigure = '';
    img.parentNode.insertBefore(fig, img);
    fig.appendChild(img);
    if (blockIndex >= 0) {
      fig.insertBefore(createImageGearButton(), fig.firstChild);
    }
  }

  let cap = fig.querySelector('figcaption.ge-image-caption');
  if (!cap) {
    cap = document.createElement('figcaption');
    cap.className = 'ge-image-caption ge-image-caption--inline';
    cap.dataset.geImageCaptionPreview = '';
    fig.appendChild(cap);
  }
  cap.textContent = trimmed;
}

function selectInlineImage(img, blockIndex) {
  if (!img) return;
  ensureInlineImageId(img);
  state.inlineImageId = img.dataset.geImgId;
  state.selectedIndex = blockIndex;
  activeEditable = img.closest('[contenteditable="true"]');
  activeBlockIndex = blockIndex;

  document.querySelectorAll('.ge-inline-img.is-selected').forEach((el) => {
    el.classList.remove('is-selected');
  });
  img.classList.add('is-selected');

  document.querySelectorAll('.ge-block-wrap').forEach((el, i) => {
    el.classList.toggle('is-selected', i === blockIndex);
  });
  syncBlockFormatSelect();
  syncImageSidebar();
}

function selectBlock(index, { keepInlineImage = false } = {}) {
  if (index < 0 || index >= state.blocks.length) return;
  state.selectedIndex = index;
  if (!keepInlineImage) clearInlineImageSelection();
  document.querySelectorAll('.ge-block-wrap').forEach((el, i) => {
    el.classList.toggle('is-selected', i === index);
  });
  syncBlockFormatSelect();
  syncImageSidebar();
}

function ensureSidebarPanels() {
  const sidebar = document.querySelector('.ge-sidebar');
  if (!sidebar) return;

  let title = document.querySelector('[data-ge-sidebar-title]');
  if (!title) {
    title = sidebar.querySelector('.ge-sidebar__title');
    if (title) title.dataset.geSidebarTitle = '';
  }

  let docPanel = document.querySelector('[data-ge-doc-settings]');
  if (!docPanel) {
    docPanel = document.createElement('div');
    docPanel.className = 'ge-sidebar-panel';
    docPanel.dataset.geDocSettings = '';
    const movable = [...sidebar.children].filter(
      (el) => !el.matches('[data-ge-sidebar-title], [data-ge-image-settings], .ge-sidebar__title')
    );
    movable.forEach((el) => docPanel.appendChild(el));
    sidebar.appendChild(docPanel);
  }

  if (!document.querySelector('[data-ge-image-settings]')) {
    const imagePanel = document.createElement('div');
    imagePanel.className = 'ge-image-settings ge-sidebar-panel';
    imagePanel.dataset.geImageSettings = '';
    imagePanel.hidden = true;
    imagePanel.innerHTML = `
      <div class="ge-field">
        <label for="ge-image-alt">Alt text</label>
        <input id="ge-image-alt" type="text" data-ge-image-alt placeholder="Describe the image for screen readers" autocomplete="off">
        <p class="ge-field__hint">Shown to screen readers and used when the image cannot load.</p>
      </div>
      <div class="ge-field">
        <label for="ge-image-caption">Caption</label>
        <textarea id="ge-image-caption" data-ge-image-caption rows="3" placeholder="Optional caption shown under the image"></textarea>
        <p class="ge-field__hint">Updates live in the editor as you type.</p>
      </div>
      <div class="ge-field">
        <label for="ge-image-url">Image URL</label>
        <input id="ge-image-url" type="url" data-ge-image-url placeholder="https:// or /images/..." autocomplete="off">
      </div>
    `;
    sidebar.appendChild(imagePanel);
    imageSidebarBound = false;
  }
}

function syncImageSidebar() {
  ensureSidebarPanels();

  const docPanel = document.querySelector('[data-ge-doc-settings]');
  const imagePanel = document.querySelector('[data-ge-image-settings]');
  const title = document.querySelector('[data-ge-sidebar-title]') || document.querySelector('.ge-sidebar__title');
  if (!docPanel || !imagePanel || !title) return;

  const idx = state.selectedIndex;
  const block = idx >= 0 ? state.blocks[idx] : null;
  const mode = getImageSidebarMode();
  const showImage = mode !== null;

  docPanel.hidden = showImage;
  imagePanel.hidden = !showImage;
  docPanel.classList.toggle('is-hidden', showImage);
  imagePanel.classList.toggle('is-hidden', !showImage);
  title.textContent = showImage ? 'Image' : 'Document settings';

  if (!showImage) return;

  syncingImageSidebar = true;
  const altInput = document.querySelector('[data-ge-image-alt]');
  const captionInput = document.querySelector('[data-ge-image-caption]');
  const urlInput = document.querySelector('[data-ge-image-url]');

  if (mode === 'block' && block) {
    if (altInput) altInput.value = block.alt || '';
    if (captionInput) captionInput.value = block.caption || '';
    if (urlInput) urlInput.value = block.url || '';
  } else if (mode === 'inline') {
    const img = getActiveInlineImage();
    if (img) {
      if (altInput) altInput.value = img.getAttribute('alt') || '';
      if (captionInput) captionInput.value = getInlineImageCaption(img);
      if (urlInput) urlInput.value = img.getAttribute('src') || '';
    }
  }

  syncingImageSidebar = false;
}

function bindImageSidebar() {
  if (imageSidebarBound) return;
  imageSidebarBound = true;

  document.querySelector('[data-ge-image-alt]')?.addEventListener('input', (e) => {
    if (syncingImageSidebar) return;
    const mode = getImageSidebarMode();
    if (mode === 'block') {
      const block = state.blocks[state.selectedIndex];
      if (!block || block.type !== 'image') return;
      block.alt = e.target.value;
      updateImageBlockAltInEditor(state.selectedIndex);
      return;
    }
    if (mode === 'inline') {
      const img = getActiveInlineImage();
      if (!img) return;
      img.alt = e.target.value;
      persistInlineEditableHtml();
    }
  });

  document.querySelector('[data-ge-image-caption]')?.addEventListener('input', (e) => {
    if (syncingImageSidebar) return;
    const mode = getImageSidebarMode();
    if (mode === 'block') {
      const block = state.blocks[state.selectedIndex];
      if (!block || block.type !== 'image') return;
      block.caption = e.target.value;
      syncImageCaptionInEditor(state.selectedIndex);
      return;
    }
    if (mode === 'inline') {
      const img = getActiveInlineImage();
      if (!img) return;
      setInlineImageCaption(img, e.target.value);
      persistInlineEditableHtml();
    }
  });

  document.querySelector('[data-ge-image-url]')?.addEventListener('input', (e) => {
    if (syncingImageSidebar) return;
    const mode = getImageSidebarMode();
    if (mode === 'block') {
      const idx = state.selectedIndex;
      const block = state.blocks[idx];
      if (!block || block.type !== 'image') return;
      block.url = e.target.value.trim();
      refreshImageBlockDropzone(idx);
      syncImageCaptionInEditor(idx);
      return;
    }
    if (mode === 'inline') {
      const img = getActiveInlineImage();
      if (!img) return;
      img.setAttribute('src', e.target.value.trim());
      persistInlineEditableHtml();
    }
  });
}

function renderBlocks() {
  const root = document.querySelector('[data-ge-blocks]');
  if (!root) return;
  clearInlineImageSelection();
  root.innerHTML = '';

  state.blocks.forEach((block, index) => {
    root.appendChild(renderInserter(index));
    root.appendChild(renderBlockWrap(block, index));
  });
  if (hasEditorContent()) {
    root.appendChild(renderContinueZone(state.blocks.length));
  }

  updateReadMins();
  syncImageSidebar();
  if (state.selectedIndex >= 0 && state.blocks[state.selectedIndex]?.type === 'image') {
    syncImageCaptionInEditor(state.selectedIndex);
    updateImageBlockAltInEditor(state.selectedIndex);
  }
}

function renderInserter(index) {
  const el = document.createElement('div');
  el.className = 'ge-inserter';
  el.innerHTML = `<button type="button" class="ge-inserter__btn" aria-label="Add block" data-inserter="${index}">+</button>`;
  el.querySelector('button').addEventListener('click', (e) => {
    e.stopPropagation();
    openInserter(index);
  });
  return el;
}

function renderContinueZone(index) {
  const el = document.createElement('div');
  el.className = 'ge-continue';
  el.innerHTML = `
    <button type="button" class="ge-continue__hit" aria-label="Click to continue writing">
      <span class="ge-continue__hint">Click to continue writing</span>
    </button>
    <button type="button" class="ge-inserter__btn ge-continue__add" aria-label="Add block" data-inserter="${index}">+</button>
  `;
  el.querySelector('.ge-continue__add').addEventListener('click', (e) => {
    e.stopPropagation();
    openInserter(index);
  });
  el.querySelector('.ge-continue__hit').addEventListener('click', () => {
    continueWritingAt(index);
  });
  return el;
}

function blockHasContent(block) {
  if (!block) return false;
  if (block.type === 'paragraph' || block.type === 'quote' || block.type === 'callout') {
    return blockTextPlain(block) !== '';
  }
  if (block.type === 'heading') {
    return blockTextPlain({ text: block.text }) !== '';
  }
  if (block.type === 'image') return !!(block.url || block.alt || block.caption);
  if (block.type === 'youtube') return !!(block.url || block.videoId || block.caption);
  if (block.type === 'separator') return true;
  if (block.type === 'table') {
    return true;
  }
  if (block.type === 'list') return (block.items || []).some((item) => String(item || '').trim() !== '');
  return false;
}

function hasEditorContent() {
  if (state.blocks.length > 1) return true;
  return state.blocks.some((block) => blockHasContent(block));
}

function updateContinueZone() {
  const root = document.querySelector('[data-ge-blocks]');
  if (!root) return;
  const existing = root.querySelector('.ge-continue');
  const shouldShow = hasEditorContent();

  if (shouldShow && !existing) {
    root.appendChild(renderContinueZone(state.blocks.length));
  } else if (!shouldShow && existing) {
    existing.remove();
  }
}

function blockTextPlain(block) {
  if (!block) return '';
  if (block.type === 'list') return (block.items || []).join(' ');
  const doc = new DOMParser().parseFromString(block.text || '', 'text/html');
  return (doc.body.textContent || '').trim();
}

function focusBlockEditable(blockIndex) {
  const wrap = document.querySelectorAll('.ge-block-wrap')[blockIndex];
  const editable = wrap?.querySelector('[contenteditable="true"]');
  if (!editable) return;
  editable.focus();
  const range = document.createRange();
  range.selectNodeContents(editable);
  range.collapse(false);
  const sel = window.getSelection();
  sel?.removeAllRanges();
  sel?.addRange(range);
}

function continueWritingAt(index) {
  const lastIdx = state.blocks.length - 1;
  const last = state.blocks[lastIdx];
  if (
    index === state.blocks.length
    && last?.type === 'paragraph'
    && !blockTextPlain(last)
  ) {
    state.selectedIndex = lastIdx;
    renderBlocks();
    requestAnimationFrame(() => focusBlockEditable(lastIdx));
    return;
  }

  insertBlock(index, 'paragraph');
  requestAnimationFrame(() => focusBlockEditable(index));
}

function openInserter(index) {
  state.inserterIndex = index;
  const modal = document.querySelector('[data-ge-inserter-modal]');
  const menu = document.querySelector('[data-ge-inserter-menu]');
  menu.innerHTML = '';

  const askBtn = document.createElement('button');
  askBtn.type = 'button';
  askBtn.className = 'ge-inserter-menu__btn--ai';
  askBtn.innerHTML = `
    <span class="ge-inserter-menu__icon ge-inserter-menu__icon--ai" aria-hidden="true">AI</span>
    <span class="ge-inserter-menu__meta">
      <strong>Ask AI</strong>
      <span>Build tables and blocks from screenshots or notes</span>
    </span>
  `;
  askBtn.addEventListener('click', () => {
    closeInserter();
    openAskAiModal(state.inserterIndex);
  });
  menu.appendChild(askBtn);

  INSERTER_OPTIONS.forEach((opt) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.innerHTML = `
      <span class="ge-inserter-menu__icon">${esc(opt.short)}</span>
      <span>${esc(opt.label)}</span>
    `;
    btn.addEventListener('click', () => {
      insertBlock(state.inserterIndex, opt.type, opt.data || {});
      closeInserter();
    });
    menu.appendChild(btn);
  });
  modal.hidden = false;
  animateModalCard(modal);
}

function closeInserter() {
  const modal = document.querySelector('[data-ge-inserter-modal]');
  if (modal) modal.hidden = true;
}

function reduceMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function animateModalCard(modal) {
  const card = modal?.querySelector('.ge-modal__card');
  if (!card || reduceMotion()) return;
  animate(card, { opacity: [0, 1], y: [12, 0] }, { duration: 0.28, easing: [0.22, 1, 0.36, 1] });
}

function openAskAiModal(index) {
  state.inserterIndex = index;
  state.aiAttachments = [];
  state.aiBusy = false;
  const modal = document.querySelector('[data-ge-ai-modal]');
  const prompt = document.querySelector('[data-ge-ai-prompt]');
  if (prompt && !prompt.value.trim()) {
    prompt.value = 'Please create a table in this article with these exact data, as they are.';
  }
  setAskAiStatus('', '');
  renderAskAiAttachments();
  syncAskAiRunButton();
  if (modal) {
    modal.hidden = false;
    animateModalCard(modal);
  }
  requestAnimationFrame(() => prompt?.focus());
}

function closeAskAiModal() {
  if (state.aiBusy) return;
  const modal = document.querySelector('[data-ge-ai-modal]');
  if (modal) modal.hidden = true;
}

function setAskAiStatus(message, kind = '') {
  const el = document.querySelector('[data-ge-ai-status]');
  if (!el) return;
  if (!message) {
    el.hidden = true;
    el.textContent = '';
    el.classList.remove('is-error', 'is-busy');
    return;
  }
  el.hidden = false;
  el.textContent = message;
  el.classList.toggle('is-error', kind === 'error');
  el.classList.toggle('is-busy', kind === 'busy');
}

function syncAskAiRunButton() {
  const btn = document.querySelector('[data-ge-ai-run]');
  if (!btn) return;
  btn.disabled = state.aiBusy;
  const label = btn.querySelector('.mf-btn__label');
  if (label) label.textContent = state.aiBusy ? 'Working…' : 'Generate';
}

function renderAskAiAttachments() {
  const list = document.querySelector('[data-ge-ai-file-list]');
  if (!list) return;
  list.innerHTML = '';
  if (!state.aiAttachments.length) {
    list.hidden = true;
    return;
  }
  list.hidden = false;
  state.aiAttachments.forEach((item, index) => {
    const card = document.createElement('div');
    card.className = 'ge-ai-file';
    if (item.kind === 'image' && item.previewUrl) {
      const img = document.createElement('img');
      img.src = item.previewUrl;
      img.alt = item.name || 'Attachment';
      card.appendChild(img);
    } else {
      const doc = document.createElement('div');
      doc.className = 'ge-ai-file__doc';
      doc.innerHTML = `<strong>${esc(item.name || 'File')}</strong><span>${esc(item.kind === 'text' ? 'Text file' : 'File')}</span>`;
      card.appendChild(doc);
    }
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'ge-ai-file__remove';
    remove.title = 'Remove';
    remove.setAttribute('aria-label', 'Remove attachment');
    remove.textContent = '×';
    remove.addEventListener('click', (e) => {
      e.stopPropagation();
      const gone = state.aiAttachments.splice(index, 1)[0];
      if (gone?.previewUrl) URL.revokeObjectURL(gone.previewUrl);
      renderAskAiAttachments();
    });
    card.appendChild(remove);
    list.appendChild(card);
  });
}

async function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('Could not read file'));
    reader.readAsDataURL(file);
  });
}

async function readFileAsText(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('Could not read file'));
    reader.readAsText(file);
  });
}

function isHeicFile(file) {
  const type = String(file?.type || '').toLowerCase();
  const name = String(file?.name || '').toLowerCase();
  return type === 'image/heic'
    || type === 'image/heif'
    || type === 'image/heic-sequence'
    || type === 'image/heif-sequence'
    || /\.heic$/i.test(name)
    || /\.heif$/i.test(name);
}

function isAskAiImageFile(file) {
  const type = String(file?.type || '').toLowerCase();
  if (type.startsWith('image/')) return true;
  return isHeicFile(file) || /\.(jpe?g|png|gif|webp|bmp)$/i.test(String(file?.name || ''));
}

let heic2anyLoader = null;

function loadHeic2Any() {
  if (typeof window !== 'undefined' && typeof window.heic2any === 'function') {
    return Promise.resolve(window.heic2any);
  }
  if (heic2anyLoader) return heic2anyLoader;
  heic2anyLoader = new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-heic2any]');
    if (existing) {
      existing.addEventListener('load', () => {
        if (typeof window.heic2any === 'function') resolve(window.heic2any);
        else reject(new Error('HEIC converter failed to load'));
      });
      existing.addEventListener('error', () => reject(new Error('HEIC converter failed to load')));
      return;
    }
    const script = document.createElement('script');
    script.src = '/js/vendor/heic2any.min.js?v=20260920heic';
    script.async = true;
    script.dataset.heic2any = '1';
    script.onload = () => {
      if (typeof window.heic2any === 'function') resolve(window.heic2any);
      else reject(new Error('HEIC converter failed to load'));
    };
    script.onerror = () => reject(new Error('HEIC converter failed to load'));
    document.head.appendChild(script);
  });
  return heic2anyLoader;
}

async function convertHeicToJpegFile(file) {
  const heic2any = await loadHeic2Any();
  const result = await heic2any({
    blob: file,
    toType: 'image/jpeg',
    quality: 0.9,
  });
  const blob = Array.isArray(result) ? result[0] : result;
  if (!(blob instanceof Blob)) {
    throw new Error('Could not convert HEIC image');
  }
  const base = String(file.name || 'screenshot')
    .replace(/\.(heic|heif)$/i, '')
    .trim() || 'screenshot';
  return new File([blob], `${base}.jpg`, { type: 'image/jpeg' });
}

async function prepareAskAiImageFile(file) {
  if (isHeicFile(file)) {
    setAskAiStatus('Converting HEIC screenshot…', 'busy');
    return convertHeicToJpegFile(file);
  }
  return file;
}

async function compressImageFile(file, maxEdge = 1800, quality = 0.84) {
  const dataUrl = await readFileAsDataUrl(file);
  const img = await new Promise((resolve, reject) => {
    const el = new Image();
    el.onload = () => resolve(el);
    el.onerror = () => reject(new Error('Could not load image'));
    el.src = dataUrl;
  });
  const scale = Math.min(1, maxEdge / Math.max(img.width, img.height));
  const width = Math.max(1, Math.round(img.width * scale));
  const height = Math.max(1, Math.round(img.height * scale));
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!ctx) return { mime: file.type || 'image/jpeg', data: dataUrl, name: file.name };
  ctx.drawImage(img, 0, 0, width, height);
  const mime = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
  const out = canvas.toDataURL(mime, quality);
  const name = String(file.name || 'image.jpg').replace(/\.(heic|heif)$/i, '.jpg');
  return { mime, data: out, name };
}

async function addAskAiFiles(fileList) {
  const files = [...(fileList || [])];
  if (!files.length) return;

  for (const file of files) {
    const type = String(file.type || '').toLowerCase();
    const name = file.name || 'file';
    if (isAskAiImageFile(file)) {
      if (state.aiAttachments.filter((a) => a.kind === 'image').length >= 6) {
        toast('You can attach up to 6 images');
        break;
      }
      try {
        const prepared = await prepareAskAiImageFile(file);
        const compressed = await compressImageFile(prepared);
        state.aiAttachments.push({
          kind: 'image',
          name: compressed.name,
          mime: compressed.mime,
          data: compressed.data,
          previewUrl: URL.createObjectURL(prepared),
        });
        setAskAiStatus('', '');
      } catch (err) {
        setAskAiStatus('', '');
        toast(err?.message || `Could not read ${name}`);
      }
      continue;
    }

    if (type.startsWith('text/') || /\.(txt|csv)$/i.test(name)) {
      if (state.aiAttachments.filter((a) => a.kind === 'text').length >= 4) {
        toast('You can attach up to 4 text files');
        break;
      }
      try {
        const text = await readFileAsText(file);
        state.aiAttachments.push({
          kind: 'text',
          name,
          text,
        });
      } catch {
        toast(`Could not read ${name}`);
      }
      continue;
    }

    toast(`${name} is not supported. Use images (including HEIC), CSV, or TXT.`);
  }

  renderAskAiAttachments();
}

function prepareAskAiBlocks(blocks) {
  return (blocks || []).map((raw) => {
    const block = { ...raw, id: uid() };
    if (block.type === 'table') return normalizeTableBlock(block);
    return block;
  }).filter(Boolean);
}

function insertAiBlocks(index, blocks) {
  const prepared = prepareAskAiBlocks(blocks);
  if (!prepared.length) return;
  let at = Math.max(0, Math.min(state.blocks.length, index));
  if (at < state.blocks.length && isEmptyReplaceableBlock(state.blocks[at])) {
    state.blocks.splice(at, 1, ...prepared);
  } else {
    state.blocks.splice(at, 0, ...prepared);
  }
  state.selectedIndex = at;
  renderBlocks();
  const first = prepared[0];
  if (first?.type === 'table') {
    requestAnimationFrame(() => focusTableCell(at, 0, 0));
  } else if (first?.type === 'paragraph' || first?.type === 'heading' || first?.type === 'quote' || first?.type === 'callout') {
    requestAnimationFrame(() => focusBlockEditable(at));
  }
}

async function runAskAi() {
  if (state.aiBusy) return;
  const prompt = document.querySelector('[data-ge-ai-prompt]');
  const researchOn = Boolean(document.querySelector('[data-ge-ai-research]')?.checked);
  const instruction = String(prompt?.value || '').trim();
  if (!instruction) {
    setAskAiStatus('Write an instruction first.', 'error');
    prompt?.focus();
    return;
  }
  if (!state.aiAttachments.length && !researchOn && instruction.length < 12) {
    setAskAiStatus('Add a clearer instruction, attach a file, or turn on web research.', 'error');
    return;
  }

  state.aiBusy = true;
  syncAskAiRunButton();
  setAskAiStatus(
    researchOn
      ? 'Searching verified sources, then building blocks…'
      : 'Reading your files and building blocks…',
    'busy'
  );

  const images = state.aiAttachments
    .filter((a) => a.kind === 'image')
    .map((a) => ({ mime: a.mime, data: a.data, name: a.name }));
  const texts = state.aiAttachments
    .filter((a) => a.kind === 'text')
    .map((a) => ({ name: a.name, text: a.text }));

  try {
    const data = await admFetch('guide-ai.php', {
      method: 'POST',
      body: JSON.stringify({ instruction, images, texts, research: researchOn }),
    });
    insertAiBlocks(state.inserterIndex, data.blocks || []);
    state.aiBusy = false;
    syncAskAiRunButton();
    closeAskAiModal();
    const sourceCount = Number(data.research?.source_count || 0);
    if (researchOn && sourceCount > 0) {
      toast(`${data.count === 1 ? 'Block' : `${data.count} blocks`} added from ${sourceCount} verified source${sourceCount === 1 ? '' : 's'}`);
    } else {
      toast(data.count === 1 ? 'Block added' : `${data.count} blocks added`);
    }
  } catch (err) {
    state.aiBusy = false;
    syncAskAiRunButton();
    setAskAiStatus(err.message || 'Ask AI failed', 'error');
  }
}

function bindAskAiModal() {
  const modal = document.querySelector('[data-ge-ai-modal]');
  const drop = document.querySelector('[data-ge-ai-drop]');
  const fileInput = document.querySelector('[data-ge-ai-files]');

  document.querySelector('[data-ge-ai-cancel]')?.addEventListener('click', closeAskAiModal);
  document.querySelector('[data-ge-ai-run]')?.addEventListener('click', () => {
    runAskAi().catch((err) => toast(err.message || 'Ask AI failed'));
  });
  document.querySelector('[data-ge-ai-browse]')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    fileInput?.click();
  });
  fileInput?.addEventListener('change', () => {
    addAskAiFiles(fileInput.files).finally(() => {
      fileInput.value = '';
    });
  });

  drop?.addEventListener('click', (e) => {
    if (e.target.closest('[data-ge-ai-browse]')) return;
    fileInput?.click();
  });
  drop?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      fileInput?.click();
    }
  });
  ['dragenter', 'dragover'].forEach((type) => {
    drop?.addEventListener(type, (e) => {
      e.preventDefault();
      drop.classList.add('is-drag');
    });
  });
  ['dragleave', 'drop'].forEach((type) => {
    drop?.addEventListener(type, (e) => {
      e.preventDefault();
      drop.classList.remove('is-drag');
    });
  });
  drop?.addEventListener('drop', (e) => {
    addAskAiFiles(e.dataTransfer?.files);
  });

  document.querySelectorAll('[data-ge-ai-chip]').forEach((chip) => {
    chip.addEventListener('click', () => {
      const prompt = document.querySelector('[data-ge-ai-prompt]');
      const research = document.querySelector('[data-ge-ai-research]');
      if (!prompt) return;
      prompt.value = chip.getAttribute('data-ge-ai-chip') || '';
      if (research && chip.hasAttribute('data-ge-ai-chip-research')) {
        research.checked = chip.getAttribute('data-ge-ai-chip-research') === '1';
      }
      prompt.focus();
    });
  });

  modal?.addEventListener('click', (e) => {
    if (e.target.matches('[data-ge-ai-modal]')) closeAskAiModal();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const aiModal = document.querySelector('[data-ge-ai-modal]');
    if (aiModal && !aiModal.hidden) {
      e.preventDefault();
      closeAskAiModal();
    }
  });
}

function insertBlock(index, type, extra = {}) {
  const block = defaultBlock(type, extra);
  state.blocks.splice(index, 0, block);
  state.selectedIndex = index;
  renderBlocks();
  if (type === 'table') {
    requestAnimationFrame(() => focusTableCell(index, 0, 0));
  } else if (type === 'paragraph' || type === 'heading' || type === 'quote' || type === 'callout') {
    requestAnimationFrame(() => focusBlockEditable(index));
  }
}

function renderBlockWrap(block, index) {
  const wrap = document.createElement('div');
  wrap.className = 'ge-block-wrap' + (state.selectedIndex === index ? ' is-selected' : '');
  wrap.dataset.index = String(index);

  const toolbar = document.createElement('div');
  toolbar.className = 'ge-block-toolbar';
  const delBtn = document.createElement('button');
  delBtn.type = 'button';
  delBtn.className = 'ge-block-toolbar__btn';
  delBtn.title = 'Delete block';
  delBtn.textContent = 'Delete';
  delBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (state.blocks.length <= 1) {
      state.blocks = [defaultBlock('paragraph')];
    } else {
      state.blocks.splice(index, 1);
      if (state.selectedIndex >= index) state.selectedIndex = Math.max(0, state.selectedIndex - 1);
    }
    renderBlocks();
  });
  const upBtn = document.createElement('button');
  upBtn.type = 'button';
  upBtn.className = 'ge-block-toolbar__btn';
  upBtn.title = 'Move up';
  upBtn.textContent = '↑';
  upBtn.disabled = index === 0;
  upBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (index === 0) return;
    [state.blocks[index - 1], state.blocks[index]] = [state.blocks[index], state.blocks[index - 1]];
    state.selectedIndex = index - 1;
    renderBlocks();
  });
  const downBtn = document.createElement('button');
  downBtn.type = 'button';
  downBtn.className = 'ge-block-toolbar__btn';
  downBtn.title = 'Move down';
  downBtn.textContent = '↓';
  downBtn.disabled = index === state.blocks.length - 1;
  downBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (index >= state.blocks.length - 1) return;
    [state.blocks[index], state.blocks[index + 1]] = [state.blocks[index + 1], state.blocks[index]];
    state.selectedIndex = index + 1;
    renderBlocks();
  });
  const copyBtn = document.createElement('button');
  copyBtn.type = 'button';
  copyBtn.className = 'ge-block-toolbar__btn ge-block-toolbar__btn--icon';
  copyBtn.title = 'Copy block';
  copyBtn.setAttribute('aria-label', 'Copy block');
  copyBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10" stroke-linecap="round"/></svg>';
  copyBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    copyGuideBlock(index);
  });
  toolbar.appendChild(upBtn);
  toolbar.appendChild(downBtn);
  toolbar.appendChild(copyBtn);
  toolbar.appendChild(delBtn);
  wrap.appendChild(toolbar);

  const inner = document.createElement('div');
  inner.className = 'ge-block ' + blockClass(block);
  inner.appendChild(renderBlockEditor(block, index));
  wrap.appendChild(inner);

  wrap.addEventListener('click', (e) => {
    if (e.target.closest('[data-ge-image-gear]')) return;
    const fig = e.target.closest('figure.ge-inline-figure');
    if (fig) {
      const img = fig.querySelector('img:not(.ge-image-dropzone__img)');
      if (img) {
        selectInlineImage(img, index);
        return;
      }
    }
    selectBlock(index);
  });

  return wrap;
}

function blockClass(block) {
  if (block.type === 'heading') return `ge-block--heading ge-block--h${block.level || 2}`;
  if (block.type === 'quote') return 'ge-block--quote';
  return `ge-block--${block.type}`;
}

function renderBlockEditor(block, index) {
  if (block.type === 'list') {
    block = listBlockToParagraph(block);
    state.blocks[index] = block;
  }

  const frag = document.createDocumentFragment();

  if (block.type === 'paragraph' || block.type === 'quote' || block.type === 'callout') {
    if (block.type === 'callout') {
      const title = editableField('Callout title', block.title, (v) => { block.title = v; });
      title.classList.add('ge-block__input');
      title.style.marginBottom = '0.5rem';
      frag.appendChild(wrapField('Title', title));
    }
    if (block.type === 'quote') {
      const cite = inputField('Source (optional)', block.cite, (v) => { block.cite = v; });
      frag.appendChild(wrapField('Citation', cite));
    }
    const field = editableField(
      block.type === 'quote' ? 'Quote text' : block.type === 'callout' ? 'Callout text' : 'Start writing…',
      block.text,
      (v) => { block.text = v; updateReadMins(); },
      true,
      index
    );
    frag.appendChild(field);
    return frag;
  }

  if (block.type === 'heading') {
    const row = document.createElement('div');
    row.className = 'ge-block__row';
    const level = document.createElement('select');
    level.className = 'ge-block__input';
    level.style.width = '5.5rem';
    for (let i = 1; i <= 6; i += 1) {
      const opt = document.createElement('option');
      opt.value = String(i);
      opt.textContent = `H${i}`;
      if ((block.level || 2) === i) opt.selected = true;
      level.appendChild(opt);
    }
    level.addEventListener('change', () => {
      block.level = parseInt(level.value, 10);
      renderBlocks();
    });
    row.appendChild(level);
    const field = editableField('Heading', block.text, (v) => { block.text = v; updateReadMins(); }, false, index);
    field.style.flex = '1';
    row.appendChild(field);
    frag.appendChild(row);
    return frag;
  }

  if (block.type === 'image') {
    frag.appendChild(imageBlockFields(block, index));
    return frag;
  }

  if (block.type === 'youtube') {
    frag.appendChild(youtubeBlockFields(block, index));
    return frag;
  }

  if (block.type === 'separator') {
    const div = document.createElement('div');
    div.className = 'ge-separator-preview';
    div.setAttribute('aria-hidden', 'true');
    frag.appendChild(div);
    return frag;
  }

  if (block.type === 'table') {
    frag.appendChild(renderTableBlockEditor(normalizeTableBlock(block), index));
    return frag;
  }

  return frag;
}

function renderTableBlockEditor(block, index) {
  normalizeTableBlock(block);
  const root = document.createElement('div');
  root.className = 'ge-table-block';
  root.dataset.tableBlock = String(index);

  const bar = document.createElement('div');
  bar.className = 'ge-table-block__bar';

  const headerLabel = document.createElement('label');
  headerLabel.className = 'ge-table-block__check';
  const headerCb = document.createElement('input');
  headerCb.type = 'checkbox';
  headerCb.checked = block.header !== false;
  headerCb.addEventListener('change', () => {
    block.header = headerCb.checked;
    refreshTableBlock(index);
  });
  headerLabel.appendChild(headerCb);
  headerLabel.appendChild(document.createTextNode(' Header row'));
  bar.appendChild(headerLabel);

  const stickyLabel = document.createElement('label');
  stickyLabel.className = 'ge-table-block__sticky';
  stickyLabel.appendChild(document.createTextNode('Sticky columns '));
  const stickySel = document.createElement('select');
  stickySel.className = 'ge-block__input ge-table-block__sticky-select';
  const colCount = tableColCount(block);
  const stickyMax = Math.min(6, Math.max(0, colCount - 1));
  for (let n = 0; n <= stickyMax; n += 1) {
    const opt = document.createElement('option');
    opt.value = String(n);
    opt.textContent = n === 0 ? 'None' : n === 1 ? 'First column' : `First ${n} columns`;
    if ((block.stickyCols || 0) === n) opt.selected = true;
    stickySel.appendChild(opt);
  }
  stickySel.addEventListener('change', () => {
    block.stickyCols = parseInt(stickySel.value, 10) || 0;
    refreshTableBlock(index, { keepFocus: true });
  });
  stickyLabel.appendChild(stickySel);
  bar.appendChild(stickyLabel);

  const hint = document.createElement('p');
  hint.className = 'ge-table-block__hint';
  hint.textContent = 'Drag a column or row border to resize. Use + to add rows and columns. Sticky columns stay fixed while you scroll sideways.';
  bar.appendChild(hint);
  root.appendChild(bar);

  const frame = document.createElement('div');
  frame.className = 'ge-table-block__frame';
  root.appendChild(frame);

  const colAdds = document.createElement('div');
  colAdds.className = 'ge-table-block__col-adds';
  const colTrack = Array.from({ length: colCount }, (_, c) => `${tableColWidthPx(block, c)}px`).join(' ');
  colAdds.style.gridTemplateColumns = `2rem ${colTrack} 2rem`;
  colAdds.appendChild(document.createElement('span'));
  for (let c = 0; c <= colCount; c += 1) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ge-table-block__add';
    btn.title = c === 0 ? 'Insert column at start' : c === colCount ? 'Insert column at end' : `Insert column after column ${c}`;
    btn.setAttribute('aria-label', btn.title);
    btn.textContent = '+';
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      insertTableBlockColumn(index, c);
    });
    colAdds.appendChild(btn);
  }
  frame.appendChild(colAdds);

  const body = document.createElement('div');
  body.className = 'ge-table-block__body';
  frame.appendChild(body);

  const rowAdds = document.createElement('div');
  rowAdds.className = 'ge-table-block__row-adds';
  const rowCount = block.rows.length;
  for (let r = 0; r <= rowCount; r += 1) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ge-table-block__add ge-table-block__add--row';
    btn.title = r === 0 ? 'Insert row at start' : r === rowCount ? 'Insert row at end' : `Insert row after row ${r}`;
    btn.setAttribute('aria-label', btn.title);
    btn.textContent = '+';
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      insertTableBlockRow(index, r);
    });
    rowAdds.appendChild(btn);
  }
  body.appendChild(rowAdds);

  const scroll = document.createElement('div');
  scroll.className = 'ge-table-block__scroll';
  const sticky = Math.max(0, Math.min(block.stickyCols || 0, colCount - 1));
  if (sticky > 0) scroll.dataset.stickyCols = String(sticky);

  const table = document.createElement('table');
  table.className = 'ge-table-block__table' + (sticky > 0 ? ` ge-table-block__table--sticky-${sticky}` : '');
  table.dataset.sized = '1';

  const colgroup = document.createElement('colgroup');
  for (let c = 0; c < colCount; c += 1) {
    const col = document.createElement('col');
    col.style.width = `${tableColWidthPx(block, c)}px`;
    colgroup.appendChild(col);
  }
  const toolCol = document.createElement('col');
  toolCol.style.width = '2rem';
  colgroup.appendChild(toolCol);
  table.appendChild(colgroup);

  block.rows.forEach((row, r) => {
    const tr = document.createElement('tr');
    const rowH = tableRowHeightPx(block, r);
    if (rowH > 0) {
      tr.style.height = `${rowH}px`;
    }
    row.forEach((cellHtml, c) => {
      const isHead = block.header && r === 0;
      const cell = document.createElement(isHead ? 'th' : 'td');
      cell.contentEditable = 'true';
      cell.dataset.tableCell = '';
      cell.dataset.r = String(r);
      cell.dataset.c = String(c);
      cell.style.width = `${tableColWidthPx(block, c)}px`;
      cell.style.minWidth = `${tableColWidthPx(block, c)}px`;
      cell.style.maxWidth = `${tableColWidthPx(block, c)}px`;
      if (rowH > 0) {
        cell.style.height = `${rowH}px`;
        cell.style.minHeight = `${rowH}px`;
      }
      cell.innerHTML = cellHtml || '<br>';
      if (sticky > 0 && c < sticky) {
        cell.classList.add('is-sticky');
        cell.style.left = `${stickyLeftPx(block, c)}px`;
      }
      cell.addEventListener('focus', () => {
        activeEditable = cell;
        activeBlockIndex = index;
        state.selectedIndex = index;
        document.querySelectorAll('.ge-block-wrap').forEach((el, i) => {
          el.classList.toggle('is-selected', i === index);
        });
      });
      cell.addEventListener('input', () => {
        const plain = (cell.textContent || '').replace(/\u00a0/g, ' ').trim();
        block.rows[r][c] = plain ? sanitizeInlineHtml(cell.innerHTML) : '';
        updateReadMins();
      });
      cell.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        e.preventDefault();
        const cells = [...table.querySelectorAll('[data-table-cell]')];
        const idx = cells.indexOf(cell);
        const next = e.shiftKey ? cells[idx - 1] : cells[idx + 1];
        if (next) {
          next.focus();
          return;
        }
        if (!e.shiftKey) {
          insertTableBlockRow(index, block.rows.length);
        }
      });
      tr.appendChild(cell);
    });
    const delTd = document.createElement('td');
    delTd.className = 'ge-table-block__row-tools';
    delTd.contentEditable = 'false';
    const delRowBtn = document.createElement('button');
    delRowBtn.type = 'button';
    delRowBtn.className = 'ge-table-block__tool';
    delRowBtn.title = 'Delete row';
    delRowBtn.setAttribute('aria-label', 'Delete row');
    delRowBtn.textContent = '×';
    delRowBtn.disabled = block.rows.length <= 1;
    delRowBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      deleteTableBlockRow(index, r);
    });
    delTd.appendChild(delRowBtn);
    tr.appendChild(delTd);
    table.appendChild(tr);
  });

  const toolRow = document.createElement('tr');
  toolRow.className = 'ge-table-block__col-tools';
  for (let c = 0; c < colCount; c += 1) {
    const td = document.createElement('td');
    td.contentEditable = 'false';
    const delColBtn = document.createElement('button');
    delColBtn.type = 'button';
    delColBtn.className = 'ge-table-block__tool';
    delColBtn.title = 'Delete column';
    delColBtn.setAttribute('aria-label', 'Delete column');
    delColBtn.textContent = '×';
    delColBtn.disabled = colCount <= 1;
    delColBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      deleteTableBlockColumn(index, c);
    });
    td.appendChild(delColBtn);
    toolRow.appendChild(td);
  }
  toolRow.appendChild(document.createElement('td'));
  table.appendChild(toolRow);

  scroll.appendChild(table);
  body.appendChild(scroll);
  bindTableBorderResize(root, scroll, table, block, index);

  return root;
}

function hitTestTableBorder(table, clientX, clientY) {
  const cells = [...table.querySelectorAll('[data-table-cell]')];
  if (!cells.length) return null;

  let bestCol = null;
  let bestColDist = TABLE_EDGE + 1;
  let bestRow = null;
  let bestRowDist = TABLE_EDGE + 1;

  cells.forEach((cell) => {
    const rect = cell.getBoundingClientRect();
    const c = Number(cell.dataset.c);
    const r = Number(cell.dataset.r);
    const distRight = Math.abs(clientX - rect.right);
    const inRowY = clientY >= rect.top - 2 && clientY <= rect.bottom + 2;
    if (inRowY && distRight <= TABLE_EDGE && distRight < bestColDist) {
      bestColDist = distRight;
      bestCol = { type: 'col', index: c };
    }
    const distBottom = Math.abs(clientY - rect.bottom);
    const inColX = clientX >= rect.left - 2 && clientX <= rect.right + 2;
    if (inColX && distBottom <= TABLE_EDGE && distBottom < bestRowDist) {
      bestRowDist = distBottom;
      bestRow = { type: 'row', index: r };
    }
  });

  // Prefer the nearer edge when both are close.
  if (bestCol && bestRow) {
    return bestColDist <= bestRowDist ? bestCol : bestRow;
  }
  return bestCol || bestRow;
}

function applyTableSizesLive(table, block) {
  const cols = tableColCount(block);
  const sticky = Math.max(0, Math.min(block.stickyCols || 0, cols - 1));
  const colEls = [...table.querySelectorAll('colgroup col')];
  for (let c = 0; c < cols; c += 1) {
    const w = `${tableColWidthPx(block, c)}px`;
    if (colEls[c]) colEls[c].style.width = w;
  }
  table.querySelectorAll('[data-table-cell]').forEach((cell) => {
    const c = Number(cell.dataset.c);
    const r = Number(cell.dataset.r);
    const w = tableColWidthPx(block, c);
    const h = tableRowHeightPx(block, r);
    cell.style.width = `${w}px`;
    cell.style.minWidth = `${w}px`;
    cell.style.maxWidth = `${w}px`;
    if (h > 0) {
      cell.style.height = `${h}px`;
      cell.style.minHeight = `${h}px`;
      cell.parentElement.style.height = `${h}px`;
    } else {
      cell.style.height = '';
      cell.style.minHeight = '';
      cell.parentElement.style.height = '';
    }
    if (sticky > 0 && c < sticky) {
      cell.style.left = `${stickyLeftPx(block, c)}px`;
    }
  });
}

function bindTableBorderResize(root, scroll, table, block, index) {
  let drag = null;
  let guide = null;

  const clearGuide = () => {
    guide?.remove();
    guide = null;
  };

  const placeGuide = (type, clientX, clientY) => {
    if (!guide) {
      guide = document.createElement('div');
      guide.className = 'ge-table-block__guide';
      scroll.appendChild(guide);
    }
    const sRect = scroll.getBoundingClientRect();
    guide.classList.toggle('ge-table-block__guide--col', type === 'col');
    guide.classList.toggle('ge-table-block__guide--row', type === 'row');
    if (type === 'col') {
      guide.style.left = `${clientX - sRect.left + scroll.scrollLeft}px`;
      guide.style.top = '0';
      guide.style.width = '2px';
      guide.style.height = `${table.offsetHeight}px`;
    } else {
      guide.style.top = `${clientY - sRect.top + scroll.scrollTop}px`;
      guide.style.left = '0';
      guide.style.height = '2px';
      guide.style.width = `${table.offsetWidth}px`;
    }
  };

  const onMove = (e) => {
    if (!drag) {
      const hit = hitTestTableBorder(table, e.clientX, e.clientY);
      table.style.cursor = hit
        ? (hit.type === 'col' ? 'col-resize' : 'row-resize')
        : '';
      return;
    }
    e.preventDefault();
    if (drag.type === 'col') {
      const next = clampTableCol(drag.startSize + (e.clientX - drag.startPos));
      block.colWidths[drag.index] = next;
      applyTableSizesLive(table, block);
      placeGuide('col', e.clientX, e.clientY);
    } else {
      const next = clampTableRow(drag.startSize + (e.clientY - drag.startPos));
      block.rowHeights[drag.index] = next;
      applyTableSizesLive(table, block);
      placeGuide('row', e.clientX, e.clientY);
    }
  };

  const onUp = () => {
    if (!drag) return;
    const was = drag;
    drag = null;
    root.classList.remove('is-col-resizing', 'is-row-resizing');
    clearGuide();
    table.style.cursor = '';
    window.removeEventListener('mousemove', onMove);
    window.removeEventListener('mouseup', onUp);
    // Re-render once so + column alignment tracks new widths.
    refreshTableBlock(index, {
      keepFocus: true,
      focusR: was.focusR,
      focusC: was.focusC,
    });
  };

  table.addEventListener('mousemove', onMove);
  table.addEventListener('mousedown', (e) => {
    if (e.button !== 0) return;
    if (e.target.closest('.ge-table-block__tool')) return;
    const hit = hitTestTableBorder(table, e.clientX, e.clientY);
    if (!hit) return;
    e.preventDefault();
    e.stopPropagation();
    ensureTableSizeArrays(block);
    const active = document.activeElement;
    drag = {
      type: hit.type,
      index: hit.index,
      startPos: hit.type === 'col' ? e.clientX : e.clientY,
      startSize: hit.type === 'col'
        ? tableColWidthPx(block, hit.index)
        : (tableRowHeightPx(block, hit.index) || (
          table.querySelector(`[data-table-cell][data-r="${hit.index}"]`)?.getBoundingClientRect().height
          || TABLE_ROW_MIN
        )),
      focusR: active?.dataset?.r,
      focusC: active?.dataset?.c,
    };
    if (hit.type === 'col') {
      block.colWidths[hit.index] = drag.startSize;
      root.classList.add('is-col-resizing');
    } else {
      block.rowHeights[hit.index] = drag.startSize;
      root.classList.add('is-row-resizing');
    }
    placeGuide(hit.type, e.clientX, e.clientY);
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
  });

  table.addEventListener('mouseleave', () => {
    if (!drag) table.style.cursor = '';
  });
}

function refreshTableBlock(index, opts = {}) {
  const block = state.blocks[index];
  if (!block || block.type !== 'table') return;
  const active = document.activeElement;
  const focusR = opts.focusR != null ? opts.focusR : active?.dataset?.r;
  const focusC = opts.focusC != null ? opts.focusC : active?.dataset?.c;
  renderBlocks();
  if (opts.keepFocus && focusR != null && focusC != null) {
    requestAnimationFrame(() => focusTableCell(index, Number(focusR), Number(focusC)));
  }
}

function insertTableBlockRow(index, at) {
  const block = normalizeTableBlock(state.blocks[index]);
  if (!block || block.type !== 'table') return;
  const cols = tableColCount(block);
  const pos = Math.max(0, Math.min(block.rows.length, at));
  block.rows.splice(pos, 0, Array.from({ length: cols }, () => ''));
  if (!Array.isArray(block.rowHeights)) block.rowHeights = [];
  block.rowHeights.splice(pos, 0, 0);
  ensureTableSizeArrays(block);
  state.selectedIndex = index;
  renderBlocks();
  requestAnimationFrame(() => focusTableCell(index, pos, 0));
}

function insertTableBlockColumn(index, at) {
  const block = normalizeTableBlock(state.blocks[index]);
  if (!block || block.type !== 'table') return;
  const pos = Math.max(0, Math.min(tableColCount(block), at));
  block.rows.forEach((row) => {
    row.splice(pos, 0, '');
  });
  if (!Array.isArray(block.colWidths)) block.colWidths = [];
  block.colWidths.splice(pos, 0, 0);
  ensureTableSizeArrays(block);
  if ((block.stickyCols || 0) > tableColCount(block) - 1) {
    block.stickyCols = Math.max(0, tableColCount(block) - 1);
  }
  state.selectedIndex = index;
  renderBlocks();
  requestAnimationFrame(() => focusTableCell(index, 0, pos));
}

function deleteTableBlockRow(index, rowIndex) {
  const block = normalizeTableBlock(state.blocks[index]);
  if (!block || block.type !== 'table' || block.rows.length <= 1) return;
  block.rows.splice(rowIndex, 1);
  if (Array.isArray(block.rowHeights)) block.rowHeights.splice(rowIndex, 1);
  ensureTableSizeArrays(block);
  state.selectedIndex = index;
  renderBlocks();
}

function deleteTableBlockColumn(index, colIndex) {
  const block = normalizeTableBlock(state.blocks[index]);
  if (!block || block.type !== 'table' || tableColCount(block) <= 1) return;
  block.rows.forEach((row) => {
    row.splice(colIndex, 1);
  });
  if (Array.isArray(block.colWidths)) block.colWidths.splice(colIndex, 1);
  ensureTableSizeArrays(block);
  if ((block.stickyCols || 0) > tableColCount(block) - 1) {
    block.stickyCols = Math.max(0, tableColCount(block) - 1);
  }
  state.selectedIndex = index;
  renderBlocks();
}

function wrapField(label, el) {
  const wrap = document.createElement('div');
  wrap.className = 'ge-field';
  const l = document.createElement('label');
  l.textContent = label;
  wrap.appendChild(l);
  wrap.appendChild(el);
  return wrap;
}

function editableField(placeholder, value, onInput, rich = false, blockIndex = -1) {
  const el = document.createElement('div');
  el.contentEditable = 'true';
  el.dataset.placeholder = placeholder;
  el.innerHTML = value || '';
  decorateInlineImages(el, blockIndex);

  el.addEventListener('focus', () => {
    activeEditable = el;
    activeBlockIndex = blockIndex;
    if (blockIndex >= 0) {
      state.selectedIndex = blockIndex;
      document.querySelectorAll('.ge-block-wrap').forEach((wrap, i) => {
        wrap.classList.toggle('is-selected', i === blockIndex);
      });
      syncBlockFormatSelect();
      syncImageSidebar();
    }
  });
  el.addEventListener('input', () => {
    if (blockIndex >= 0 && salvageGuideBlockFromTextValue(el.innerText || el.textContent || '', blockIndex)) {
      return;
    }
    onInput(el.innerHTML);
    updateContinueZone();
  });
  el.addEventListener('click', (e) => {
    if (e.target.closest('[data-ge-image-gear]')) return;
    const anchor = e.target.closest('a');
    if (anchor && el.contains(anchor)) {
      e.preventDefault();
      openLinkModalFromAnchor(anchor, el, blockIndex);
      return;
    }
    if (state.inlineImageId && !e.target.closest('figure.ge-inline-figure')) {
      clearInlineImageSelection();
      syncImageSidebar();
    }
  });
  el.addEventListener('paste', (e) => handleEditablePaste(e, blockIndex, onInput, rich));
  return el;
}

function extractInlinePasteHtml(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const blocks = [...doc.body.querySelectorAll('p, div')];
  if (blocks.length === 1) {
    const el = blocks[0];
    const nestedBlock = el.querySelector('ul, ol, img, table, blockquote, h1, h2, h3, h4, h5, h6');
    if (!nestedBlock) return el.innerHTML;
  }
  return html;
}

function shouldPasteAsBlocks(html, rich) {
  if (!html || !isStructuredHtml(html)) return false;
  if (!rich) return true;

  const doc = new DOMParser().parseFromString(html, 'text/html');
  const blocks = [...doc.body.querySelectorAll('p, ul, ol, h1, h2, h3, h4, h5, h6, blockquote, hr, img, table')];
  if (blocks.length === 0) return false;
  if (blocks.length > 1) return true;

  const only = blocks[0];
  if (only.tagName === 'UL' || only.tagName === 'OL' || only.tagName === 'TABLE') return true;
  if (/^H[1-6]$/.test(only.tagName) || only.tagName === 'BLOCKQUOTE' || only.tagName === 'HR' || only.tagName === 'IMG') {
    return true;
  }
  if (only.tagName === 'P') {
    return Boolean(only.querySelector('ul, ol, img, table, blockquote, h1, h2, h3, h4, h5, h6'));
  }
  return false;
}

function handleEditablePaste(e, blockIndex, onInput, rich = false) {
  // Prefer the live clipboard. Images must win over any previously copied block.
  const clipboardFile = getClipboardImageFile(e);
  if (clipboardFile) {
    clearStoredGuideBlockClipboard();
    e.preventDefault();
    pasteInlineImageFile(clipboardFile, onInput);
    return;
  }

  const items = e.clipboardData?.items;
  if (items) {
    for (const item of items) {
      if (item.type.startsWith('image/')) {
        clearStoredGuideBlockClipboard();
        e.preventDefault();
        const file = item.getAsFile();
        if (file) pasteInlineImageFile(file, onInput);
        return;
      }
    }
  }

  if (tryPasteGuideBlockFromEvent(e, blockIndex >= 0 ? blockIndex : state.selectedIndex)) {
    e.preventDefault();
    e.stopPropagation();
    return;
  }

  // A normal paste replaces any stored guide-block copy.
  clearStoredGuideBlockClipboard();

  const html = e.clipboardData?.getData('text/html') || '';
  const plain = e.clipboardData?.getData('text/plain') || '';

  if (html && shouldPasteAsBlocks(html, rich)) {
    e.preventDefault();
    const newBlocks = htmlToBlocks(html);
    if (blockIndex >= 0) {
      insertBlocksAt(blockIndex, newBlocks);
    }
    return;
  }

  if (plain && looksLikePlainList(plain) && !rich) {
    e.preventDefault();
    const newBlocks = plainTextToBlocks(plain);
    if (blockIndex >= 0) {
      insertBlocksAt(blockIndex, newBlocks);
    }
    return;
  }

  if (html) {
    const imgMatch = html.match(/<img[^>]+src=["']([^"']+)["']/i);
    if (imgMatch?.[1]) {
      e.preventDefault();
      document.execCommand('insertHTML', false, `<img src="${escAttr(imgMatch[1])}" alt="">`);
      onInput(elInnerHtmlFromEventTarget(e));
      if (activeEditable) {
        decorateInlineImages(activeEditable, blockIndex);
        onInput(activeEditable.innerHTML);
      }
      return;
    }
  }

  const imageUrl = extractImageUrl(plain);
  if (imageUrl) {
    e.preventDefault();
    document.execCommand('insertHTML', false, `<img src="${escAttr(imageUrl)}" alt="">`);
    onInput(elInnerHtmlFromEventTarget(e));
    if (activeEditable) {
      decorateInlineImages(activeEditable, blockIndex);
      onInput(activeEditable.innerHTML);
    }
    return;
  }

  if (html && rich) {
    e.preventDefault();
    const clean = sanitizeInlineHtml(extractInlinePasteHtml(html));
    document.execCommand('insertHTML', false, clean);
    if (activeEditable) onInput(activeEditable.innerHTML);
    return;
  }
}

function elInnerHtmlFromEventTarget(e) {
  if (activeEditable) return activeEditable.innerHTML;
  const el = e.target;
  return el && el.innerHTML ? el.innerHTML : '';
}

function inputField(placeholder, value, onInput) {
  const el = document.createElement('input');
  el.type = 'text';
  el.className = 'ge-block__input';
  el.placeholder = placeholder;
  el.value = value || '';
  el.addEventListener('input', () => onInput(el.value));
  return el;
}

function getClipboardImageFile(e) {
  const dt = e.clipboardData;
  if (!dt) return null;

  if (dt.items) {
    for (const item of dt.items) {
      if (item.type.startsWith('image/')) {
        const file = item.getAsFile();
        if (file) return file;
      }
    }
  }

  if (dt.files?.length) {
    for (const file of dt.files) {
      if (file.type.startsWith('image/')) return file;
    }
  }

  return null;
}

function shouldIgnoreGlobalImagePaste() {
  const el = document.activeElement;
  if (!el) return false;
  if (el.matches('[data-ge-title]')) return true;
  if (el.closest('.ge-sidebar')) return true;
  if (el.closest('[data-ge-link-modal]:not([hidden])')) return true;
  if (el.matches('[contenteditable="true"]')) return true;
  return false;
}

function resolveImagePasteTargetIndex() {
  const focusedBlock = document.activeElement?.closest('.ge-block-wrap');
  if (focusedBlock?.dataset.index != null) {
    const i = parseInt(focusedBlock.dataset.index, 10);
    if (state.blocks[i]?.type === 'image') return i;
  }
  if (state.selectedIndex >= 0 && state.blocks[state.selectedIndex]?.type === 'image') {
    return state.selectedIndex;
  }
  return state.selectedIndex;
}

async function ingestImageFile(file) {
  if (!file || !file.type.startsWith('image/')) return;

  const url = await uploadImage(file);
  const targetIdx = resolveImagePasteTargetIndex();
  const selected = targetIdx >= 0 ? state.blocks[targetIdx] : null;

  if (selected?.type === 'image') {
    selected.url = url;
    renderBlocks();
    toast('Image added');
    return;
  }

  const insertAt = targetIdx >= 0 ? targetIdx + 1 : state.blocks.length;
  const block = defaultBlock('image');
  block.url = url;
  state.blocks.splice(insertAt, 0, block);
  state.selectedIndex = insertAt;
  renderBlocks();
  toast('Image added');
}

function imageBlockFields(block, index) {
  const wrap = document.createElement('div');
  wrap.className = 'ge-image-block';

  const file = document.createElement('input');
  file.type = 'file';
  file.accept = 'image/*';
  file.hidden = true;

  const figure = document.createElement('figure');
  figure.className = 'ge-image-figure';

  const dropzone = document.createElement('div');
  dropzone.className = 'ge-image-dropzone';
  dropzone.tabIndex = 0;
  dropzone.setAttribute('role', 'button');
  dropzone.setAttribute('aria-label', block.url ? 'Replace image' : 'Add image');

  const syncDropzone = () => {
    dropzone.classList.toggle('has-image', Boolean(block.url));
    dropzone.setAttribute('aria-label', block.url ? 'Replace image' : 'Add image');

    if (block.url) {
      dropzone.innerHTML = `
        ${imageBlockGearHtml()}
        <img class="ge-image-dropzone__img" src="${esc(block.url)}" alt="${escAttr(block.alt || '')}">
        <div class="ge-image-dropzone__overlay" aria-hidden="true">
          <span class="ge-image-dropzone__overlay-text">Replace image</span>
        </div>
      `;
      return;
    }

    dropzone.innerHTML = `
      <div class="ge-image-dropzone__empty">
        <span class="ge-image-dropzone__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="3" y="5" width="18" height="14" rx="2"/>
            <circle cx="9" cy="10" r="1.5" fill="currentColor" stroke="none"/>
            <path d="m3 17 5.5-5.5 3 3 3.5-4.5 6 7"/>
          </svg>
        </span>
        <span class="ge-image-dropzone__title">Add an image</span>
        <span class="ge-image-dropzone__hint">Drop a file here, paste with ⌘V, or click to browse</span>
      </div>
    `;
  };

  const setUploading = (busy) => {
    dropzone.classList.toggle('is-uploading', busy);
    dropzone.setAttribute('aria-busy', busy ? 'true' : 'false');
  };

  const applyFile = async (picked) => {
    if (!picked || !picked.type.startsWith('image/')) return;
    setUploading(true);
    try {
      block.url = await uploadImage(picked);
      syncDropzone();
      selectBlock(index);
      if (!wrap.querySelector('.ge-image-actions')) {
        const actions = document.createElement('div');
        actions.className = 'ge-image-actions';
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'adm-btn-sm';
        removeBtn.textContent = 'Remove image';
        removeBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          block.url = '';
          syncDropzone();
          syncImageSidebar();
          actions.remove();
        });
        actions.appendChild(removeBtn);
        wrap.appendChild(actions);
      }
      syncImageSidebar();
      toast('Image uploaded');
    } catch (err) {
      toast(err.message || 'Upload failed');
    } finally {
      setUploading(false);
      file.value = '';
    }
  };

  dropzone.addEventListener('mousedown', (e) => {
    e.stopPropagation();
    selectBlock(index);
  });

  dropzone.addEventListener('click', (e) => {
    e.stopPropagation();
    if (e.target.closest('[data-ge-image-gear]')) return;
    selectBlock(index);
    if (!block.url) {
      file.click();
      return;
    }
    if (e.target.closest('.ge-image-dropzone__overlay, .ge-image-dropzone__overlay-text')) {
      file.click();
    }
  });
  dropzone.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      file.click();
    }
  });
  dropzone.addEventListener('paste', (e) => {
    const picked = getClipboardImageFile(e);
    if (!picked) return;
    e.preventDefault();
    applyFile(picked);
  });
  dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.classList.add('is-dragover');
  });
  dropzone.addEventListener('dragleave', () => {
    dropzone.classList.remove('is-dragover');
  });
  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('is-dragover');
    const picked = e.dataTransfer?.files?.[0];
    applyFile(picked);
  });
  file.addEventListener('change', () => applyFile(file.files?.[0]));

  wrap.addEventListener('paste', (e) => {
    const picked = getClipboardImageFile(e);
    if (!picked) return;
    e.preventDefault();
    applyFile(picked);
  });

  syncDropzone();
  figure.appendChild(dropzone);

  if ((block.caption || '').trim()) {
    const captionEl = document.createElement('figcaption');
    captionEl.className = 'ge-image-caption';
    captionEl.dataset.geImageCaptionPreview = '';
    captionEl.textContent = block.caption.trim();
    figure.appendChild(captionEl);
  }

  wrap.appendChild(figure);
  wrap.appendChild(file);

  if (block.url) {
    const actions = document.createElement('div');
    actions.className = 'ge-image-actions';
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'adm-btn-sm';
    removeBtn.textContent = 'Remove image';
    removeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      block.url = '';
      syncDropzone();
      syncImageSidebar();
      actions.remove();
    });
    actions.appendChild(removeBtn);
    wrap.appendChild(actions);
  }

  return wrap;
}

function youtubeBlockFields(block) {
  const wrap = document.createElement('div');
  wrap.innerHTML = `<span class="ge-block__label">YouTube</span>`;
  const url = inputField('YouTube URL', block.url, (v) => {
    block.url = v;
    block.videoId = youtubeId(v);
    renderBlocks();
  });
  const caption = inputField('Caption (optional)', block.caption, (v) => { block.caption = v; });
  wrap.appendChild(url);
  wrap.appendChild(caption);
  const id = block.videoId || youtubeId(block.url);
  if (id) {
    const preview = document.createElement('div');
    preview.className = 'ge-block__preview';
    preview.innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${esc(id)}" title="YouTube preview" loading="lazy" allowfullscreen></iframe>`;
    wrap.appendChild(preview);
  }
  return wrap;
}

function extractImageUrl(text) {
  const t = text.trim();
  if (/^https?:\/\/.+\.(png|jpe?g|gif|webp)(\?.*)?$/i.test(t)) return t;
  if (/^https?:\/\//.test(t) && /\.(png|jpe?g|gif|webp)/i.test(t)) return t;
  return '';
}

async function uploadImage(file) {
  const fd = new FormData();
  fd.append('image', file);
  const res = await fetch('/api/admin/guide-upload.php', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
  });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Upload failed');
  return data.url;
}

function collectBlocksFromDom() {
  return normalizeBlocks(state.blocks);
}

async function save(status) {
  if (state.saving) return;
  persistInlineEditableHtml();
  // Normalize any tables in paragraph HTML before save.
  state.blocks.forEach((block) => {
    if (!block || (block.type !== 'paragraph' && block.type !== 'callout')) return;
    if (!block.text || !/<table\b/i.test(block.text)) return;
    const doc = new DOMParser().parseFromString(`<div>${block.text}</div>`, 'text/html');
    const root = doc.body.firstElementChild;
    if (!root) return;
    const parts = [];
    [...root.childNodes].forEach((node) => {
      if (node.nodeType === Node.ELEMENT_NODE && node.tagName === 'TABLE') {
        const html = serializeGuideTable(node);
        if (html) parts.push(html);
        return;
      }
      if (node.nodeType === Node.ELEMENT_NODE && node.classList?.contains('fg-table-wrap')) {
        const table = node.querySelector('table');
        const html = table ? serializeGuideTable(table) : '';
        if (html) parts.push(html);
        return;
      }
      if (node.nodeType === Node.ELEMENT_NODE) {
        parts.push(node.outerHTML);
        return;
      }
      if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
        parts.push(node.textContent);
      }
    });
    if (parts.length) block.text = parts.join('');
  });
  state.saving = true;
  toast('Saving…');

  const payload = {
    id: state.id || undefined,
    title: state.title.trim(),
    slug: state.slug.trim(),
    description: state.description.trim(),
    category: state.category,
    schema_type: state.schema_type,
    featured_image: state.featured_image.trim(),
    status,
    blocks: collectBlocksFromDom(),
  };

  try {
    const data = await admFetch('guides.php', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    state.id = data.post.id;
    state.status = data.post.status;
    document.querySelector('[data-ge-status]').textContent = state.status === 'published' ? 'Published' : 'Draft';
    if (!params.get('id') && state.id) {
      history.replaceState(null, '', `?id=${state.id}`);
    }
    toast(status === 'published' ? 'Published to FPL Guides' : 'Draft saved');
  } catch (err) {
    toast(err.message || 'Save failed');
  } finally {
    state.saving = false;
  }
}

function toast(message) {
  const el = document.querySelector('[data-ge-toast]');
  if (!el) return;
  el.textContent = message;
  el.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { el.hidden = true; }, 2800);
}

document.addEventListener('paste', (e) => {
  // Live image clipboard always wins over a previously copied guide block.
  if (!shouldIgnoreGlobalImagePaste()) {
    const file = getClipboardImageFile(e);
    if (file) {
      clearStoredGuideBlockClipboard();
      e.preventDefault();
      e.stopImmediatePropagation();
      ingestImageFile(file).catch((err) => toast(err.message || 'Upload failed'));
      return;
    }
  }

  const targetIndex = (() => {
    const cell = e.target?.closest?.('[data-table-cell]');
    if (cell) {
      const wrap = cell.closest('.ge-block-wrap');
      if (wrap?.dataset?.index != null) return parseInt(wrap.dataset.index, 10);
    }
    const editable = e.target?.closest?.('[contenteditable="true"]');
    const wrap = editable?.closest?.('.ge-block-wrap');
    if (wrap?.dataset?.index != null) return parseInt(wrap.dataset.index, 10);
    return state.selectedIndex;
  })();

  if (tryPasteGuideBlockFromEvent(e, targetIndex)) {
    e.preventDefault();
    e.stopImmediatePropagation();
    return;
  }

  // Any other paste means the old stored block should no longer hijack Ctrl+V.
  if (!clipboardHasImagePayload(e)) {
    const plain = e.clipboardData?.getData('text/plain') || '';
    const html = e.clipboardData?.getData('text/html') || '';
    if (plain || html) clearStoredGuideBlockClipboard();
  }
}, true);

boot().catch((err) => {
  console.error(err);
  toast(err.message || 'Could not load editor');
});
