/**
 * LeadBirdModal — confirm / alert dialogs (no native confirm/alert)
 */
(function (global) {
  'use strict';

  var root = null;
  var previousFocus = null;

  function ensureRoot() {
    if (root) return root;
    root = document.createElement('div');
    root.className = 'lb-modal-root';
    root.setAttribute('role', 'presentation');
    root.innerHTML =
      '<button type="button" class="lb-modal-root__backdrop" data-lb-modal-backdrop aria-label="Close dialog"></button>' +
      '<div class="lb-modal" role="dialog" aria-modal="true" aria-labelledby="lb-modal-title" aria-describedby="lb-modal-body">' +
      '<div class="lb-modal__header"><h2 class="lb-modal__title" id="lb-modal-title"></h2></div>' +
      '<p class="lb-modal__body" id="lb-modal-body"></p>' +
      '<div class="lb-modal__footer" data-lb-modal-footer></div>' +
      '</div>';
    document.body.appendChild(root);

    root.querySelector('[data-lb-modal-backdrop]').addEventListener('click', function () {
      if (root._allowBackdrop !== false) {
        close(null);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (!root.classList.contains('is-open')) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        close(null);
      }
      if (e.key === 'Tab') {
        trapTab(e);
      }
    });

    return root;
  }

  function trapTab(e) {
    var focusable = root.querySelectorAll(
      'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (!focusable.length) return;
    var list = Array.prototype.slice.call(focusable);
    var first = list[0];
    var last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function close(value) {
    if (!root || !root.classList.contains('is-open')) return;
    root.classList.remove('is-open');
    document.body.style.overflow = '';
    var resolver = root._resolver;
    root._resolver = null;
    if (previousFocus && typeof previousFocus.focus === 'function') {
      try {
        previousFocus.focus();
      } catch (err) {
        /* ignore */
      }
    }
    previousFocus = null;
    if (typeof resolver === 'function') {
      resolver(value);
    }
  }

  function openDialog(options) {
    ensureRoot();
    return new Promise(function (resolve) {
      if (root.classList.contains('is-open')) {
        close(null);
      }

      previousFocus = document.activeElement;
      root._resolver = resolve;
      root._allowBackdrop = options.allowBackdrop !== false;

      var titleEl = root.querySelector('#lb-modal-title');
      var bodyEl = root.querySelector('#lb-modal-body');
      var footerEl = root.querySelector('[data-lb-modal-footer]');

      titleEl.textContent = options.title || '';
      bodyEl.textContent = options.message || '';
      footerEl.innerHTML = '';

      (options.buttons || []).forEach(function (btn, index) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'lb-modal__btn ' + (btn.className || 'lb-modal__btn--ghost');
        button.textContent = btn.label || 'OK';
        button.addEventListener('click', function () {
          close(btn.value !== undefined ? btn.value : true);
        });
        footerEl.appendChild(button);
        if (index === (options.focusIndex != null ? options.focusIndex : options.buttons.length - 1)) {
          setTimeout(function () {
            button.focus();
          }, 0);
        }
      });

      document.body.style.overflow = 'hidden';
      root.classList.add('is-open');
    });
  }

  var LeadBirdModal = {
    alert: function (message, title) {
      return openDialog({
        title: title || 'Notice',
        message: message || '',
        buttons: [{ label: 'OK', value: true, className: 'lb-modal__btn--primary' }],
        focusIndex: 0,
      });
    },

    confirm: function (message, options) {
      options = options || {};
      var danger = options.danger === true;
      return openDialog({
        title: options.title || 'Confirm',
        message: message || '',
        allowBackdrop: options.allowBackdrop !== false,
        buttons: [
          { label: options.cancelLabel || 'Cancel', value: false, className: 'lb-modal__btn--ghost' },
          {
            label: options.confirmLabel || 'Confirm',
            value: true,
            className: danger ? 'lb-modal__btn--danger' : 'lb-modal__btn--primary',
          },
        ],
        focusIndex: 0,
      });
    },

    close: close,
  };

  global.LeadBirdModal = LeadBirdModal;
})(typeof window !== 'undefined' ? window : globalThis);
