/**
 * Runtime perf helpers — self-hosted fonts only (no CDN font requests).
 */
(() => {
  const head = document.head;
  if (!head || document.querySelector('[data-mf-perf]')) return;

  const critical = document.createElement('style');
  critical.setAttribute('data-mf-perf', 'critical');
  critical.textContent = '.hero-reveal,.auth-reveal,.reveal,.tools-grid .tool{opacity:1!important}';
  head.insertBefore(critical, head.firstChild);

  const mark = document.createElement('meta');
  mark.setAttribute('data-mf-perf', '1');
  head.appendChild(mark);

  const ensureStylesheet = (href) => {
    if (document.querySelector(`link[rel="stylesheet"][href="${href}"]`)) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    const appCss = document.querySelector('link[href*="app.css"]');
    if (appCss?.parentNode) {
      appCss.parentNode.insertBefore(link, appCss);
    } else {
      head.appendChild(link);
    }
  };

  ensureStylesheet('./css/fonts.css?v=20260828b');

  if (!document.querySelector('link[href*="perf.css"]')) {
    ensureStylesheet('./css/perf.css?v=20260828b');
  }

  document.querySelectorAll('img.site-logo__img[src*="metro-football-logo"]').forEach((img) => {
    if (img.closest('picture')) return;
    const png = img.getAttribute('src') || '';
    if (!/\.png(\?|$)/i.test(png)) return;
    const webp = png.replace(/\.png(\?.*)?$/i, '.webp$1');
    const picture = document.createElement('picture');
    const source = document.createElement('source');
    source.type = 'image/webp';
    source.srcset = webp;
    picture.appendChild(source);
    img.parentNode?.insertBefore(picture, img);
    picture.appendChild(img);
  });
})();
