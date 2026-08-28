/**
 * Metro Football homepage
 * Header matches provided shadcn Header design (sticky blur, Tools/Reports panels, mobile portal)
 * Motion: animate, hover, inView, stagger
 */
import { animate, inView, stagger } from './motion.js';
import { initSiteNav, scheduleGwTipsHydration } from './site-nav.js';
import { applyAuthNav, bindAuthToolLinks, bindSignOutButtons, fetchSession } from './auth.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function initHeader() {
  initSiteNav({ deferGwTips: true });
}

const authState = { session: null };

async function initAuthNav() {
  authState.session = await fetchSession();
  applyAuthNav(authState.session);
  bindAuthToolLinks(() => authState.session);
  bindSignOutButtons();
}

function revealHero() {
  const parts = document.querySelectorAll('.hero-reveal');
  if (!parts.length) return;

  if (reduceMotion) {
    parts.forEach((el) => {
      el.style.transform = 'none';
    });
    return;
  }

  animate(
    parts,
    { y: [28, 0] },
    {
      duration: 0.7,
      delay: stagger(0.1, { startDelay: 0.08 }),
      easing: [0.17, 0.55, 0.55, 1],
    }
  );
}

function revealOnScroll() {
  const targets = document.querySelectorAll('.reveal');
  if (!targets.length) return;

  if (reduceMotion) {
    targets.forEach((el) => {
      el.style.transform = 'none';
    });
    return;
  }

  inView(
    targets,
    (element) => {
      animate(
        element,
        { y: 0 },
        {
          duration: 0.55,
          easing: [0.17, 0.55, 0.55, 1],
        }
      );
    },
    { amount: 0.25, margin: '0px 0px -8% 0px' }
  );
}

function staggerToolGrid() {
  const tools = document.querySelectorAll('.tools-grid .tool');
  if (!tools.length || reduceMotion) return;

  inView(
    '.tools-grid',
    () => {
      animate(
        tools,
        { y: [22, 0], scale: [0.98, 1] },
        {
          duration: 0.45,
          delay: stagger(0.06),
          easing: [0.17, 0.55, 0.55, 1],
        }
      );
    },
    { amount: 0.2 }
  );
}

function initHeroAccordion() {
  const root = document.querySelector('[data-hero-accordion]');
  if (!root) return;

  const desktopQuery = window.matchMedia('(min-width: 900px)');
  let teardown = null;

  const mount = () => {
    teardown?.();
    teardown = desktopQuery.matches
      ? initHeroAccordionDesktop(root)
      : initHeroMobileSlider(root);
  };

  mount();
  desktopQuery.addEventListener('change', mount);
}

function initHeroAccordionDesktop(root) {
  const track = root.querySelector('[data-hero-slider-track]') || root.querySelector('.hero-accordion__track');
  const cards = [...root.querySelectorAll('[data-hero-card]:not([data-hero-clone])')];
  const CENTER_INDEX = 2;
  const GAP = 12;
  let activeIndex = CENTER_INDEX;

  track.style.transform = '';
  cards.forEach((card) => {
    card.style.width = '';
    card.style.flexBasis = '';
    card.removeAttribute('aria-hidden');
    card.removeAttribute('data-hero-clone');
  });

  function sizes() {
    const count = cards.length;
    const gaps = GAP * Math.max(count - 1, 0);
    const available = Math.max(track.clientWidth - gaps, 280);
    const idle = Math.max(44, Math.min(60, Math.floor(available * 0.075)));
    const active = Math.max(200, Math.min(400, available - idle * (count - 1)));
    return { idle, active };
  }

  function setActive(index) {
    activeIndex = index;
    const { idle, active } = sizes();

    cards.forEach((card, i) => {
      const on = i === index;
      const nextWidth = on ? active : idle;
      card.classList.toggle('is-active', on);

      if (reduceMotion) {
        card.style.width = `${nextWidth}px`;
        return;
      }

      animate(
        card,
        { width: nextWidth },
        { duration: 0.7, easing: [0.22, 1, 0.36, 1] }
      );
    });
  }

  const handlers = cards.map((card, index) => {
    const onEnter = () => setActive(index);

    card.addEventListener('mouseenter', onEnter);
    card.addEventListener('focus', onEnter);

    return { card, onEnter };
  });

  const onResize = () => setActive(activeIndex);
  setActive(CENTER_INDEX);
  window.addEventListener('resize', onResize, { passive: true });

  return () => {
    window.removeEventListener('resize', onResize);
    handlers.forEach(({ card, onEnter }) => {
      card.removeEventListener('mouseenter', onEnter);
      card.removeEventListener('focus', onEnter);
    });
    root.querySelectorAll('[data-hero-clone]').forEach((clone) => clone.remove());
    track.style.transform = '';
  };
}

