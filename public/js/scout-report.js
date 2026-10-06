import { animate, inView, stagger } from '/js/motion.js?v=20260901perf';

function initScoutReport() {
  const section = document.querySelector('[data-mf-scout]');
  if (!section) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // Text stays visible without JS; the fade only plays as the card scrolls in.
  inView(section, () => {
    const parts = section.querySelectorAll('.mf-scout__title, .mf-scout__byline, .mf-scout__text > p, .mf-scout__call');
    animate(parts, { opacity: [0, 1], y: [10, 0] }, { delay: stagger(0.045), duration: 0.4, easing: [0.22, 1, 0.36, 1] });
  }, { amount: 0.1 });
}

initScoutReport();
