/**
 * Mobile navigation drawer with accordion sections.
 */

const CHEVRON = '<svg class="mobile-accordion__chevron" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

const MOBILE_MENU_SECTIONS = [
  {
    id: 'tools',
    label: 'Tools',
    links: [
      { title: 'Rate My Team', desc: 'Score your squad and pick a captain', href: './pick', auth: true },
      { title: 'AI Transfers', desc: 'Get the best transfer for your squad', href: './ai-tips', auth: true },
      { title: 'Chip Strategy Planner', desc: 'Find the best weeks for each chip', href: './chip-strategy', auth: true },
      { title: 'Differential Finder', desc: 'Spot low owned high scorers', href: './ai-tips#snipers', auth: true },
      { title: 'Fixture Difficulty Ticker', desc: 'See easy and hard fixture runs', href: './fixture-difficulty', auth: true },
      { title: 'Mini League Tracker', desc: 'Watch rivals and rank swings', href: './mini-leagues', auth: true },
    ],
    note: 'Interested? <a href="./join">Go premium from £2 a month</a>',
  },
  {
    id: 'reports',
    label: 'Reports',
    links: [
      { title: 'Gameweek Recap', desc: 'What just happened in FPL', href: './gameweek-recap' },
      { title: 'Price Change Predictions', desc: 'See who may rise or fall', href: './price-changes' },
      { title: 'Injury Risk Digest', desc: 'Who may miss the next match', href: './fpl-injury-news' },
    ],
  },
  {
    id: 'explore',
    label: 'Explore',
    links: [
      { title: 'Players', desc: 'Search players and season stats', href: '#' },
      { title: 'League Table', desc: 'See the table and recent form', href: './league-table' },
      { title: 'Gameweek Tips', desc: 'Picks and advice for this gameweek', href: './fpl-gameweek-2-tips', gwTips: true },
    ],
  },
];

const MOBILE_FOOTER_LINKS = [
  { label: 'Pricing', href: './join', pricing: true },
  { label: 'About', href: '#' },
  { label: 'Contact', href: './contact' },
];

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function renderLink(link) {
  const attrs = [
    `class="mobile-menu__link"`,
    `href="${escapeHtml(link.href)}"`,
  ];
  if (link.auth) attrs.push('data-auth-tool');
  if (link.gwTips) {
    attrs.push('data-nav-gw-tips-link');
  }

  const titleAttrs = link.gwTips ? ' data-nav-gw-tips-label' : '';

  return `<li>
    <a ${attrs.join(' ')}>
      <span class="mobile-menu__link-title"${titleAttrs}>${escapeHtml(link.title)}</span>
      <span class="mobile-menu__link-desc">${escapeHtml(link.desc)}</span>
    </a>
  </li>`;
}

function renderSection(section) {
  const panelId = `mobile-panel-${section.id}`;
  const links = section.links.map(renderLink).join('');
  const note = section.note
    ? `<p class="mobile-accordion__note">${section.note}</p>`
    : '';

  return `<section class="mobile-accordion__item" data-mobile-accordion-item>
    <button
      type="button"
      class="mobile-accordion__trigger"
      data-mobile-accordion-trigger
      aria-expanded="false"
      aria-controls="${panelId}"
    >
      <span class="mobile-accordion__label">${escapeHtml(section.label)}</span>
      <span class="mobile-accordion__count">${section.links.length}</span>
      ${CHEVRON}
    </button>
    <div id="${panelId}" class="mobile-accordion__panel" data-mobile-accordion-panel hidden>
      <ul class="mobile-accordion__list">
        ${links}
      </ul>
      ${note}
    </div>
  </section>`;
}

export function buildMobileMenuNav(navRoot) {
  if (!navRoot || navRoot.dataset.mobileBuilt === '1') return;

  const sections = MOBILE_MENU_SECTIONS.map(renderSection).join('');
  const footer = MOBILE_FOOTER_LINKS.map((link) => {
    const attrs = [
      'class="mobile-menu__footer-link"',
      `href="${escapeHtml(link.href)}"`,
    ];
    if (link.pricing) attrs.push('data-pricing-nav');
    return `<a ${attrs.join(' ')}>${escapeHtml(link.label)}</a>`;
  }).join('');

  navRoot.innerHTML = `
    <div class="mobile-accordion" data-mobile-accordion>
      ${sections}
    </div>
    <div class="mobile-menu__footer-links">
      ${footer}
    </div>
  `;

  navRoot.dataset.mobileBuilt = '1';
}

export function initMobileAccordions(root, { reduceMotion = false } = {}) {
  const items = [...root.querySelectorAll('[data-mobile-accordion-item]')];

  function setOpen(item, open) {
    const trigger = item.querySelector('[data-mobile-accordion-trigger]');
    const panel = item.querySelector('[data-mobile-accordion-panel]');
    if (!trigger || !panel) return;

    item.classList.toggle('is-open', open);
    trigger.setAttribute('aria-expanded', String(open));
    panel.hidden = !open;
  }

  function closeAll(except = null) {
    items.forEach((item) => {
      if (item !== except) setOpen(item, false);
    });
  }

  items.forEach((item) => {
    const trigger = item.querySelector('[data-mobile-accordion-trigger]');
    if (!trigger) return;

    trigger.addEventListener('click', () => {
      const open = trigger.getAttribute('aria-expanded') !== 'true';
      if (open) closeAll(item);
      setOpen(item, open);
    });
  });
}
