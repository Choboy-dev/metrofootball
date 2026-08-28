/**
 * Shared auth helpers for Metro Football
 */

import { initLinkTargets } from './link-targets.js';

initLinkTargets();

const IDLE_EVENTS = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click', 'wheel'];

let idleTimerId = null;
let idleMs = 60 * 60 * 1000;
let idleArmed = false;
let idleLoggingOut = false;
let lastActivityAt = Date.now();
let sessionCache = null;
let sessionFetchPromise = null;
let pageBootstrapping = false;

function sleep(ms) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

export function clearSessionCache() {
  sessionCache = null;
  sessionFetchPromise = null;
}

export function setPageBootstrapping(active) {
  pageBootstrapping = Boolean(active);
}

export function apiBase() {
  const path = window.location.pathname;
  const marker = '/public/';
  const idx = path.indexOf(marker);
  if (idx !== -1) {
    return path.slice(0, idx);
  }

  // Strip trailing page segment for clean URLs under the project folder
  const parts = path.replace(/\/+$/, '').split('/');
  const last = parts[parts.length - 1] || '';
  if (last && !last.includes('.') && last !== 'Metro%20Football%20UK' && last !== 'Metro Football UK') {
    parts.pop();
  }
  return parts.join('/') || '';
}

export function pageUrl(path) {
  const base = apiBase();
  return `${base}/${String(path).replace(/^\//, '')}`;
}

const RETURN_STORAGE_KEY = 'mf_return_to';
const RETURN_QUERY_KEY = 'next';

