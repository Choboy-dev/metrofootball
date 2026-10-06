import { animate, inView, stagger } from '/js/motion.js?v=20260901perf';
import { scheduleGwTipsHydration } from '/js/site-nav.js?v=20260907pub';

function initToolGuide() {
  const section = document.querySelector('[data-mf-explainer]');
  if (!section) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // Content stays visible by default; the fade only runs as the section scrolls in.
  inView(
    section,
    () => {
      const parts = section.querySelectorAll('.mf-explainer__byline, .mf-explainer__body > *');
      animate(
        parts,
        { opacity: [0, 1], y: [12, 0] },
        { delay: stagger(0.04), duration: 0.4, easing: [0.22, 1, 0.36, 1] }
      );
    },
    { amount: 0.08 }
  );
}

initToolGuide();
// Tool pages defer the nav's gameweek tips link update and nothing else triggers it.
scheduleGwTipsHydration();
