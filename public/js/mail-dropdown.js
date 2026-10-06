/**
 * LeadBirdDropdown — enhances native <select> elements on admin mail pages
 */
(function (global) {
  'use strict';

  var CHEVRON =
    '<svg class="lb-dropdown__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
    '<path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function enhanceSelect(select) {
    if (!select || select.dataset.lbDropdown === '1') return;
    select.dataset.lbDropdown = '1';

    var wrap = document.createElement('div');
    wrap.className = 'lb-dropdown';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('lb-dropdown__native');
    select.tabIndex = -1;

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'lb-dropdown__trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.innerHTML = '<span class="lb-dropdown__label"></span>' + CHEVRON;
    wrap.insertBefore(trigger, select);

    var menu = document.createElement('ul');
    menu.className = 'lb-dropdown__menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    wrap.appendChild(menu);

    var labelEl = trigger.querySelector('.lb-dropdown__label');
    var focusedIndex = -1;

    function syncDisabled() {
      wrap.classList.toggle('is-disabled', select.disabled);
      trigger.disabled = select.disabled;
    }

    function selectedOption() {
      return select.options[select.selectedIndex] || null;
    }

    function updateLabel() {
      var opt = selectedOption();
      labelEl.textContent = opt ? opt.textContent : '';
    }

    function buildMenu() {
      menu.innerHTML = '';
      focusedIndex = -1;
      Array.prototype.forEach.call(select.options, function (opt, index) {
        var item = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lb-dropdown__option';
        btn.setAttribute('role', 'option');
        btn.dataset.index = String(index);
        btn.textContent = opt.textContent;
        if (opt.disabled) {
          btn.setAttribute('aria-disabled', 'true');
          btn.disabled = true;
        }
        if (opt.selected) {
          btn.classList.add('is-selected');
          btn.setAttribute('aria-selected', 'true');
        } else {
          btn.setAttribute('aria-selected', 'false');
        }
        btn.addEventListener('click', function () {
          if (opt.disabled) return;
          select.selectedIndex = index;
          select.dispatchEvent(new Event('change', { bubbles: true }));
          closeMenu();
          updateLabel();
          buildMenu();
        });
        item.appendChild(btn);
        menu.appendChild(item);
      });
    }

    function openMenu() {
      if (select.disabled) return;
      LeadBirdDropdown.closeAll(wrap);
      wrap.classList.add('is-open');
      menu.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      buildMenu();
    }

    function closeMenu() {
      wrap.classList.remove('is-open');
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      focusedIndex = -1;
    }

    function moveFocus(delta) {
      var buttons = menu.querySelectorAll('.lb-dropdown__option:not([disabled])');
      if (!buttons.length) return;
      focusedIndex = Math.max(0, Math.min(buttons.length - 1, focusedIndex + delta));
      buttons.forEach(function (btn, i) {
        btn.classList.toggle('is-focused', i === focusedIndex);
      });
      buttons[focusedIndex].focus();
    }

    trigger.addEventListener('click', function () {
      if (wrap.classList.contains('is-open')) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    trigger.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openMenu();
        moveFocus(1);
      }
    });

    menu.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeMenu();
        trigger.focus();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        moveFocus(1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveFocus(-1);
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        var focused = menu.querySelector('.lb-dropdown__option.is-focused');
        if (focused) focused.click();
      }
    });

    select.addEventListener('change', function () {
      updateLabel();
      buildMenu();
    });

    var observer = new MutationObserver(function () {
      syncDisabled();
      updateLabel();
      if (wrap.classList.contains('is-open')) buildMenu();
    });
    observer.observe(select, { childList: true, subtree: true, attributes: true });

    syncDisabled();
    updateLabel();
    buildMenu();

    wrap._lbDropdownClose = closeMenu;
  }

  var LeadBirdDropdown = {
    init: function (root) {
      root = root || document;
      root.querySelectorAll('select[data-mail-dropdown], .mail-admin select, .mail-settings select').forEach(enhanceSelect);
    },

    closeAll: function (except) {
      document.querySelectorAll('.lb-dropdown.is-open').forEach(function (el) {
        if (except && el === except) return;
        if (typeof el._lbDropdownClose === 'function') {
          el._lbDropdownClose();
        }
      });
    },
  };

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.lb-dropdown')) {
      LeadBirdDropdown.closeAll();
    }
  });

  global.LeadBirdDropdown = LeadBirdDropdown;
})(typeof window !== 'undefined' ? window : globalThis);