export function sanitizeReturnPath(path) {
  if (!path || typeof path !== 'string') return null;
  const trimmed = path.trim();
  if (!trimmed.startsWith('/') || trimmed.startsWith('//')) return null;

  const pathOnly = trimmed.split(/[?#]/)[0];
  if (/^\/(login|register|join|verify-otp|payment)(\/|$)/i.test(pathOnly)) {
    return null;
  }

  return trimmed;
}

export function hrefToReturnPath(href) {
  if (!href || href === '#') return null;
  try {
    const url = new URL(href, window.location.href);
    const basePath = (apiBase() || '').replace(/\/$/, '');
    let pathname = url.pathname;
    if (basePath && pathname.startsWith(basePath)) {
      pathname = pathname.slice(basePath.length) || '/';
    }
    return sanitizeReturnPath(`${pathname}${url.search}${url.hash}`);
  } catch {
    return null;
  }
}

export function setReturnPath(path) {
  const safe = sanitizeReturnPath(path);
  if (safe) sessionStorage.setItem(RETURN_STORAGE_KEY, safe);
  return safe;
}

export function getReturnPath() {
  const params = new URLSearchParams(window.location.search);
  const fromQuery = sanitizeReturnPath(params.get(RETURN_QUERY_KEY));
  if (fromQuery) {
    sessionStorage.setItem(RETURN_STORAGE_KEY, fromQuery);
    return fromQuery;
  }
  return sanitizeReturnPath(sessionStorage.getItem(RETURN_STORAGE_KEY));
}

export function captureReturnFromUrl() {
  return getReturnPath();
}

export function clearReturnPath() {
  sessionStorage.removeItem(RETURN_STORAGE_KEY);
}

export function appendReturnQuery(url, path = getReturnPath()) {
  const safe = sanitizeReturnPath(path);
  if (!safe) return url;
  const joiner = url.includes('?') ? '&' : '?';
  return `${url}${joiner}${RETURN_QUERY_KEY}=${encodeURIComponent(safe)}`;
}

export function loginUrlWithReturn(destination = null) {
  const path = hrefToReturnPath(destination) || sanitizeReturnPath(destination) || getReturnPath();
  if (path) setReturnPath(path);
  const next = path || getReturnPath();
  return next ? appendReturnQuery(pageUrl('login'), next) : pageUrl('login');
}

export function redirectToLogin(destination = null) {
  const path = destination
    ? (hrefToReturnPath(destination) || sanitizeReturnPath(destination))
    : hrefToReturnPath(window.location.href);
  window.location.href = loginUrlWithReturn(path || undefined);
}

export function applyAuthRedirect(path) {
  const target = String(path || '/live').replace(/^\//, '');
  if (!target.includes('link-team') && !target.includes('payment')) {
    clearReturnPath();
  }
  window.location.href = pageUrl(target);
}

export function apiUrl(path) {
  return pageUrl(`api/${String(path).replace(/^\//, '')}`);
}

export function getPlan() {
  const params = new URLSearchParams(window.location.search);
  const fromQuery = (params.get('plan') || '').toLowerCase();
  if (fromQuery === 'premium' || fromQuery === 'free') {
    sessionStorage.setItem('mf_plan', fromQuery);
    return fromQuery;
  }
  const stored = (sessionStorage.getItem('mf_plan') || 'free').toLowerCase();
  return stored === 'premium' ? 'premium' : 'free';
}

export function setPlan(plan) {
  const value = plan === 'premium' ? 'premium' : 'free';
  sessionStorage.setItem('mf_plan', value);
  return value;
}

export async function postJson(path, body) {
  const response = await fetch(apiUrl(path), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  });

  let data = {};
  let rawText = '';
  try {
    rawText = await response.text();
    data = rawText ? JSON.parse(rawText) : {};
  } catch {
    data = {};
  }

  if (!response.ok || data.ok === false) {
    const fallback = response.status === 503
      ? 'The server is busy right now. Please wait a moment and try again.'
      : 'Something went wrong. Please try again.';
    const error = new Error(data.error || fallback);
    error.status = response.status;
    error.data = data;
    throw error;
  }

  return data;
}

export function showFormError(el, message) {
  if (!el) return;
  el.hidden = !message;
  el.textContent = message || '';
}

export function setBusy(button, busy, busyLabel = 'Please wait') {
  if (!button) return;
  button.disabled = busy;
  button.classList.toggle('is-busy', busy);
  const label = button.querySelector('.mf-btn__label');
  if (!label) return;
  if (busy) {
    button.dataset.label = label.textContent || '';
    label.textContent = busyLabel;
  } else if (button.dataset.label) {
    label.textContent = button.dataset.label;
  }
}

export function googleStartUrl(plan, mode = 'signup', nextPath = null) {
  const q = new URLSearchParams({ plan, mode });
  const next = sanitizeReturnPath(nextPath) || getReturnPath();
  if (next) q.set(RETURN_QUERY_KEY, next);
  return apiUrl(`auth/google/start?${q.toString()}`);
}

function loginUrl(reason) {
  const q = reason ? `?reason=${encodeURIComponent(reason)}` : '';
  return pageUrl('login') + q;
}

function bumpActivity() {
  lastActivityAt = Date.now();
  if (!idleArmed || idleLoggingOut) return;
  if (idleTimerId) window.clearTimeout(idleTimerId);
  idleTimerId = window.setTimeout(() => {
    forceIdleLogout();
  }, idleMs);
}

async function forceIdleLogout() {
  if (idleLoggingOut || !idleArmed) return;
  idleLoggingOut = true;
  idleArmed = false;
  if (idleTimerId) window.clearTimeout(idleTimerId);
  try {
    await postJson('auth/logout', {});
  } catch {
    // still leave
  }
  window.location.href = loginUrl('idle');
}

async function verifySessionStillActive() {
  if (!idleArmed || idleLoggingOut || pageBootstrapping) return;
  try {
    const response = await fetch(apiUrl('auth/me'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (response.status >= 500) {
      return;
    }
    const data = await response.json();
    if (!response.ok || !data.authenticated) {
      idleLoggingOut = true;
      idleArmed = false;
      window.location.href = loginUrl('idle');
      return;
    }
    if (data.idle_timeout_seconds) {
      idleMs = Math.max(60, Number(data.idle_timeout_seconds) || 3600) * 1000;
    }
    sessionCache = data;
    bumpActivity();
  } catch {
    // network blip: keep local timer
  }
}

/**
 * Stripe-style idle logout while a signed-in page is open.
 */
export function initIdleLogout(session) {
  if (!session?.authenticated) return;

  const seconds = Number(session.idle_timeout_seconds);
  idleMs = Math.max(60, (Number.isFinite(seconds) && seconds > 0 ? seconds : 3600)) * 1000;
  idleArmed = true;
  idleLoggingOut = false;
  bumpActivity();

  if (document.documentElement.dataset.mfIdleBound === '1') return;
  document.documentElement.dataset.mfIdleBound = '1';

  IDLE_EVENTS.forEach((eventName) => {
    window.addEventListener(eventName, bumpActivity, { passive: true, capture: true });
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    const quietFor = Date.now() - lastActivityAt;
    if (quietFor >= idleMs) {
      forceIdleLogout();
      return;
    }
    verifySessionStillActive();
  });

  window.addEventListener('focus', () => {
    verifySessionStillActive();
  });
}

export async function fetchSession(options = {}) {
  const force = Boolean(options.force);
  const retries = Number.isFinite(options.retries) ? options.retries : 2;

  if (!force && sessionCache) {
    return sessionCache;
  }
  if (!force && sessionFetchPromise) {
    return sessionFetchPromise;
  }

  sessionFetchPromise = (async () => {
    for (let attempt = 0; attempt <= retries; attempt += 1) {
      try {
        const response = await fetch(apiUrl('auth/me'), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        let data = {};
        try {
          data = await response.json();
        } catch {
          data = {};
        }

        if (response.ok && data.authenticated) {
          sessionCache = data;
          initIdleLogout(data);
          return data;
        }

        if (response.status >= 500 && attempt < retries) {
          await sleep(400 * (attempt + 1));
          continue;
        }

        if (data?.reason === 'idle') {
          sessionCache = { authenticated: false, reason: 'idle' };
          return sessionCache;
        }

        if (response.status >= 500) {
          return {
            authenticated: false,
            transient: true,
            status: response.status,
          };
        }

        sessionCache = null;
        return null;
      } catch {
        if (attempt < retries) {
          await sleep(400 * (attempt + 1));
          continue;
        }
        return { authenticated: false, transient: true };
      }
    }

    return { authenticated: false, transient: true };
  })();

  try {
    return await sessionFetchPromise;
  } finally {
    sessionFetchPromise = null;
  }
}

export function isPremiumActive(user) {
  return user?.plan === 'premium' && user?.subscription_status === 'active';
}

export function applyAuthNav(session) {
  const guestNodes = document.querySelectorAll('[data-guest-only]');
  const authNodes = document.querySelectorAll('[data-auth-only]');
  const pricingNodes = document.querySelectorAll('[data-pricing-nav]');
  const signedIn = Boolean(session?.authenticated);
  const premium = signedIn && isPremiumActive(session?.user);

  document.body.classList.toggle('is-authenticated', signedIn);
  document.body.classList.toggle('is-premium', premium);

  document.querySelectorAll('.site-logo').forEach((logo) => {
    if (!(logo instanceof HTMLAnchorElement)) return;
    if (!logo.dataset.homeHref) {
      logo.dataset.homeHref = logo.getAttribute('href') || './';
    }
    if (signedIn) {
      logo.href = pageUrl('live');
      logo.setAttribute('aria-label', 'Metro Football dashboard');
    } else {
      logo.href = logo.dataset.homeHref;
      logo.setAttribute('aria-label', 'Metro Football home');
    }
  });

  guestNodes.forEach((node) => {
    node.hidden = signedIn;
  });

  authNodes.forEach((node) => {
    node.hidden = !signedIn;
  });

  pricingNodes.forEach((node) => {
    node.hidden = premium;
  });
}

export function bindAuthToolLinks(sessionOrGetter, root = document) {
  const scope = root instanceof Element || root instanceof Document ? root : document;
  const readSession = () => (
    typeof sessionOrGetter === 'function' ? sessionOrGetter() : sessionOrGetter
  );

  scope.querySelectorAll('[data-auth-tool]').forEach((node) => {
    if (node.dataset.authToolBound === '1') return;
    node.dataset.authToolBound = '1';

    node.addEventListener('click', (event) => {
      if (readSession()?.authenticated) return;
      event.preventDefault();
      event.stopPropagation();
      const destination = node instanceof HTMLAnchorElement
        ? node.getAttribute('href')
        : node.getAttribute('data-auth-tool-href');
      window.location.href = loginUrlWithReturn(destination);
    });
  });

  scope.querySelectorAll('[data-auth-tool-href]').forEach((node) => {
    if (node.dataset.authToolBound === '1') return;
    node.dataset.authToolBound = '1';

    node.addEventListener('click', (event) => {
      const href = node.getAttribute('data-auth-tool-href');
      if (!href) return;

      if (!readSession()?.authenticated) {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = loginUrlWithReturn(href);
        return;
      }

      if (node instanceof HTMLAnchorElement) return;

      event.preventDefault();
      window.open(href, '_blank', 'noopener,noreferrer');
    });
  });
}

export function bindSignOutButtons() {
  document.querySelectorAll('[data-sign-out]').forEach((button) => {
    button.addEventListener('click', async (event) => {
      event.preventDefault();
      setBusy(button, true);
      idleArmed = false;
      if (idleTimerId) window.clearTimeout(idleTimerId);
      clearSessionCache();
      try {
        await postJson('auth/logout', {});
      } catch {
        // still leave
      }
      window.location.href = pageUrl('login');
    });
  });
}

export function initPasswordToggles(root = document) {
  root.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const inputId = button.getAttribute('aria-controls');
      const input = inputId ? document.getElementById(inputId) : button.parentElement?.querySelector('input');
      if (!(input instanceof HTMLInputElement)) return;

      const showIcon = button.querySelector('.auth-password__icon--show');
      const hideIcon = button.querySelector('.auth-password__icon--hide');
      const revealing = input.type === 'password';

      input.type = revealing ? 'text' : 'password';
      button.setAttribute('aria-pressed', revealing ? 'true' : 'false');
      button.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');

      if (showIcon) showIcon.hidden = revealing;
      if (hideIcon) hideIcon.hidden = !revealing;
    });
  });
}
