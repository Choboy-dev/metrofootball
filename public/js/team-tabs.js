/**
 * Compact My Team tabs — hover pill + sliding active underline.
 * Port of the provided Tabs React pattern to vanilla JS.
 */
export function initTeamTabs(root = document) {
  const nav = root.querySelector('[data-team-tabs]');
  if (!nav) return;

  const hoverEl = nav.querySelector('[data-team-tabs-hover]');
  const activeEl = nav.querySelector('[data-team-tabs-active]');
  const tabs = [...nav.querySelectorAll('[data-team-tab]')];
  if (!hoverEl || !activeEl || !tabs.length) return;

  const moveIndicator = (el, tab) => {
    if (!tab) return;
    el.style.left = `${tab.offsetLeft}px`;
    el.style.width = `${tab.offsetWidth}px`;
  };

  const syncActive = () => {
    const active = tabs.find((tab) => tab.classList.contains('is-active')) || tabs[0];
    moveIndicator(activeEl, active);
  };

  const setHover = (index) => {
    if (index === null) {
      hoverEl.style.opacity = '0';
      return;
    }
    moveIndicator(hoverEl, tabs[index]);
    hoverEl.style.opacity = '1';
  };

  const isPlaceholder = (href) => !href || href === '#' || /#$/.test(href);

  tabs.forEach((tab, index) => {
    tab.addEventListener('mouseenter', () => setHover(index));
    tab.addEventListener('mouseleave', () => setHover(null));
    tab.addEventListener('focus', () => setHover(index));
    tab.addEventListener('blur', () => setHover(null));

    tab.addEventListener('click', (event) => {
      const href = tab.getAttribute('href') || '';
      if (!isPlaceholder(href)) {
        return;
      }

      event.preventDefault();
      tabs.forEach((node) => {
        node.classList.toggle('is-active', node === tab);
        if (node === tab) {
          node.setAttribute('aria-current', 'page');
        } else {
          node.removeAttribute('aria-current');
        }
      });
      syncActive();
    });
  });

  requestAnimationFrame(syncActive);
  window.addEventListener('resize', syncActive);
}
