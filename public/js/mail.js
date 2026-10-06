/**
 * Metro Football Private Mail — client behaviour
 * Implements init modules from MAIL_CREATOR_GUIDE section 19
 */
(function () {
  'use strict';

  var SOUND_KEY = 'lb-mail-sound';
  var POLL_VISIBLE_MS = 15000;
  var POLL_HIDDEN_MS = 60000;
  var audioCtx = null;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function bindOnce(el, event, key, fn) {
    if (!el) return;
    var mapKey = key + '::' + event;
    if (!el._mailBind) el._mailBind = Object.create(null);
    if (el._mailBind[mapKey]) return;
    el._mailBind[mapKey] = true;
    el.addEventListener(event, fn);
  }

  function esc(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
  }

  function getCsrfToken() {
    var input = $('input[name="_csrf"]') || $('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function fetchJson(url, options) {
    options = options || {};
    var headers = options.headers || {};
    if (!headers['Accept']) headers['Accept'] = 'application/json';
    if (options.body && !(options.body instanceof FormData) && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }
    return fetch(url, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: options.body,
    }).then(function (res) {
      return res.json().catch(function () {
        return { ok: false, error: 'Invalid response' };
      }).then(function (data) {
        if (!res.ok && data && !data.error) data.error = 'Request failed';
        data._status = res.status;
        return data;
      });
    });
  }

  function postAction(payload) {
    var body = new URLSearchParams();
    Object.keys(payload).forEach(function (k) {
      if (payload[k] != null) body.append(k, payload[k]);
    });
    var csrf = getCsrfToken();
    if (csrf) body.append('_csrf', csrf);
    return fetch('/mail/actions', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: body.toString(),
    }).then(function (res) {
      return res.json();
    });
  }

  function primeAudio() {
    if (audioCtx) return;
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) audioCtx = new Ctx();
    } catch (err) {
      audioCtx = null;
    }
  }

  function playChime() {
    if (localStorage.getItem(SOUND_KEY) === '0') return;
    primeAudio();
    if (!audioCtx) return;
    if (audioCtx.state === 'suspended') {
      audioCtx.resume().catch(function () {});
    }
    var now = audioCtx.currentTime;
    [523.25, 659.25].forEach(function (freq, i) {
      var osc = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, now + i * 0.12);
      gain.gain.exponentialRampToValueAtTime(0.08, now + i * 0.12 + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.12 + 0.18);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(now + i * 0.12);
      osc.stop(now + i * 0.12 + 0.2);
    });
  }

  function showToast(message) {
    var toast = $('.mail-live-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'mail-live-toast';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      document.body.appendChild(toast);
    }
    toast.textContent = message || 'New mail arrived';
    toast.classList.add('is-visible');
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(function () {
      toast.classList.remove('is-visible');
    }, 4200);
  }

  function updateTitleUnread(count) {
    var base = document.title.replace(/^\(\d+\+\)\s*/, '');
    document.title = count > 0 ? '(' + count + ') ' + base : base;
  }

  function hasSelectedRows() {
    return $$('.mail-row__check input[type="checkbox"]:checked').length > 0;
  }

  /* ── Attachment accumulator ───────────────────────────── */

  function createAttachments(input, listEl) {
    var files = [];

    function fileKey(file) {
      return [file.name, file.size, file.lastModified].join('|');
    }

    function syncInput() {
      if (!input) return;
      try {
        var dt = new DataTransfer();
        files.forEach(function (f) {
          dt.items.add(f);
        });
        input.files = dt.files;
      } catch (err) {
        /* DataTransfer unsupported — browser keeps last selection only */
      }
    }

    function render() {
      if (!listEl) return;
      listEl.innerHTML = '';
      files.forEach(function (file, index) {
        var chip = document.createElement('span');
        chip.className = 'mail-attach-chip';
        chip.innerHTML =
          '<span class="mail-attach-chip__name">' +
          esc(file.name) +
          '</span>' +
          '<button type="button" class="mail-attach-chip__remove" aria-label="Remove attachment">&times;</button>';
        chip.querySelector('.mail-attach-chip__remove').addEventListener('click', function () {
          files.splice(index, 1);
          syncInput();
          render();
        });
        listEl.appendChild(chip);
      });
      listEl.hidden = files.length === 0;
    }

    return {
      add: function (newFiles) {
        Array.prototype.forEach.call(newFiles || [], function (file) {
          var key = fileKey(file);
          if (files.some(function (f) { return fileKey(f) === key; })) return;
          files.push(file);
        });
        syncInput();
        render();
      },
      clear: function () {
        files = [];
        syncInput();
        render();
      },
      sync: syncInput,
      count: function () {
        return files.length;
      },
    };
  }

  /* ── initList ─────────────────────────────────────────── */

  function getRowChecks(root) {
    return $$('.mail-row__check input[type="checkbox"]', root || document);
  }

  function syncInboxToolbarSelection(root) {
    root = root || document;
    var toolbar = $('[data-mail-inbox-toolbar]', root);
    var bulk = $('[data-mail-bulk-actions]', root);
    var selectAll = $('[data-mail-select-all]', root);
    var checks = getRowChecks(root);
    var selected = checks.filter(function (cb) { return cb.checked; });
    var selectedCount = selected.length;

    if (toolbar) {
      toolbar.classList.toggle('is-selection-active', selectedCount > 0);
    }
    if (bulk) {
      bulk.hidden = selectedCount === 0;
    }

    checks.forEach(function (cb) {
      var row = cb.closest('[data-mail-row]');
      if (row) row.classList.toggle('is-selected', cb.checked);
    });

    if (selectAll && checks.length) {
      selectAll.indeterminate = selectedCount > 0 && selectedCount < checks.length;
      selectAll.checked = selectedCount > 0 && selectedCount === checks.length;
    }
  }

  function closeInboxMenu() {
    var menu = $('[data-mail-more-menu]');
    var trigger = $('[data-mail-more-trigger]');
    if (menu) menu.hidden = true;
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }

  function initInboxToolbar(root) {
    root = root || document;
    var toolbar = $('[data-mail-inbox-toolbar]', root);
    if (!toolbar) return;

    var refreshBtn = $('[data-mail-refresh]', toolbar);
    if (refreshBtn) {
      bindOnce(refreshBtn, 'click', 'refresh', function () {
        if (typeof refreshMailList === 'function' && $('[data-mail-list]')) {
          refreshMailList();
          return;
        }
        window.location.reload();
      });
    }

    var trigger = $('[data-mail-more-trigger]', toolbar);
    var menu = $('[data-mail-more-menu]', toolbar);
    if (trigger && menu) {
      bindOnce(trigger, 'click', 'more', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = menu.hidden;
        closeInboxMenu();
        if (open) {
          menu.hidden = false;
          trigger.setAttribute('aria-expanded', 'true');
        }
      });

      $$('.mail-inbox-menu__item', menu).forEach(function (item) {
        bindOnce(item, 'click', 'menu-action', function () {
          closeInboxMenu();
        });
      });
    }

    if (!initInboxToolbar._docBound) {
      initInboxToolbar._docBound = true;
      document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-mail-toolbar-more]')) {
          closeInboxMenu();
        }
      });
    }

    var form = $('#mail-bulk-form', root) || $('#mail-bulk-form');
    if (form) {
      bindOnce(form, 'submit', 'bulk-guard', function (e) {
        var submitter = e.submitter;
        if (!submitter || submitter.name !== 'action') return;
        if (getRowChecks(document).filter(function (cb) { return cb.checked; }).length === 0) {
          e.preventDefault();
        }
      });
    }

    syncInboxToolbarSelection(root);
  }

  function bindListHandlers(root) {
    root = root || document;

    $$('[data-mail-row]', root).forEach(function (row) {
      bindOnce(row, 'click', 'row-nav', function (e) {
        if (e.target.closest('input, button, a, label')) return;
        var href = row.getAttribute('data-href');
        if (href) window.location.href = href;
      });
    });

    $$('[data-mail-star]', root).forEach(function (btn) {
      bindOnce(btn, 'click', 'star', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = btn.getAttribute('data-mail-star');
        if (!id) return;
        btn.disabled = true;
        postAction({ action: 'star', message_id: id })
          .then(function (data) {
            if (data && data.ok) {
              btn.classList.toggle('is-active', !!data.starred);
              btn.setAttribute('aria-pressed', data.starred ? 'true' : 'false');
            }
          })
          .finally(function () {
            btn.disabled = false;
          });
      });
    });

    $$('[data-mail-cancel-schedule]', root).forEach(function (btn) {
      bindOnce(btn, 'click', 'cancel-schedule', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = btn.getAttribute('data-mail-cancel-schedule');
        if (!id) return;
        LeadBirdModal.confirm('Cancel this scheduled send? The message will move to Drafts.', {
          title: 'Cancel scheduled send',
          confirmLabel: 'Cancel send',
          danger: true,
        }).then(function (ok) {
          if (!ok) return;
          btn.disabled = true;
          postAction({ action: 'cancel_schedule', message_id: id }).then(function (data) {
            if (data && data.ok) {
              var row = btn.closest('[data-mail-row]');
              if (row) row.remove();
            } else {
              LeadBirdModal.alert((data && data.error) || 'Could not cancel scheduled send.');
              btn.disabled = false;
            }
          });
        });
      });
    });

    var selectAll = $('[data-mail-select-all]', root);
    if (selectAll) {
      bindOnce(selectAll, 'change', 'select-all', function () {
        var checked = selectAll.checked;
        getRowChecks(root).forEach(function (cb) {
          cb.checked = checked;
        });
        syncInboxToolbarSelection(root);
      });
    }

    getRowChecks(root).forEach(function (cb) {
      bindOnce(cb, 'change', 'row-check', function () {
        syncInboxToolbarSelection(root);
      });
    });

    syncInboxToolbarSelection(root);
  }

  function initList() {
    bindListHandlers(document);
    initInboxToolbar(document);
  }

  /* ── initSoundToggle ──────────────────────────────────── */

  function initSoundToggle() {
    var btn = $('[data-mail-sound-toggle]');
    if (!btn) return;

    function sync() {
      var enabled = localStorage.getItem(SOUND_KEY) !== '0';
      btn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      btn.setAttribute('aria-label', enabled ? 'Mute new mail sound' : 'Unmute new mail sound');
    }

    sync();
    bindOnce(btn, 'click', 'sound', function () {
      primeAudio();
      var enabled = localStorage.getItem(SOUND_KEY) === '0';
      localStorage.setItem(SOUND_KEY, enabled ? '1' : '0');
      sync();
    });

    document.addEventListener(
      'click',
      function once() {
        primeAudio();
        document.removeEventListener('click', once, true);
      },
      true
    );
  }

  /* ── initLiveMail ─────────────────────────────────────── */

  function refreshMailList() {
    return fetch(window.location.href, { credentials: 'same-origin' })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newList = doc.querySelector('[data-mail-list]');
        var curList = $('[data-mail-list]');
        if (newList && curList) {
          curList.innerHTML = newList.innerHTML;
          bindListHandlers(curList);
          syncInboxToolbarSelection(document);
        }
        var newTotal = doc.querySelector('[data-mail-total]');
        var curTotal = $('[data-mail-total]');
        if (newTotal && curTotal) {
          curTotal.textContent = newTotal.textContent;
        }
        var newToolbar = doc.querySelector('[data-mail-inbox-toolbar]');
        var curToolbar = $('[data-mail-inbox-toolbar]');
        if (newToolbar && curToolbar) {
          curToolbar.className = newToolbar.className;
          var newBulk = newToolbar.querySelector('[data-mail-bulk-actions]');
          var curBulk = curToolbar.querySelector('[data-mail-bulk-actions]');
          if (newBulk && curBulk) {
            curBulk.hidden = newBulk.hidden;
          }
        }
        var newUnread = doc.body.getAttribute('data-mail-unread');
        if (newUnread != null) {
          document.body.setAttribute('data-mail-unread', newUnread);
          updateTitleUnread(parseInt(newUnread, 10) || 0);
        }
      });
  }

  function initLiveMail() {
    if (!document.body.hasAttribute('data-mail-latest-id')) return;

    var latestId = parseInt(document.body.getAttribute('data-mail-latest-id'), 10) || 0;
    var unread = parseInt(document.body.getAttribute('data-mail-unread'), 10) || 0;
    updateTitleUnread(unread);

    function poll() {
      fetchJson('/mail/updates')
        .then(function (data) {
          if (!data || !data.ok) return;
          var newLatest = parseInt(data.latest_id, 10) || 0;
          var newUnread = parseInt(data.unread, 10) || 0;
          document.body.setAttribute('data-mail-unread', String(newUnread));
          updateTitleUnread(newUnread);
          if (newLatest > latestId) {
            latestId = newLatest;
            document.body.setAttribute('data-mail-latest-id', String(latestId));
            playChime();
            showToast('New mail arrived');
            if (!hasSelectedRows() && $('[data-mail-list]')) {
              refreshMailList();
            }
          }
        })
        .catch(function () {});
    }

    function schedule() {
      clearTimeout(initLiveMail._timer);
      var delay = document.hidden ? POLL_HIDDEN_MS : POLL_VISIBLE_MS;
      initLiveMail._timer = setTimeout(function () {
        poll();
        schedule();
      }, delay);
    }

    document.addEventListener('visibilitychange', schedule);
    schedule();
  }

  /* ── initRecipientReveal ──────────────────────────────── */

  function initRecipientReveal() {
    $$('[data-mail-recipients-reveal]').forEach(function (wrap) {
      var full = wrap.querySelector('[data-mail-recipients-full]');
      var short = wrap.querySelector('[data-mail-recipients-short]');
      var toggle = wrap.querySelector('[data-mail-recipients-toggle]');
      if (!full || !short || !toggle) return;

      bindOnce(toggle, 'click', 'recipients', function (e) {
        e.preventDefault();
        var expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        full.hidden = expanded;
        short.hidden = !expanded;
        toggle.textContent = expanded ? toggle.getAttribute('data-more-label') || '(more)' : '(less)';
      });
    });
  }

  /* ── initSidebar ──────────────────────────────────────── */

  function initSidebar() {
    var sidebar = $('.mail-sidebar');
    var menuBtn = $('[data-mail-menu-toggle]');
    var overlay = $('.mail-sidebar-overlay');

    if (!sidebar || !menuBtn) return;

    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'mail-sidebar-overlay';
      document.body.appendChild(overlay);
    }

    function close() {
      sidebar.classList.remove('is-open');
      overlay.classList.remove('is-visible');
      document.body.classList.remove('is-sidebar-open');
    }

    function open() {
      sidebar.classList.add('is-open');
      overlay.classList.add('is-visible');
      document.body.classList.add('is-sidebar-open');
    }

    bindOnce(menuBtn, 'click', 'menu', function () {
      if (sidebar.classList.contains('is-open')) close();
      else open();
    });

    bindOnce(overlay, 'click', 'overlay', close);
    window.addEventListener('resize', function () {
      if (window.innerWidth > 700) close();
    });
  }

  /* ── initFrames ───────────────────────────────────────── */

  function resizeFrame(frame) {
    try {
      var doc = frame.contentDocument || frame.contentWindow.document;
      if (!doc || !doc.body) return;
      var height = doc.documentElement.scrollHeight || doc.body.scrollHeight;
      frame.style.height = Math.max(height, 120) + 'px';
    } catch (err) {
      /* sandbox cross-origin guard */
    }
  }

  function initFrames() {
    $$('.mail-body-frame').forEach(function (frame) {
      bindOnce(frame, 'load', 'frame', function () {
        resizeFrame(frame);
      });
      if (frame.getAttribute('srcdoc')) {
        setTimeout(function () { resizeFrame(frame); }, 0);
      }
    });
  }

  /* ── initPopovers ─────────────────────────────────────── */

  var openPop = null;

  function closePop() {
    if (openPop) {
      openPop.hidden = true;
      openPop = null;
    }
  }

  function positionPop(pop, anchor) {
    var rect = anchor.getBoundingClientRect();
    pop.style.top = rect.bottom + 6 + 'px';
    pop.style.left = Math.min(rect.left, window.innerWidth - pop.offsetWidth - 8) + 'px';
  }

  function initPopovers() {
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-mail-pop-trigger]');
      if (trigger) {
        e.preventDefault();
        var targetId = trigger.getAttribute('data-mail-pop-trigger');
        var pop = targetId ? document.getElementById(targetId) : trigger.parentElement.querySelector('[data-mail-pop]');
        if (!pop) return;
        if (openPop === pop && !pop.hidden) {
          closePop();
          return;
        }
        closePop();
        pop.hidden = false;
        positionPop(pop, trigger);
        openPop = pop;
        return;
      }
      if (!e.target.closest('[data-mail-pop]')) closePop();
    });

    window.addEventListener('resize', closePop);
    window.addEventListener('scroll', closePop, true);
  }

  /* ── initEditors ──────────────────────────────────────── */

  function uploadInlineImage(file) {
    var fd = new FormData();
    fd.append('image', file);
    var csrf = getCsrfToken();
    if (csrf) fd.append('_csrf', csrf);
    return fetch('/mail/upload-image', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.url) throw new Error((data && data.error) || 'Upload failed');
        return data.url;
      });
  }

  function insertImageAtCursor(editor, url) {
    editor.focus();
    var html = '<img src="' + esc(url) + '" alt="" style="max-width:600px;height:auto">';
    if (document.queryCommandSupported('insertHTML')) {
      document.execCommand('insertHTML', false, html);
    } else {
      editor.innerHTML += html;
    }
  }

  function handleImageFiles(editor, files) {
    Array.prototype.forEach.call(files || [], function (file) {
      if (!file.type || file.type.indexOf('image/') !== 0) return;
      uploadInlineImage(file)
        .then(function (url) { insertImageAtCursor(editor, url); })
        .catch(function (err) {
          LeadBirdModal.alert(err.message || 'Could not upload image.');
        });
    });
  }

  function sanitizePasteHtml(html) {
    var tpl = document.createElement('template');
    tpl.innerHTML = html;
    tpl.content.querySelectorAll('script, iframe, form, object, embed').forEach(function (n) {
      n.remove();
    });
    return tpl.innerHTML;
  }

  function initEditorToolbar(toolbar, editor) {
    if (!toolbar || !editor) return;

    toolbar.querySelectorAll('[data-cmd]').forEach(function (btn) {
      bindOnce(btn, 'click', 'cmd', function (e) {
        e.preventDefault();
        var cmd = btn.getAttribute('data-cmd');
        var val = btn.getAttribute('data-cmd-value') || null;
        editor.focus();
        document.execCommand(cmd, false, val);
      });
    });

    toolbar.querySelectorAll('[data-font-name]').forEach(function (sel) {
      bindOnce(sel, 'change', 'font', function () {
        editor.focus();
        document.execCommand('fontName', false, sel.value);
      });
    });

    toolbar.querySelectorAll('[data-font-size]').forEach(function (sel) {
      bindOnce(sel, 'change', 'size', function () {
        editor.focus();
        document.execCommand('fontSize', false, sel.value);
      });
    });

    toolbar.querySelectorAll('[data-fore-color]').forEach(function (sw) {
      bindOnce(sw, 'click', 'fore', function (e) {
        e.preventDefault();
        editor.focus();
        document.execCommand('foreColor', false, sw.getAttribute('data-fore-color'));
      });
    });

    toolbar.querySelectorAll('[data-hilite-color]').forEach(function (sw) {
      bindOnce(sw, 'click', 'hi', function (e) {
        e.preventDefault();
        editor.focus();
        document.execCommand('hiliteColor', false, sw.getAttribute('data-hilite-color'));
      });
    });

    var imageBtn = toolbar.querySelector('[data-mail-insert-image]');
    var imageInput = toolbar.querySelector('[data-mail-image-input]');
    if (imageBtn && imageInput) {
      bindOnce(imageBtn, 'click', 'img-btn', function (e) {
        e.preventDefault();
        imageInput.click();
      });
      bindOnce(imageInput, 'change', 'img-in', function () {
        handleImageFiles(editor, imageInput.files);
        imageInput.value = '';
      });
    }

    bindOnce(editor, 'paste', 'paste', function (e) {
      var items = (e.clipboardData && e.clipboardData.items) || [];
      var hasImage = false;
      for (var i = 0; i < items.length; i++) {
        if (items[i].type && items[i].type.indexOf('image/') === 0) {
          hasImage = true;
          e.preventDefault();
          var file = items[i].getAsFile();
          if (file) handleImageFiles(editor, [file]);
        }
      }
      if (!hasImage && e.clipboardData && e.clipboardData.getData('text/html')) {
        e.preventDefault();
        var safe = sanitizePasteHtml(e.clipboardData.getData('text/html'));
        document.execCommand('insertHTML', false, safe);
      }
    });

    bindOnce(editor, 'drop', 'drop', function (e) {
      if (!e.dataTransfer || !e.dataTransfer.files.length) return;
      e.preventDefault();
      handleImageFiles(editor, e.dataTransfer.files);
    });

    bindOnce(editor, 'dragover', 'drag', function (e) {
      e.preventDefault();
    });

    bindOnce(editor, 'keydown', 'keys', function (e) {
      if (e.ctrlKey || e.metaKey) {
        if (e.key === 'b') { e.preventDefault(); document.execCommand('bold'); }
        if (e.key === 'i') { e.preventDefault(); document.execCommand('italic'); }
        if (e.key === 'u') { e.preventDefault(); document.execCommand('underline'); }
      }
    });
  }

  function initEditors(root) {
    root = root || document;
    $$('[data-mail-editor]', root).forEach(function (editor) {
      var toolbarId = editor.getAttribute('aria-labelledby') || editor.getAttribute('data-mail-toolbar-id');
      var toolbar = toolbarId ? document.getElementById(toolbarId) : editor.parentElement.querySelector('[data-mail-toolbar]');
      initEditorToolbar(toolbar, editor);
    });
  }

  /* ── Recipient collapse ───────────────────────────────── */

  function parseRecipients(value) {
    return (value || '')
      .split(/[,;]+/)
      .map(function (s) { return s.trim(); })
      .filter(Boolean);
  }

  function measureSummaryWidth(text, font) {
    var canvas = measureSummaryWidth._canvas || (measureSummaryWidth._canvas = document.createElement('canvas'));
    var ctx = canvas.getContext('2d');
    ctx.font = font || '14px Inter, system-ui, sans-serif';
    return ctx.measureText(text).width;
  }

  function buildRecipientSummary(block) {
    var toVal = ($('[name="to"]', block) || {}).value || '';
    var ccVal = ($('[name="cc"]', block) || {}).value || '';
    var bccVal = ($('[name="bcc"]', block) || {}).value || '';
    var to = parseRecipients(toVal);
    var cc = parseRecipients(ccVal);
    var bcc = parseRecipients(bccVal);
    var all = to.concat(cc);
    var maxW = block.offsetWidth - 120 || 280;
    var font = '14px Inter, system-ui, sans-serif';
    var shown = [];
    var hidden = 0;
    all.forEach(function (addr, i) {
      var trial = shown.concat(addr).join(', ');
      if (i === 0 || measureSummaryWidth(trial, font) <= maxW) {
        shown.push(addr);
      } else {
        hidden = all.length - shown.length;
      }
    });
    if (!shown.length && all.length) {
      shown = [all[0]];
      hidden = Math.max(0, all.length - 1);
    }
    var summaryEl = block.querySelector('[data-mail-recipients-summary-text]');
    var chipEl = block.querySelector('[data-mail-recipients-bcc-chip]');
    if (summaryEl) summaryEl.textContent = shown.join(', ') + (hidden ? '' : '');
    if (chipEl) {
      if (bcc.length) {
        chipEl.textContent = bcc.length + ' Bcc';
        chipEl.hidden = false;
      } else {
        chipEl.hidden = true;
      }
    }
    var moreEl = block.querySelector('[data-mail-recipients-more]');
    if (moreEl) {
      if (hidden > 0) {
        moreEl.textContent = hidden + ' more';
        moreEl.hidden = false;
      } else {
        moreEl.hidden = true;
      }
    }
  }

  function initRecipientCollapse(block) {
    if (!block) return;
    var inputs = $$('input[name="to"], input[name="cc"], input[name="bcc"]', block);

    function isRecipientInput(el) {
      return el && inputs.indexOf(el) !== -1;
    }

    function collapse() {
      var hasAny = inputs.some(function (inp) { return inp.value.trim(); });
      if (!hasAny) return;
      if (isRecipientInput(document.activeElement)) return;
      buildRecipientSummary(block);
      block.classList.add('is-collapsed');
    }

    function expand() {
      block.classList.remove('is-collapsed');
    }

    block._mailRecipientsExpand = expand;

    inputs.forEach(function (inp) {
      bindOnce(inp, 'blur', 'rc-blur', function () {
        setTimeout(function () {
          var active = document.activeElement;
          if (isRecipientInput(active)) return;
          if (active && block.contains(active) && active.closest('.mail-compose__toggles')) return;
          collapse();
        }, 0);
      });
      bindOnce(inp, 'focus', 'rc-focus', expand);
    });

    var summary = block.querySelector('.mail-recipients__summary');
    if (summary) {
      bindOnce(summary, 'click', 'rc-summary', function () {
        expand();
        var ccRow = block.querySelector('[data-mail-field="cc"]');
        var bccRow = block.querySelector('[data-mail-field="bcc"]');
        var target = inputs[0];
        if (ccRow && !ccRow.hidden) target = ccRow.querySelector('input') || target;
        else if (bccRow && !bccRow.hidden) target = bccRow.querySelector('input') || target;
        if (target) target.focus();
      });
    }
  }

  /* ── initCompose ──────────────────────────────────────── */

  function initCompose() {
    var panel = $('[data-mail-compose]');
    if (!panel) return;

    var form = $('[data-mail-compose-form]', panel) || $('form', panel);
    var editor = $('[data-mail-editor]', panel);
    var htmlInput = $('input[name="html"]', form);
    var attachInput = $('input[name="attachments[]"]', form) || $('input[data-mail-attach-input]', panel);
    var attachList = $('[data-mail-attach-list]', panel);
    var attachments = createAttachments(attachInput, attachList);
    var recipientBlock = $('[data-mail-recipients]', panel);

    initEditors(panel);
    initRecipientCollapse(recipientBlock);

    bindOnce(panel.querySelector('[data-mail-compose-minimize]'), 'click', 'min', function () {
      panel.classList.toggle('is-minimized');
    });

    bindOnce(panel.querySelector('[data-mail-compose-fullscreen]'), 'click', 'full', function () {
      panel.classList.toggle('is-full');
    });

    bindOnce(panel.querySelector('[data-mail-compose-close]'), 'click', 'close', function () {
      if (htmlInput && editor) htmlInput.value = editor.innerHTML;
      var draftBtn = panel.querySelector('[data-mail-save-draft]');
      if (draftBtn) {
        var actionInput = $('input[name="action"]', form);
        if (actionInput) actionInput.value = 'draft';
        form.submit();
      } else {
        var url = new URL(window.location.href);
        url.searchParams.delete('compose');
        url.searchParams.delete('source');
        url.searchParams.delete('draft');
        window.location.href = url.toString();
      }
    });

    $$('[data-mail-toggle-cc], [data-mail-toggle-bcc]', panel).forEach(function (btn) {
      bindOnce(btn, 'click', 'cc-bcc', function (e) {
        e.preventDefault();
        if (recipientBlock && typeof recipientBlock._mailRecipientsExpand === 'function') {
          recipientBlock._mailRecipientsExpand();
        }
        var field = btn.hasAttribute('data-mail-toggle-cc') ? 'cc' : 'bcc';
        var row = panel.querySelector('[data-mail-field="' + field + '"]');
        if (!row) return;
        row.hidden = false;
        btn.hidden = true;
        var input = row.querySelector('input');
        if (input) input.focus();
      });
    });

    bindOnce(panel.querySelector('[data-mail-formatting-toggle]'), 'click', 'fmt', function () {
      var wrap = panel.querySelector('.mail-editor-wrap');
      if (wrap) wrap.classList.toggle('is-toolbar-hidden');
    });

    if (attachInput) {
      bindOnce(attachInput, 'click', 'attach-pick', function () {
        attachInput.value = '';
      });
      bindOnce(attachInput, 'change', 'attach', function () {
        attachments.add(attachInput.files);
      });
    }

    var linkPop = panel.querySelector('[data-mail-link-pop]');
    bindOnce(panel.querySelector('[data-mail-insert-link]'), 'click', 'link', function (e) {
      e.preventDefault();
      if (!linkPop) return;
      linkPop.hidden = false;
      var anchor = e.currentTarget;
      positionPop(linkPop, anchor);
      openPop = linkPop;
    });

    if (linkPop) {
      bindOnce(linkPop.querySelector('[data-mail-link-apply]'), 'click', 'link-go', function () {
        var urlInput = linkPop.querySelector('input[type="url"], input[name="url"]');
        var url = urlInput ? urlInput.value.trim() : '';
        if (!url) return;
        if (editor) {
          editor.focus();
          document.execCommand('createLink', false, url);
        }
        linkPop.hidden = true;
      });
    }

    $$('[data-mail-insert-signature]', panel).forEach(function (btn) {
      bindOnce(btn, 'click', 'sig-trig', function (e) {
        e.preventDefault();
        var pop = panel.querySelector('[data-mail-signature-pop]');
        if (!pop) return;
        pop.hidden = false;
        positionPop(pop, btn);
        openPop = pop;
      });
    });

    $$('[data-mail-signature-item]', panel).forEach(function (item) {
      bindOnce(item, 'click', 'sig-item', function () {
        var tpl = document.querySelector('template[data-mail-signature-html="' + item.getAttribute('data-mail-signature-item') + '"]');
        if (!tpl || !editor) return;
        editor.focus();
        document.execCommand('insertHTML', false, tpl.innerHTML);
        var pop = panel.querySelector('[data-mail-signature-pop]');
        if (pop) pop.hidden = true;
      });
    });

    bindOnce(panel.querySelector('[data-mail-save-draft]'), 'click', 'draft', function (e) {
      e.preventDefault();
      if (htmlInput && editor) htmlInput.value = editor.innerHTML;
      var actionInput = $('input[name="action"]', form);
      if (actionInput) actionInput.value = 'draft';
      form.submit();
    });

    bindOnce(panel.querySelector('[data-mail-discard]'), 'click', 'discard', function (e) {
      e.preventDefault();
      LeadBirdModal.confirm('Discard this draft? This cannot be undone.', {
        title: 'Discard draft',
        confirmLabel: 'Discard',
        danger: true,
      }).then(function (ok) {
        if (!ok) return;
        var draftId = $('input[name="draft_id"]', form);
        if (draftId && draftId.value) {
          postAction({ action: 'discard_draft', id: draftId.value }).then(function () {
            var url = new URL(window.location.href);
            url.searchParams.delete('compose');
            url.searchParams.delete('draft');
            window.location.href = url.toString();
          });
        } else {
          var url = new URL(window.location.href);
          url.searchParams.delete('compose');
          window.location.href = url.toString();
        }
      });
    });

    /* Schedule send sheet */
    var scheduleSheet = $('[data-mail-schedule]', panel);
    var scheduledInput = $('input[name="scheduled_at"]', form);

    bindOnce(panel.querySelector('[data-mail-schedule-open]'), 'click', 'sched-open', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (scheduleSheet) scheduleSheet.hidden = !scheduleSheet.hidden;
    });

    $$('[data-mail-schedule-preset]', panel).forEach(function (btn) {
      bindOnce(btn, 'click', 'sched-pre', function () {
        var val = btn.getAttribute('data-mail-schedule-preset');
        if (scheduledInput) scheduledInput.value = val || '';
        if (scheduleSheet) scheduleSheet.hidden = true;
      });
    });

    bindOnce(panel.querySelector('[data-mail-schedule-apply]'), 'click', 'sched-go', function (e) {
      e.preventDefault();
      if (htmlInput && editor) htmlInput.value = editor.innerHTML;
      var actionInput = $('input[name="action"]', form);
      if (actionInput) actionInput.value = 'send';
      if (scheduleSheet) scheduleSheet.hidden = true;
      form.submit();
    });

    bindOnce(panel.querySelector('[data-mail-schedule-cancel]'), 'click', 'sched-cancel', function (e) {
      e.preventDefault();
      if (scheduleSheet) scheduleSheet.hidden = true;
    });

    bindOnce(form, 'submit', 'submit', function () {
      if (htmlInput && editor) htmlInput.value = editor.innerHTML;
      if (attachments && typeof attachments.sync === 'function') attachments.sync();
    });

    bindOnce(panel.querySelector('[data-mail-compose-send]'), 'click', 'send', function () {
      if (htmlInput && editor) htmlInput.value = editor.innerHTML;
      var actionInput = $('input[name="action"]', form);
      if (actionInput) actionInput.value = 'send';
      if (scheduledInput && !scheduledInput.value) {
        /* immediate send */
      }
    });
  }

  /* ── initSignatureForms ───────────────────────────────── */

  function initSignatureForms() {
    $$('[data-mail-signature-form]').forEach(function (form) {
      var editor = $('[data-mail-editor]', form);
      var htmlInput = $('input[name="body_html"], textarea[name="body_html"]', form);
      initEditors(form);

      bindOnce(form, 'submit', 'sig-submit', function () {
        if (htmlInput && editor) {
          if (htmlInput.tagName === 'TEXTAREA') htmlInput.value = editor.innerHTML;
          else htmlInput.value = editor.innerHTML;
        }
      });

      var deleteBtn = form.querySelector('[data-mail-signature-delete]');
      if (deleteBtn) {
        bindOnce(deleteBtn, 'click', 'sig-del', function (e) {
          e.preventDefault();
          LeadBirdModal.confirm('Delete this signature?', {
            title: 'Delete signature',
            confirmLabel: 'Delete',
            danger: true,
          }).then(function (ok) {
            if (!ok) return;
            var idInput = $('input[name="signature_id"]', form);
            if (!idInput) return;
            postAction({ action: 'delete_signature', id: idInput.value }).then(function (data) {
              if (data && data.ok) window.location.reload();
              else LeadBirdModal.alert((data && data.error) || 'Could not delete signature.');
            });
          });
        });
      }
    });

    $$('[data-mail-signature-create]').forEach(function (btn) {
      bindOnce(btn, 'click', 'sig-new', function () {
        var target = document.querySelector(btn.getAttribute('data-mail-signature-create'));
        if (target) target.hidden = false;
      });
    });
  }

  /* ── initAvatarForm ───────────────────────────────────── */

  function initAvatarForm() {
    var form = $('[data-mail-avatar-form]');
    if (!form) return;

    var input = $('input[type="file"][name="avatar"]', form);
    var preview = $('[data-mail-avatar-preview]', form);

    if (input && preview) {
      bindOnce(input, 'change', 'avatar', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        if (file.size > 3 * 1024 * 1024) {
          LeadBirdModal.alert('Profile picture must be 3 MB or smaller.');
          input.value = '';
          return;
        }
        var reader = new FileReader();
        reader.onload = function () {
          if (preview.tagName === 'IMG') preview.src = reader.result;
          else {
            var img = document.createElement('img');
            img.className = preview.className;
            img.alt = 'Profile preview';
            img.src = reader.result;
            preview.replaceWith(img);
          }
        };
        reader.readAsDataURL(file);
      });
    }

    var removeBtn = $('[data-mail-avatar-remove]', form);
    if (removeBtn) {
      bindOnce(removeBtn, 'click', 'avatar-rm', function (e) {
        e.preventDefault();
        LeadBirdModal.confirm('Remove your profile picture?', {
          title: 'Remove photo',
          confirmLabel: 'Remove',
          danger: true,
        }).then(function (ok) {
          if (!ok) return;
          var rmInput = $('input[name="remove_avatar"]', form);
          if (rmInput) rmInput.value = '1';
          form.submit();
        });
      });
    }
  }

  /* ── initAdminUsers ───────────────────────────────────── */

  function initAdminUsers() {
    var modal = document.getElementById('mail-user-modal');
    var form = $('[data-mail-user-form]');
    if (!modal || !form) return;

    var emailEl = $('[data-mail-user-modal-email]', modal);
    var userIdInput = $('input[name="user_id"]', form);
    var nameInput = $('input[name="display_name"]', form);
    var roleSelect = $('select[name="role"]', form);
    var activeCheck = $('input[name="is_active"]', form);
    var passwordInput = $('input[name="password"]', form);
    var previousFocus = null;

    function closeModal() {
      modal.classList.remove('is-open');
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (typeof LeadBirdDropdown !== 'undefined') {
        LeadBirdDropdown.closeAll();
      }
      if (previousFocus && typeof previousFocus.focus === 'function') {
        try {
          previousFocus.focus();
        } catch (err) {
          /* ignore */
        }
      }
      previousFocus = null;
    }

    function openModal(data) {
      previousFocus = document.activeElement;
      if (emailEl) emailEl.textContent = data.email || '';
      if (userIdInput) userIdInput.value = data.id || '';
      if (nameInput) nameInput.value = data.name || '';
      if (roleSelect) {
        roleSelect.value = data.role || 'user';
        roleSelect.dispatchEvent(new Event('change', { bubbles: true }));
        var dropdownWrap = roleSelect.closest('.lb-dropdown');
        if (dropdownWrap) {
          var dropdownLabel = dropdownWrap.querySelector('.lb-dropdown__label');
          var selectedOpt = roleSelect.options[roleSelect.selectedIndex];
          if (dropdownLabel && selectedOpt) {
            dropdownLabel.textContent = selectedOpt.textContent;
          }
        }
      }
      if (activeCheck) activeCheck.checked = data.active === '1';
      if (passwordInput) passwordInput.value = '';

      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('is-open');
      document.body.style.overflow = 'hidden';

      if (typeof LeadBirdDropdown !== 'undefined') {
        LeadBirdDropdown.init(modal);
      }

      if (nameInput) {
        setTimeout(function () {
          nameInput.focus();
        }, 0);
      }
    }

    $$('[data-mail-edit-user]').forEach(function (btn) {
      bindOnce(btn, 'click', 'edit-user', function () {
        openModal({
          id: btn.getAttribute('data-user-id'),
          email: btn.getAttribute('data-user-email'),
          name: btn.getAttribute('data-user-name'),
          role: btn.getAttribute('data-user-role'),
          active: btn.getAttribute('data-user-active'),
        });
      });
    });

    $$('[data-mail-user-modal-close]', modal).forEach(function (btn) {
      bindOnce(btn, 'click', 'close-user-modal', function (e) {
        e.preventDefault();
        closeModal();
      });
    });

    if (!initAdminUsers._keydownBound) {
      initAdminUsers._keydownBound = true;
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
          e.preventDefault();
          closeModal();
        }
      });
    }
  }

  /* ── Boot ─────────────────────────────────────────────── */

  function boot() {
    initList();
    initSoundToggle();
    initLiveMail();
    initRecipientReveal();
    initSidebar();
    initFrames();
    initPopovers();
    initCompose();
    initSignatureForms();
    initAvatarForm();
    initAdminUsers();
    if (typeof LeadBirdDropdown !== 'undefined') LeadBirdDropdown.init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
