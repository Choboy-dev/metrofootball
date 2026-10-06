(function () {
  function applyNavHint(mode) {
    document.documentElement.dataset.authHint = mode;
    document.body?.classList.toggle('is-authenticated', mode === 'signed-in' || mode === 'premium');
    document.body?.classList.toggle('is-premium', mode === 'premium');

    document.querySelectorAll('[data-guest-only]').forEach((node) => {
      node.hidden = mode === 'signed-in' || mode === 'premium';
    });

    document.querySelectorAll('[data-auth-only]').forEach((node) => {
      node.hidden = mode !== 'signed-in' && mode !== 'premium';
    });

    if (mode === 'premium') {
      document.querySelectorAll('[data-pricing-nav]').forEach((node) => {
        node.hidden = true;
      });
    }
  }

  function readHint() {
    try {
      var raw = localStorage.getItem('mf_auth_hint');
      if (!raw) return null;

      var hint = JSON.parse(raw);
      var age = Date.now() - (hint.ts || 0);
      if (age > 7 * 24 * 60 * 60 * 1000) {
        localStorage.removeItem('mf_auth_hint');
        return null;
      }

      if (hint.premium) return 'premium';
      if (hint.authenticated) return 'signed-in';
      return null;
    } catch (e) {
      return null;
    }
  }

  var mode = readHint();
  if (!mode) return;

  applyNavHint(mode);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      applyNavHint(mode);
    }, { once: true });
  }
})();
