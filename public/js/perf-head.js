/**
 * Early head bootstrap — auth hint, inlined perf CSS, self-hosted fonts, async app CSS.
 */
(function () {
  var head = document.head;
  if (!head || document.querySelector('[data-mf-perf-head]')) return;

  var mark = document.createElement('meta');
  mark.setAttribute('data-mf-perf-head', '1');
  head.appendChild(mark);
  (function authHint() {
    function applyNavHint(mode) {
      document.documentElement.dataset.authHint = mode;
      document.body?.classList.toggle('is-authenticated', mode === 'signed-in' || mode === 'premium');
      document.body?.classList.toggle('is-premium', mode === 'premium');
      document.querySelectorAll('[data-guest-only]').forEach(function (node) {
        node.hidden = mode === 'signed-in' || mode === 'premium';
      });
      document.querySelectorAll('[data-auth-only]').forEach(function (node) {
        node.hidden = mode !== 'signed-in' && mode !== 'premium';
      });
      if (mode === 'premium') {
        document.querySelectorAll('[data-pricing-nav]').forEach(function (node) {
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

  var critical = document.createElement('style');
  critical.textContent = [
    ':root{--color-background:#f4f6f8;--color-foreground:#14161c;--color-ink:#0b1220;--color-border:#d7dee6;--font-body:"Inter",system-ui,sans-serif;--font-display:"Google Sans","Inter",system-ui,sans-serif;--header-h:3.5rem}',
    '*,*::before,*::after{box-sizing:border-box}',
    'body{margin:0;font-family:var(--font-body);background:var(--color-background);color:var(--color-foreground);-webkit-font-smoothing:antialiased}',
    'img{max-width:100%;display:block}',
    '[hidden],.mf-btn[hidden]{display:none!important}',
    '.site-header{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.96);border-bottom:1px solid var(--color-border)}',
    '@media (max-width:767px){.site-header{background:#f4f6f8;border-bottom:1px solid var(--color-border)}}',
    '.site-logo__img{height:3.75rem;width:auto;display:block;max-width:none}',
    '.hero{padding:3rem 1rem 3.5rem;background:#fff}',
    '.hero-shell{width:min(100%,80rem);margin-inline:auto;display:flex;flex-direction:column;align-items:center;gap:2.5rem}',
    '.hero-copy{display:flex;flex-direction:column;gap:1rem;width:100%;max-width:36rem;text-align:center}',
    '.hero-title{margin:0;font-family:var(--font-display);font-size:clamp(2rem,5vw,3.25rem);font-weight:700;line-height:1.08;letter-spacing:-.03em}',
    '.hero-lead{margin:0;font-size:1.0625rem;line-height:1.55;color:#4b5563}',
    '.hero-accordion{width:100%;min-width:0}',
    '.hero-slider__viewport{width:100%;min-height:min(420px,62vw)}',
    '.hero-accordion__track{display:flex;flex-direction:row;align-items:center;justify-content:center;gap:.75rem;width:100%;padding:.5rem 0}',
    '.hero-slider__dots{display:flex;align-items:center;justify-content:center;gap:.5rem;min-height:1.25rem;margin-top:.75rem}',
    '.hero-reveal,.auth-reveal,.reveal,.tools-grid .tool,.join-intro,.compare-reveal,.faq-reveal,.pricing-card.reveal,.hc-reveal,.hc-hero{opacity:1!important;transform:none!important}',
    'html[data-auth-hint="signed-in"] [data-guest-only],html[data-auth-hint="premium"] [data-guest-only]{display:none!important}',
    'html[data-auth-hint="signed-in"] [data-auth-only],html[data-auth-hint="premium"] [data-auth-only]{display:block!important}',
    'html[data-auth-hint="premium"] [data-pricing-nav]{display:none!important}',
    '.site-nav{display:flex;min-height:5rem;align-items:center;justify-content:space-between;max-width:64rem;margin-inline:auto;padding:0.625rem 1rem;width:100%}',
    '.desktop-only{display:none}.mobile-only{display:inline-flex}',
    '@media (min-width:768px){.desktop-only{display:flex}.mobile-only{display:none!important}}',
    '.hero{background:#fff;padding:3rem 1rem 3.5rem}',
    '.hero-shell{width:min(100%,80rem);margin-inline:auto;display:flex;flex-direction:column;align-items:center;gap:2.5rem}',
    '.hero-card{position:relative;display:block;width:48px;height:min(450px,58vw);border-radius:1rem;overflow:hidden;background:#111827}',
    '.hero-card.is-active{width:280px}',
    '.hero-card img{position:absolute;top:0;left:0;width:var(--hero-card-active-width,280px);height:100%;object-fit:cover;object-position:center top}',
    '@media (max-width:899px){.hero-slider__viewport{overflow:hidden}.hero-card{flex:0 0 calc((100cqi - .75rem)/2);width:calc((100cqi - .75rem)/2);height:min(420px,62vw)}}',
    '@media (prefers-reduced-motion:no-preference){.reveal{transform:translateY(1.25rem)}.tools-grid .tool{transform:translateY(1.1rem) scale(.98)}}',
    '@media (prefers-reduced-motion:reduce){.reveal,.tools-grid .tool,.join-intro,.compare-reveal,.faq-reveal{transform:none}}',
  ].join('');
  head.insertBefore(critical, head.firstChild);

  function addLink(rel, href, attrs) {
    if (document.querySelector('link[rel="' + rel + '"][href="' + href + '"]')) return null;
    var link = document.createElement('link');
    link.rel = rel;
    link.href = href;
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        link.setAttribute(key, attrs[key]);
      });
    }
    head.appendChild(link);
    return link;
  }

  function loadAsyncStylesheet(href) {
    if (!href || document.querySelector('link[data-mf-async-css="' + href + '"]')) return;
    var link = document.createElement('link');
    link.rel = 'preload';
    link.as = 'style';
    link.href = href;
    link.setAttribute('data-mf-async-css', href);
    link.onload = function () {
      this.onload = null;
      this.rel = 'stylesheet';
    };
    head.appendChild(link);
  }

  [
    ['/fonts/inter-latin.woff2', 'font/woff2'],
    ['/fonts/google-sans-700-latin.woff2', 'font/woff2'],
  ].forEach(function (entry) {
    addLink('preload', entry[0], { as: 'font', type: entry[1], crossorigin: '' });
  });

  loadAsyncStylesheet('/css/fonts.css?v=20260830perf2');
  loadAsyncStylesheet('/css/perf.css?v=20260830perf');

  addLink('modulepreload', '/js/vendor/motion.esm.js', { crossorigin: '' });

  var script = document.currentScript;
  var asyncAppCss = script && script.getAttribute('data-async-app-css');
  if (asyncAppCss) {
    var existingAppCss = document.querySelector('link[rel="stylesheet"][href*="app.css"]');
    if (existingAppCss && existingAppCss.parentNode) {
      existingAppCss.parentNode.removeChild(existingAppCss);
    }
    loadAsyncStylesheet(asyncAppCss);
  }

  /* Other pages link app.css synchronously in head to limit layout shift. */
  var pageCss = script && script.getAttribute('data-page-css');
  if (pageCss) {
    pageCss.split(',').forEach(function (href) {
      loadAsyncStylesheet(href.trim());
    });
  }

  document.querySelectorAll('link[rel="stylesheet"][data-perf-page-css]').forEach(function (node) {
    var href = node.getAttribute('href');
    node.parentNode.removeChild(node);
    if (href) loadAsyncStylesheet(href);
  });

  var heroLogo = document.querySelector('.hero .site-logo__img[src*="metro-football-logo"], .hero .site-logo__img[src*="metro-football-correct-dark-logo"], .auth-card__brand .site-logo__img[src*="metro-football-logo"], .auth-card__brand .site-logo__img[src*="metro-football-correct-dark-logo"]');
  if (heroLogo) {
    var logoSrc = heroLogo.getAttribute('src') || '';
    if (/\.webp(\?|$)/i.test(logoSrc)) {
      var responsiveSrc = logoSrc
        .replace(/metro-football-correct-dark-logo(?:-\d+)?\.webp/i, 'metro-football-correct-dark-logo-315.webp')
        .replace(/metro-football-logo-light(?:-\d+)?\.webp/i, 'metro-football-logo-light-315.webp');
      addLink('preload', responsiveSrc, { as: 'image', type: 'image/webp' });
    }
  }
})();
