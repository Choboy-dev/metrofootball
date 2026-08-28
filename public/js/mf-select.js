import { animate, stagger } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const CHECK_SVG = `<svg class="mf-select__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M5 12.5 9.5 17 19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
const CHEVRON_SVG = `<svg class="mf-select__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

let openRoot = null;

function optionEls(root) {
  return [...root.querySelectorAll('[data-mf-select-option]')];
}

export function getMfSelectValue(root) {
  return root?.dataset?.value ?? '';
}

export function setMfSelectValue(root, value, { silent = false } = {}) {
  if (!root) return;
  const next = String(value ?? '');
  const options = optionEls(root);
  const match = options.find((opt) => opt.dataset.value === next) || options[0];
  if (!match) return;

  const resolved = match.dataset.value || '';
  const label = match.dataset.label || match.childNodes[0]?.textContent?.trim() || match.textContent.trim();
  root.dataset.value = resolved;

  const valueEl = root.querySelector('[data-mf-select-value]');
  if (valueEl) valueEl.textContent = label;

  options.forEach((opt) => {
    const selected = opt === match;
    opt.classList.toggle('is-selected', selected);
    opt.setAttribute('aria-selected', selected ? 'true' : 'false');
  });

  if (!silent) {
    root.dispatchEvent(new CustomEvent('mf-select:change', {
      bubbles: true,
      detail: {
        value: resolved,
        label,
      },
    }));
  }
}

function clampPanelHeight(root) {
  const containSel = root?.dataset?.mfSelectContain;
  if (!containSel) return;

  const container = root.closest(containSel);
  const trigger = root.querySelector('[data-mf-select-trigger]');
  const panel = root.querySelector('[data-mf-select-panel]');
  const list = root.querySelector('[data-mf-select-list]');
  const scrollEl = list || panel;
  if (!container || !trigger || !scrollEl) return;

  scrollEl.style.maxHeight = '';

  const containerRect = container.getBoundingClientRect();
  const triggerRect = trigger.getBoundingClientRect();
  const gap = 8;
  const buffer = 12;
  const available = containerRect.bottom - triggerRect.bottom - gap - buffer;

  if (available > 0) {
    scrollEl.style.maxHeight = `${Math.max(88, Math.floor(available))}px`;
  }
}

function closeSelect(root, { focusTrigger = false } = {}) {
  if (!root || !root.classList.contains('is-open')) return;
  const trigger = root.querySelector('[data-mf-select-trigger]');
  const panel = root.querySelector('[data-mf-select-panel]');
  root.classList.remove('is-open');
  if (trigger) trigger.setAttribute('aria-expanded', 'false');
  if (panel) {
    panel.hidden = true;
    panel.setAttribute('aria-hidden', 'true');
    panel.style.maxHeight = '';
    const list = root.querySelector('[data-mf-select-list]');
    if (list) list.style.maxHeight = '';
  }
  if (openRoot === root) openRoot = null;
  if (focusTrigger && trigger) trigger.focus();
}

function openSelect(root) {
  if (!root || root.classList.contains('is-open')) return;
  if (openRoot && openRoot !== root) closeSelect(openRoot);

  const trigger = root.querySelector('[data-mf-select-trigger]');
  const panel = root.querySelector('[data-mf-select-panel]');
  const options = optionEls(root);
  if (!trigger || !panel || !options.length) return;

  root.classList.add('is-open');
  trigger.setAttribute('aria-expanded', 'true');
  panel.hidden = false;
  panel.removeAttribute('hidden');
  panel.setAttribute('aria-hidden', 'false');
  panel.style.opacity = '';
  panel.style.transform = '';
  options.forEach((opt) => {
    opt.style.opacity = '';
    opt.style.transform = '';
  });
  clampPanelHeight(root);
  openRoot = root;

  const skipMotion = reduceMotion || Boolean(root.closest('.contact-form'));

  if (!skipMotion) {
    try {
      animate(panel, { y: [-6, 0] }, { duration: 0.2, easing: [0.17, 0.55, 0.55, 1] });
      animate(
        options,
        { y: [6, 0] },
        {
          duration: 0.24,
          delay: stagger(0.025, { startDelay: 0.03 }),
          easing: [0.17, 0.55, 0.55, 1],
        }
      );
    } catch {
      // Menu stays visible even if Motion fails
    }
  }

  const selected = options.find((opt) => opt.classList.contains('is-selected')) || options[0];
  window.requestAnimationFrame(() => {
    clampPanelHeight(root);
    selected?.focus({ preventScroll: true });
  });
}

