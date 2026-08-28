/**
 * Shared sticky sidebar offset — keeps TOC asides below the site header on desktop.
 */

let started = false;

const ASIDE_SELECTORS = [
  '.privacy-aside',
  '.terms-aside',
  '.fin-aside',
  '.fin-layout__aside',
  '.fin-layout__toc',
  '.report-aside',
  '.gwr-aside',
  '.pcp-aside',
  '.lt-aside',
  '[data-report-aside]',
];

export function syncHeaderHeight() {
  const header = document.querySelector('[data-header]');
  if (!header) return;
  document.documentElement.style.setProperty('--header-height', `${header.offsetHeight}px`);
}

function stickyTopValue() {
  const root = document.documentElement;
  const header = root.style.getPropertyValue('--header-height')
    || getComputedStyle(root).getPropertyValue('--header-height')
    || '5.5rem';
  const gap = getComputedStyle(root).getPropertyValue('--sticky-sidebar-gap') || '1.25rem';
  return `calc(${header.trim()} + ${gap.trim()})`;
}

function applyStickyAsideOffsets() {
  if (!window.matchMedia('(min-width: 960px)').matches) return;

  syncHeaderHeight();
  const top = stickyTopValue();

  ASIDE_SELECTORS.forEach((selector) => {
    document.querySelectorAll(selector).forEach((el) => {
      el.style.top = top;
      el.style.zIndex = '20';
      el.style.alignSelf = 'start';
    });
  });

  document.querySelectorAll('[data-fin-toc]').forEach((nav) => {
    const aside = nav.closest('aside') || nav.closest('.fin-aside, .fin-layout__aside, .fin-layout__toc');
    if (aside instanceof HTMLElement) {
      aside.style.top = top;
      aside.style.zIndex = '20';
      aside.style.alignSelf = 'start';
    }
  });

  document.querySelectorAll('.privacy-toc, .terms-toc, .fin-toc').forEach((toc) => {
    const aside = toc.closest('aside');
    if (aside instanceof HTMLElement) {
      aside.style.top = top;
      aside.style.zIndex = '20';
      aside.style.alignSelf = 'start';
    }
  });
}

function ensureStickyAsideStyles() {
  if (document.getElementById('mf-sticky-aside-fix')) return;

  const style = document.createElement('style');
  style.id = 'mf-sticky-aside-fix';
  style.textContent = `
    :root { --sticky-sidebar-gap: 1.25rem; }

    @media (min-width: 960px) {
      .privacy-aside,
      .terms-aside,
      .fin-aside,
      .fin-layout__aside,
      .fin-layout__toc,
      .report-aside,
      .gwr-aside,
      .pcp-aside,
      .lt-aside,
      aside:has([data-fin-toc]),
      aside:has(.fin-toc__title),
      aside:has(.privacy-toc),
      aside:has(.terms-toc),
      [data-report-aside] {
        top: calc(var(--header-height, 5.5rem) + var(--sticky-sidebar-gap, 1.25rem)) !important;
        z-index: 20;
        align-self: start;
      }

      .privacy-jump,
      .terms-jump,
      .fin-jump {
        top: calc(var(--header-height, 5.5rem) + 0.15rem);
      }

      .privacy-section,
      .terms-section,
      .fin-section,
      .fin-player,
      .gwr-section,
      [data-fin-section] {
        scroll-margin-top: calc(var(--header-height, 5.5rem) + var(--sticky-sidebar-gap, 1.25rem) + 0.5rem);
      }
    }
  `;
  document.head.appendChild(style);
}

function watchFinToc() {
  if (typeof MutationObserver === 'undefined') return;

  const observer = new MutationObserver(() => {
    applyStickyAsideOffsets();
  });

  document.querySelectorAll('[data-fin-toc]').forEach((node) => {
    observer.observe(node, { childList: true, subtree: true });
  });
}

export function initStickyAsideOffset() {
  if (started) return;
  started = true;

  document.documentElement.style.setProperty('--sticky-sidebar-gap', '1.25rem');
  ensureStickyAsideStyles();
  applyStickyAsideOffsets();
  watchFinToc();

  window.addEventListener('resize', applyStickyAsideOffsets, { passive: true });
  window.addEventListener('load', applyStickyAsideOffsets, { passive: true });

  const header = document.querySelector('[data-header]');
  if (header && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(applyStickyAsideOffsets).observe(header);
  }
}