function initHeroMobileSlider(root) {
  const viewport = root.querySelector('[data-hero-slider-viewport]');
  const track = root.querySelector('[data-hero-slider-track]') || root.querySelector('.hero-accordion__track');
  const dotsRoot = root.querySelector('[data-hero-slider-dots]');
  const cards = [...root.querySelectorAll('[data-hero-card]:not([data-hero-clone])')];
  const GAP = 12;
  const INTERVAL_MS = 4200;
  const SLIDE_COUNT = cards.length;
  const PREPEND_COUNT = 2;
  const APPEND_COUNT = 2;
  const SWIPE_THRESHOLD = 40;
  const DRAG_CLICK_THRESHOLD = 8;
  let step = 0;
  let offsetX = 0;
  let timer = null;
  let paused = false;
  let animating = false;
  let activeAnimation = null;
  let isDragging = false;
  let dragMoved = false;
  let dragStartX = 0;
  let dragStartOffset = 0;
  let activePointerId = null;
  const dotButtons = [];

  track.style.transform = '';
  cards.forEach((card) => {
    card.classList.remove('is-active');
  });

  root.querySelectorAll('[data-hero-clone]').forEach((clone) => clone.remove());

  function makeClone(card) {
    const clone = card.cloneNode(true);
    clone.setAttribute('data-hero-clone', 'true');
    clone.setAttribute('aria-hidden', 'true');
    clone.removeAttribute('data-index');
    clone.removeAttribute('data-auth-tool');
    clone.classList.remove('is-active');

    if (clone instanceof HTMLAnchorElement) {
      clone.removeAttribute('href');
      clone.style.pointerEvents = 'none';
      clone.tabIndex = -1;
    }

    return clone;
  }

  const prependClones = cards.slice(-PREPEND_COUNT).map(makeClone);
  const appendClones = cards.slice(0, APPEND_COUNT).map(makeClone);
  prependClones.reverse().forEach((clone) => track.insertBefore(clone, track.firstChild));
  appendClones.forEach((clone) => track.appendChild(clone));

  function metrics() {
    const gap =
      Number.parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || GAP;
    const cardWidth = (viewport.clientWidth - gap) / 2;
    return { cardWidth, stepPx: cardWidth + gap, gap };
  }

  function stepToOffset(stepIndex) {
    return -(PREPEND_COUNT + stepIndex) * metrics().stepPx;
  }

  function applyCardWidths() {
    const { cardWidth } = metrics();
    track.querySelectorAll('[data-hero-card], [data-hero-clone]').forEach((card) => {
      card.style.width = `${cardWidth}px`;
      card.style.flexBasis = `${cardWidth}px`;
    });
  }

  function applyOffset(x) {
    offsetX = x;
    track.style.transform = `translate3d(${x}px, 0, 0)`;
  }

  function stopAnimation() {
    activeAnimation?.stop?.();
    activeAnimation?.cancel?.();
    activeAnimation = null;
    track.getAnimations?.().forEach((animation) => animation.cancel());
  }

  function waitFrames(count = 2) {
    return new Promise((resolve) => {
      let remaining = count;
      const tick = () => {
        remaining -= 1;
        if (remaining <= 0) resolve();
        else requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  }

  function updateDots() {
    dotButtons.forEach((button, index) => {
      const active = index === step;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
    });
  }

  function buildDots() {
    if (!dotsRoot) return;
    dotsRoot.replaceChildren();
    dotButtons.length = 0;

    cards.forEach((card, index) => {
      const title = card.querySelector('.hero-card__title')?.textContent?.trim() || `Slide ${index + 1}`;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `hero-slider__dot${index === step ? ' is-active' : ''}`;
      button.setAttribute('data-hero-slider-dot', String(index));
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-label', title);
      button.setAttribute('aria-selected', String(index === step));
      button.tabIndex = index === step ? 0 : -1;
      button.addEventListener('click', () => {
        void goToStep(index);
      });
      dotsRoot.appendChild(button);
      dotButtons.push(button);
    });
  }

  function animateToX(targetX, animated = true) {
    if (!animated || reduceMotion) {
      stopAnimation();
      applyOffset(targetX);
      return Promise.resolve();
    }

    const fromX = offsetX;
    stopAnimation();

    activeAnimation = animate(
      track,
      { transform: [`translate3d(${fromX}px, 0, 0)`, `translate3d(${targetX}px, 0, 0)`] },
      { duration: 0.85, easing: [0.22, 1, 0.36, 1] }
    );

    return activeAnimation.finished
      .then(() => {
        applyOffset(targetX);
        activeAnimation = null;
      })
      .catch(() => {
        activeAnimation = null;
      });
  }

  function setTranslate(stepIndex, animated = true) {
    return animateToX(stepToOffset(stepIndex), animated);
  }

  function snapToStart() {
    stopAnimation();
    step = 0;
    applyOffset(stepToOffset(0));
    updateDots();
  }

  async function goToStep(targetStep) {
    if (animating || isDragging) return;
    const normalized = ((targetStep % SLIDE_COUNT) + SLIDE_COUNT) % SLIDE_COUNT;
    if (normalized === step) return;

    animating = true;
    pause();

    if (step === 0 && normalized === SLIDE_COUNT - 1) {
      await animateToX(-(PREPEND_COUNT - 1) * metrics().stepPx, true);
      step = SLIDE_COUNT - 1;
      await waitFrames();
      applyOffset(stepToOffset(step));
    } else if (step === SLIDE_COUNT - 1 && normalized === 0) {
      await setTranslate(SLIDE_COUNT, true);
      step = SLIDE_COUNT;
      await waitFrames();
      snapToStart();
    } else {
      step = normalized;
      await setTranslate(step, true);
    }

    updateDots();
    animating = false;
    resume();
  }

  async function goNext() {
    if (animating || isDragging) return;

    animating = true;
    const nextStep = step + 1;
    await setTranslate(nextStep, true);
    step = nextStep;

    if (step >= SLIDE_COUNT) {
      await waitFrames();
      snapToStart();
    }

    updateDots();
    animating = false;
  }

  async function goPrev() {
    if (animating || isDragging) return;

    animating = true;

    if (step === 0) {
      await animateToX(-(PREPEND_COUNT - 1) * metrics().stepPx, true);
      step = SLIDE_COUNT - 1;
      await waitFrames();
      applyOffset(stepToOffset(step));
    } else {
      step -= 1;
      await setTranslate(step, true);
    }

    updateDots();
    animating = false;
  }

  async function advance() {
    if (paused || animating || isDragging || reduceMotion) return;
    pause();
    await goNext();
    resume();
  }

  function startTimer() {
    if (reduceMotion) return;
    clearInterval(timer);
    timer = setInterval(() => {
      void advance();
    }, INTERVAL_MS);
  }

  function pause() {
    paused = true;
    clearInterval(timer);
  }

  function resume() {
    if (reduceMotion || isDragging) return;
    paused = false;
    startTimer();
  }

  function onPointerDown(event) {
    if (animating) return;

    isDragging = true;
    dragMoved = false;
    activePointerId = event.pointerId;
    dragStartX = event.clientX;
    dragStartOffset = offsetX;
    pause();
    stopAnimation();
    viewport.classList.add('is-dragging');
    viewport.setPointerCapture(event.pointerId);
  }

  function onPointerMove(event) {
    if (!isDragging || event.pointerId !== activePointerId) return;
    const delta = event.clientX - dragStartX;
    if (Math.abs(delta) > DRAG_CLICK_THRESHOLD) {
      dragMoved = true;
    }
    applyOffset(dragStartOffset + delta);
  }

  function onCardClick(event) {
    if (dragMoved) {
      event.preventDefault();
      event.stopPropagation();
      dragMoved = false;
    }
  }

  async function onPointerEnd(event) {
    if (!isDragging || event.pointerId !== activePointerId) return;

    isDragging = false;
    activePointerId = null;
    viewport.classList.remove('is-dragging');
    viewport.releasePointerCapture(event.pointerId);

    const delta = event.clientX - dragStartX;

    if (Math.abs(delta) > SWIPE_THRESHOLD) {
      if (delta < 0) await goNext();
      else await goPrev();
    } else {
      await setTranslate(step, true);
    }

    resume();
  }

  const onResize = () => {
    applyCardWidths();
    stopAnimation();
    applyOffset(stepToOffset(step));
  };

  const onFocusOut = (event) => {
    if (!root.contains(event.relatedTarget)) resume();
  };

  buildDots();
  applyCardWidths();
  applyOffset(stepToOffset(0));
  updateDots();
  startTimer();

  viewport.addEventListener('pointerdown', onPointerDown);
  viewport.addEventListener('pointermove', onPointerMove);
  viewport.addEventListener('pointerup', onPointerEnd);
  viewport.addEventListener('pointercancel', onPointerEnd);
  cards.forEach((card) => {
    card.addEventListener('click', onCardClick);
  });
  root.addEventListener('mouseenter', pause);
  root.addEventListener('mouseleave', resume);
  root.addEventListener('focusin', pause);
  root.addEventListener('focusout', onFocusOut);
  window.addEventListener('resize', onResize, { passive: true });

  return () => {
    pause();
    stopAnimation();
    window.removeEventListener('resize', onResize);
    viewport.removeEventListener('pointerdown', onPointerDown);
    viewport.removeEventListener('pointermove', onPointerMove);
    viewport.removeEventListener('pointerup', onPointerEnd);
    viewport.removeEventListener('pointercancel', onPointerEnd);
    cards.forEach((card) => {
      card.removeEventListener('click', onCardClick);
    });
    root.removeEventListener('mouseenter', pause);
    root.removeEventListener('mouseleave', resume);
    root.removeEventListener('focusin', pause);
    root.removeEventListener('focusout', onFocusOut);
    viewport.classList.remove('is-dragging');
    track.style.transform = '';
    prependClones.forEach((clone) => clone.remove());
    appendClones.forEach((clone) => clone.remove());
    dotsRoot?.replaceChildren();
    cards.forEach((card) => {
      card.style.width = '';
      card.style.flexBasis = '';
    });
  };
}

function initReviewTimes() {
  const nodes = [...document.querySelectorAll('[data-review-person]')];
  if (!nodes.length) return;

  const HOUR = 60 * 60 * 1000;
  const DAY = 24 * HOUR;
  const MIN_AGE = 18 * HOUR;
  const MAX_AGE = 8 * DAY;
  const dayBucket = Math.floor(Date.now() / DAY);

  function hashSeed(input) {
    let h = 2166136261;
    for (let i = 0; i < input.length; i += 1) {
      h ^= input.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return h >>> 0;
  }

  function mulberry32(seed) {
    let a = seed >>> 0;
    return () => {
      a = (a + 0x6d2b79f5) >>> 0;
      let t = a;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function formatAge(ageMs) {
    if (ageMs < DAY) {
      const hours = Math.max(18, Math.min(23, Math.round(ageMs / HOUR)));
      return `${hours} hours ago`;
    }
    const days = Math.max(1, Math.min(8, Math.round(ageMs / DAY)));
    return days === 1 ? '1 day ago' : `${days} days ago`;
  }

  const labels = new Map();

  nodes.forEach((node) => {
    const personId = node.getAttribute('data-review-person') || '0';
    if (!labels.has(personId)) {
      const rand = mulberry32(hashSeed(`${dayBucket}:${personId}`));
      const ageMs = MIN_AGE + rand() * (MAX_AGE - MIN_AGE);
      labels.set(personId, formatAge(ageMs));
    }
    node.textContent = labels.get(personId);
  });
}

function initPricingToggle() {
  const root = document.querySelector('[data-pricing]');
  if (!root) return;

  const toggle = root.querySelector('[data-billing-toggle]');
  const monthlyLabel = root.querySelector('[data-billing-label="monthly"]');
  const yearlyLabel = root.querySelector('[data-billing-label="yearly"]');
  const priceNodes = [...root.querySelectorAll('[data-price]')];
  const periodNodes = [...root.querySelectorAll('[data-period]')];
  const noteNodes = [...root.querySelectorAll('[data-billing-note]')];

  if (!toggle) return;

  const applyBilling = (isYearly) => {
    toggle.setAttribute('aria-checked', String(isYearly));
    monthlyLabel?.classList.toggle('is-active', !isYearly);
    yearlyLabel?.classList.toggle('is-active', isYearly);

    const key = isYearly ? 'yearly' : 'monthly';
    priceNodes.forEach((node) => {
      node.textContent = node.getAttribute(`data-${key}`) || node.textContent;
    });
    periodNodes.forEach((node) => {
      node.textContent = node.getAttribute(`data-${key}`) || node.textContent;
    });
    noteNodes.forEach((node) => {
      const note = node.getAttribute(`data-${key}`) || '';
      node.textContent = note;
      node.hidden = !note;
    });
  };

  applyBilling(toggle.getAttribute('aria-checked') === 'true');

  toggle.addEventListener('click', () => {
    const next = toggle.getAttribute('aria-checked') !== 'true';
    applyBilling(next);
  });
}

function deferHomeInit() {
  const run = () => {
    initHeroAccordion();
    initReviewTimes();
    initPricingToggle();
    revealOnScroll();
    staggerToolGrid();
    scheduleGwTipsHydration();
  };

  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 1800 });
  } else {
    window.setTimeout(run, 120);
  }
}

initHeader();
revealHero();
initAuthNav();
deferHomeInit();
