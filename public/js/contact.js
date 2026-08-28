import { initSiteNav } from './site-nav.js';
import {
  applyAuthNav,
  bindSignOutButtons,
  fetchSession,
  postJson,
  setBusy,
  showFormError,
} from './auth.js';
import { getMfSelectValue, initMfSelects } from './mf-select.js?v=20260828';

function bindContactReasonDropdown() {
  const root = document.querySelector('[data-contact-reason]');
  const card = root?.closest('.contact-card');
  const panel = root?.querySelector('[data-mf-select-panel]');
  const list = root?.querySelector('[data-mf-select-list]');
  const trigger = root?.querySelector('[data-mf-select-trigger]');
  if (!root || !card || !panel || !list || !trigger) return;

  const applyPanelBounds = () => {
    if (!root.classList.contains('is-open')) {
      list.style.maxHeight = '';
      return;
    }

    panel.style.background = '#ffffff';
    list.style.background = '#ffffff';
    list.style.overflowY = 'auto';

    const available = card.getBoundingClientRect().bottom - trigger.getBoundingClientRect().bottom - 20;
    list.style.maxHeight = `${Math.max(88, Math.floor(available))}px`;

    root.querySelectorAll('[data-mf-select-option]').forEach((option) => {
      option.style.opacity = '1';
    });
  };

  const observer = new MutationObserver(applyPanelBounds);
  observer.observe(root, { attributes: true, attributeFilter: ['class'] });
  trigger.addEventListener('click', () => {
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(applyPanelBounds);
    });
  });
  window.addEventListener('resize', applyPanelBounds);
}

function showSuccess() {
  const formWrap = document.querySelector('[data-contact-form-wrap]');
  const success = document.querySelector('[data-contact-success]');
  if (!formWrap || !success) return;
  formWrap.hidden = true;
  success.hidden = false;
  success.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function bindContactForm() {
  const form = document.querySelector('[data-contact-form]');
  const errorEl = document.querySelector('[data-contact-error]');
  const submitBtn = form?.querySelector('[type="submit"]');
  if (!form) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    showFormError(errorEl, '');

    const reasonRoot = form.querySelector('[data-contact-reason]');
    const payload = {
      name: form.name?.value?.trim() || '',
      email: form.email?.value?.trim() || '',
      reason: getMfSelectValue(reasonRoot),
      message: form.message?.value?.trim() || '',
      website: form.website?.value?.trim() || '',
    };

    setBusy(submitBtn, true, 'Sending');

    try {
      await postJson('contact', payload);
      form.reset();
      const reasonSelect = form.querySelector('[data-contact-reason]');
      if (reasonSelect) {
        reasonSelect.dataset.value = 'general';
        const valueEl = reasonSelect.querySelector('[data-mf-select-value]');
        if (valueEl) valueEl.textContent = 'General question';
        reasonSelect.querySelectorAll('[data-mf-select-option]').forEach((opt, index) => {
          const selected = index === 0;
          opt.classList.toggle('is-selected', selected);
          opt.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
      }
      showSuccess();
    } catch (error) {
      showFormError(errorEl, error.message || 'We could not send your message. Please try again.');
    } finally {
      setBusy(submitBtn, false);
    }
  });
}

async function init() {
  initSiteNav();
  initMfSelects();
  bindContactReasonDropdown();

  try {
    const session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }

  bindSignOutButtons();
  bindContactForm();
}

init();
