/**
 * Early head bootstrap — critical CSS, self-hosted fonts, WebP logos.
 */
(function () {
  var head = document.head;
  if (!head || document.querySelector('[data-mf-perf-head]')) return;

  var mark = document.createElement('meta');
  mark.setAttribute('data-mf-perf-head', '1');
  head.appendChild(mark);

  var critical = document.createElement('style');
  critical.textContent = '.hero-reveal,.auth-reveal,.reveal,.tools-grid .tool{opacity:1!important}';
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
    return link;
  }

  var fontsCss = addLink('stylesheet', './css/fonts.css?v=20260828b');
  var perfCss = addLink('stylesheet', './css/perf.css?v=20260828b');
  var appCss = document.querySelector('link[href*="app.css"]');
  if (appCss && appCss.parentNode) {
    if (fontsCss) appCss.parentNode.insertBefore(fontsCss, appCss);
    if (perfCss) appCss.parentNode.insertBefore(perfCss, appCss.nextSibling);
  } else {
    if (fontsCss) head.appendChild(fontsCss);
    if (perfCss) head.appendChild(perfCss);
  }

  [
    ['./fonts/inter-latin.woff2', 'font/woff2'],
    ['./fonts/google-sans-600.woff', 'font/woff'],
  ].forEach(function (entry) {
    var preload = addLink('preload', entry[0], { as: 'font', type: entry[1], crossorigin: '' });
    if (preload) head.appendChild(preload);
  });

  var motionPreload = addLink('preload', './js/vendor/motion.umd.js', { as: 'script' });
  if (motionPreload) head.appendChild(motionPreload);

  var heroLogo = document.querySelector('.hero .site-logo__img[src*="metro-football-logo"], .auth-card__brand .site-logo__img[src*="metro-football-logo"]');
  if (heroLogo) {
    var heroPng = heroLogo.getAttribute('src') || '';
    var heroWebp = heroPng.replace(/\.png(\?.*)?$/i, '.webp$1');
    var logoPreload = addLink('preload', heroWebp, { as: 'image', type: 'image/webp' });
    if (logoPreload) head.appendChild(logoPreload);
  }

  document.querySelectorAll('img.site-logo__img[src*="metro-football-logo"]').forEach(function (img) {
    if (img.closest('picture')) return;
    var png = img.getAttribute('src') || '';
    if (!/\.png(\?|$)/i.test(png)) return;
    var webp = png.replace(/\.png(\?.*)?$/i, '.webp$1');
    var picture = document.createElement('picture');
    var source = document.createElement('source');
    source.type = 'image/webp';
    source.srcset = webp;
    picture.appendChild(source);
    img.parentNode.insertBefore(picture, img);
    picture.appendChild(img);
  });
})();
