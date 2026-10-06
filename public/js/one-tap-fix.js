export const ONE_TAP_STORAGE_KEY = 'mf_one_tap_fix';

export function queueOneTapFix(payload) {
  sessionStorage.setItem(ONE_TAP_STORAGE_KEY, JSON.stringify(payload));
}

export function consumeOneTapFix() {
  const raw = sessionStorage.getItem(ONE_TAP_STORAGE_KEY);
  if (!raw) return null;
  sessionStorage.removeItem(ONE_TAP_STORAGE_KEY);
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function oneTapFixButtonHtml(message) {
  return `
    <button type="button" class="mf-btn mf-btn--primary mf-btn--sm mf-one-tap-fix" data-one-tap-message="${escapeAttr(message)}">
      <span class="mf-btn__label">One-tap fix</span>
      <span class="mf-btn__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>
    </button>`;
}

function escapeAttr(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;');
}

export function bindOneTapFixButtons(root, pageUrl) {
  if (!root) return;
  root.querySelectorAll('.mf-one-tap-fix[data-one-tap-payload]').forEach((btn) => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => {
      const payloadRaw = btn.getAttribute('data-one-tap-payload');
      if (!payloadRaw) return;
      try {
        const payload = JSON.parse(decodeURIComponent(payloadRaw));
        payload.message = btn.getAttribute('data-one-tap-message') || payload.message || 'We applied your change on the Pick page. Press Save to keep it.';
        queueOneTapFix(payload);
        window.location.href = pageUrl('pick?onetap=1');
      } catch {
        // Ignore malformed payloads.
      }
    });
  });
}

export function oneTapPayloadAttr(payload) {
  return `data-one-tap-payload="${encodeURIComponent(JSON.stringify(payload))}"`;
}
