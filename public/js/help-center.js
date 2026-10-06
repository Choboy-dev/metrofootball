import { initSiteNav } from './site-nav.js?v=20260830hd';
import {
  applyAuthNav,
  bindAuthToolLinks,
  bindSignOutButtons,
  fetchSession,
} from './auth.js?v=20260907pub';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const CACHE = '20260901perf';

let motionPromise = null;

function loadMotion() {
  if (!motionPromise) {
    motionPromise = import('./motion.js?v=20260901perf')
      .then((mod) => mod)
      .catch(() => null);
  }
  return motionPromise;
}

function syncHeaderHeight() {
  const header = document.querySelector('[data-header]');
  if (!header) return;
  document.documentElement.style.setProperty('--header-height', `${header.offsetHeight}px`);
}

function debounce(fn, ms) {
  let timer = null;
  return (...args) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => fn(...args), ms);
  };
}

function bindSearch() {
  const inputs = [...document.querySelectorAll('[data-hc-search-input]')];
  const clears = [...document.querySelectorAll('[data-hc-search-clear]')];
  const empty = document.querySelector('[data-hc-empty]');
  const sections = [...document.querySelectorAll('[data-hc-section]')];
  const faqs = [...document.querySelectorAll('[data-hc-faq]')];
  if (!inputs.length || !sections.length) return;

  const runSearch = (query) => {
    const needle = query.trim().toLowerCase();
    let visibleCount = 0;

    clears.forEach((btn) => {
      btn.hidden = needle === '';
    });

    sections.forEach((section) => {
      const sectionFaqs = [...section.querySelectorAll('[data-hc-faq]')];
      let sectionVisible = 0;

      sectionFaqs.forEach((faq) => {
        const text = faq.textContent?.toLowerCase() ?? '';
        const match = needle === '' || text.includes(needle);
        faq.classList.toggle('is-hidden', !match);
        if (match) {
          sectionVisible += 1;
          visibleCount += 1;
        }
      });

      section.classList.toggle('is-hidden', needle !== '' && sectionVisible === 0);
    });

    if (empty) {
      empty.hidden = needle === '' || visibleCount > 0;
    }
  };

  const onInput = debounce((event) => {
    const value = event.target.value ?? '';
    inputs.forEach((input) => {
      if (input !== event.target) input.value = value;
    });
    runSearch(value);
  }, 120);

  inputs.forEach((input) => input.addEventListener('input', onInput));

  clears.forEach((btn) => {
    btn.addEventListener('click', () => {
      inputs.forEach((input) => {
        input.value = '';
        input.focus();
      });
      runSearch('');
    });
  });
}

function bindNav() {
  const sections = [...document.querySelectorAll('[data-hc-section]')];
  const navLinks = [...document.querySelectorAll('[data-hc-nav]')];
  if (!sections.length || !navLinks.length) return;

  const setActive = (id) => {
    navLinks.forEach((link) => {
      const match = link.getAttribute('href') === `#${id}`;
      link.classList.toggle('is-active', match);
      if (match) {
        link.setAttribute('aria-current', 'true');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  };

  navLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      const href = link.getAttribute('href') ?? '';
      if (!href.startsWith('#')) return;
      event.preventDefault();
      const target = document.querySelector(href);
      if (!target) return;
      target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
      history.replaceState(null, '', href);
      setActive(href.slice(1));
    });
  });

  const observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
      if (!visible.length) return;
      setActive(visible[0].target.id);
    },
    {
      root: null,
      rootMargin: '-35% 0px -50% 0px',
      threshold: [0.12, 0.35, 0.6],
    }
  );

  sections.forEach((section) => observer.observe(section));

  const hash = window.location.hash.replace('#', '');
  if (hash && document.getElementById(hash)) {
    setActive(hash);
  } else if (sections[0]?.id) {
    setActive(sections[0].id);
  }
}

function bindMobileCta() {
  const cta = document.querySelector('[data-hc-mobile-cta]');
  const contact = document.querySelector('.hc-contact');
  if (!cta || !contact) return;

  const observer = new IntersectionObserver(
    ([entry]) => {
      cta.classList.toggle('is-hidden', entry?.isIntersecting ?? false);
    },
    { threshold: 0.2 }
  );

  observer.observe(contact);
}

async function bindReveal() {
  if (reduceMotion) {
    document.body.classList.add('prefers-static');
    return;
  }

  const motion = await loadMotion();
  if (!motion?.animate || !motion?.inView) {
    document.body.classList.add('prefers-static');
    return;
  }

  const { animate, inView } = motion;
  document.body.classList.add('is-motion-ready');
  const hero = document.querySelector('[data-hc-hero]');
  if (hero) {
    animate(hero, { opacity: [0, 1], y: [16, 0] }, { duration: 0.42, easing: [0.17, 0.55, 0.55, 1] });
  }

  document.querySelectorAll('.hc-quick__card').forEach((card, index) => {
    inView(
      card,
      () => {
        animate(
          card,
          { opacity: [0, 1], y: [12, 0] },
          { duration: 0.32, delay: index * 0.06, easing: [0.17, 0.55, 0.55, 1] }
        );
      },
      { amount: 0.2, once: true }
    );
  });

  document.querySelectorAll('[data-hc-reveal]').forEach((node) => {
    inView(
      node,
      () => {
        animate(
          node,
          { opacity: [0, 1], y: [14, 0] },
          { duration: 0.34, easing: [0.17, 0.55, 0.55, 1] }
        );
      },
      { amount: 0.18, once: true }
    );
  });
}

async function init() {
  syncHeaderHeight();
  window.addEventListener('resize', syncHeaderHeight, { passive: true });
  const header = document.querySelector('[data-header]');
  if (header && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(syncHeaderHeight).observe(header);
  }

  initSiteNav();
  bindSearch();
  bindNav();
  bindMobileCta();
  void bindReveal();

  let session = null;
  try {
    session = await fetchSession();
    applyAuthNav(session);
  } catch {
    applyAuthNav(null);
  }
  bindSignOutButtons();
  bindAuthToolLinks(session);
}

init();
