(function () {
  if (window.__mfGtagLoaded) return;
  window.__mfGtagLoaded = true;

  function boot() {
    window.dataLayer = window.dataLayer || [];
    function gtag() {
      window.dataLayer.push(arguments);
    }
    window.gtag = gtag;
    gtag('js', new Date());
    gtag('config', 'G-TT37S08N88');

    var script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=G-TT37S08N88';
    document.head.appendChild(script);
  }

  if ('requestIdleCallback' in window) {
    requestIdleCallback(boot, { timeout: 3500 });
  } else {
    window.addEventListener('load', boot, { once: true });
  }
})();