function toggleSelect(root) {
  if (root.classList.contains('is-open')) closeSelect(root);
  else openSelect(root);
}

function focusOption(root, index) {
  const options = optionEls(root);
  if (!options.length) return;
  const next = Math.max(0, Math.min(options.length - 1, index));
  options[next].focus();
}

function bindRoot(root) {
  if (root.dataset.mfSelectReady === '1') return;
  root.dataset.mfSelectReady = '1';

  const trigger = root.querySelector('[data-mf-select-trigger]');
  const panel = root.querySelector('[data-mf-select-panel]');
  const options = optionEls(root);
  if (!trigger || !panel || !options.length) return;

  const listboxId = root.id ? `${root.id}-listbox` : `mf-select-${Math.random().toString(36).slice(2, 9)}`;
  panel.id = listboxId;
  panel.setAttribute('role', 'listbox');
  panel.setAttribute('tabindex', '-1');
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-controls', listboxId);
  trigger.setAttribute('aria-expanded', 'false');

  if (!trigger.querySelector('.mf-select__chevron')) {
    trigger.insertAdjacentHTML('beforeend', CHEVRON_SVG);
  }

  options.forEach((opt) => {
    opt.setAttribute('role', 'option');
    opt.setAttribute('tabindex', '-1');
    if (!opt.querySelector('.mf-select__check')) {
      opt.insertAdjacentHTML('beforeend', CHECK_SVG);
    }
  });

  const initial = root.dataset.value
    || options.find((opt) => opt.classList.contains('is-selected'))?.dataset.value
    || options[0].dataset.value;
  setMfSelectValue(root, initial, { silent: true });

  trigger.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    toggleSelect(root);
  });

  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      event.stopPropagation();
      openSelect(root);
    }
  });

  panel.addEventListener('click', (event) => {
    event.stopPropagation();
  });

  panel.addEventListener('keydown', (event) => {
    const optionsNow = optionEls(root);
    const current = optionsNow.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      closeSelect(root, { focusTrigger: true });
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      focusOption(root, current < 0 ? 0 : current + 1);
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      focusOption(root, current < 0 ? optionsNow.length - 1 : current - 1);
      return;
    }
    if (event.key === 'Home') {
      event.preventDefault();
      focusOption(root, 0);
      return;
    }
    if (event.key === 'End') {
      event.preventDefault();
      focusOption(root, optionsNow.length - 1);
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      const active = document.activeElement;
      if (active?.matches?.('[data-mf-select-option]')) {
        setMfSelectValue(root, active.dataset.value);
        closeSelect(root, { focusTrigger: true });
      }
    }
  });

  options.forEach((opt) => {
    opt.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setMfSelectValue(root, opt.dataset.value);
      closeSelect(root, { focusTrigger: true });
    });
  });
}

export function initMfSelects(scope = document) {
  scope.querySelectorAll('[data-mf-select]').forEach(bindRoot);
}

if (!window.__mfSelectResizeBound) {
  window.__mfSelectResizeBound = true;
  window.addEventListener('resize', () => {
    if (openRoot) clampPanelHeight(openRoot);
  });
}

if (!window.__mfSelectDocBound) {
  window.__mfSelectDocBound = true;
  document.addEventListener('pointerdown', (event) => {
    if (!openRoot) return;
    if (openRoot.contains(event.target)) return;
    closeSelect(openRoot);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && openRoot) {
      closeSelect(openRoot, { focusTrigger: true });
    }
  });
}
