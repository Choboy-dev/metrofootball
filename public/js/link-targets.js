/**
 * Open most in-app links in a new tab.
 * Keep auth, upgrade, and similar account flows in the same tab.
 */

const SAME_TAB_PATH =
  /(?:^|\/)(login|join|register|signup|sign-up|signin|sign-in|pricing|payment|billing|checkout|verify-otp|verify-email|verify|link-team|fixture-difficulty)(?:\/|$|\.html|(?=[?#]))/i;

let started = false;

function shouldOpenSameTab(anchor) {
  if (!(anchor instanceof HTMLAnchorElement)) return true;
  if (anchor.hasAttribute('data-same-tab')) return true;
  if (anchor.closest('[data-same-tab-root]')) return true;
  if (anchor.hasAttribute('download')) return true;

  const raw = (anchor.getAttribute('href') || '').trim();
  if (!raw || raw === '#') return true;
  if (raw.startsWith('#') || raw.startsWith('javascript:') || raw.startsWith('mailto:') || raw.startsWith('tel:')) {
    return true;
  }

  try {
    const url = new URL(raw, window.location.href);
    const path = url.pathname.toLowerCase();

    // Same-page section anchors must stay in this tab (TOC, in-article jumps).
    if (
      url.origin === window.location.origin
      && url.hash
      && url.pathname.replace(/\/+$/, '') === window.location.pathname.replace(/\/+$/, '')
    ) {
      return true;
    }

    if (SAME_TAB_PATH.test(path) || SAME_TAB_PATH.test(raw.toLowerCase())) {
      return true;
    }
  } catch {
    return true;
  }

  return false;
}

export function applyLinkTargets(root = document) {
  const scope = root instanceof Element || root instanceof Document ? root : document;
  scope.querySelectorAll('a[href]').forEach((anchor) => {
    if (shouldOpenSameTab(anchor)) return;
    anchor.setAttribute('target', '_blank');
    const rel = new Set(String(anchor.getAttribute('rel') || '').split(/\s+/).filter(Boolean));
    rel.add('noopener');
    rel.add('noreferrer');
    anchor.setAttribute('rel', [...rel].join(' '));
  });
}

export function initLinkTargets() {
  applyLinkTargets(document);

  if (started) return;
  started = true;

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) return;
        if (node.matches('a[href]')) {
          applyLinkTargets(node.parentElement || document);
          return;
        }
        if (node.querySelectorAll) {
          applyLinkTargets(node);
        }
      });
    }
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initLinkTargets(), { once: true });
  } else {
    initLinkTargets();
  }
}
