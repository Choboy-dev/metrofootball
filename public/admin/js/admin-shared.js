export async function admFetch(path, options = {}) {
  const res = await fetch('/api/admin/' + path, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
    ...options,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) {
    const err = new Error(data.error || 'Request failed');
    err.status = res.status;
    throw err;
  }
  return data;
}

export function fmtGbp(value) {
  return new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(Number(value || 0));
}

export function fmtUsd(value) {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value || 0));
}

export function fmtDate(value) {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

export function esc(text) {
  return String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

const CHART_VARIANTS = {
  signups: {
    stroke: '#dc2626',
    fill: 'rgba(220, 38, 38, 0.14)',
    point: '#dc2626',
  },
  revenue: {
    stroke: '#2563eb',
    fill: 'rgba(37, 99, 235, 0.14)',
    point: '#2563eb',
  },
  ai: {
    stroke: '#d97706',
    fill: 'rgba(217, 119, 6, 0.16)',
    point: '#d97706',
  },
};

function formatChartDate(value) {
  const d = new Date(`${value}T00:00:00Z`);
  if (Number.isNaN(d.getTime())) return String(value || '');
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
}

function niceAxisMax(value) {
  const max = Math.max(Number(value) || 0, 0);
  if (max <= 0) return 1;
  if (max <= 1) return 1;
  if (max <= 5) return Math.ceil(max);
  const magnitude = 10 ** Math.floor(Math.log10(max));
  const normalized = max / magnitude;
  const nice = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
  return nice * magnitude;
}

function axisTicks(max, count = 4) {
  const ticks = [];
  for (let i = 0; i <= count; i += 1) {
    ticks.push((max / count) * i);
  }
  return ticks;
}

function pickTickIndexes(length, maxTicks = 5) {
  if (length <= 1) return [0];
  const indexes = new Set([0, length - 1]);
  const steps = maxTicks - 1;
  for (let i = 1; i < steps; i += 1) {
    indexes.add(Math.round((i / steps) * (length - 1)));
  }
  return [...indexes].sort((a, b) => a - b);
}

function summarizeSeries(items, valueKey, formatValue, formatTotal) {
  const values = items.map((item) => Number(item[valueKey] || 0));
  const total = values.reduce((sum, value) => sum + value, 0);
  const max = Math.max(...values, 0);
  const peakIndex = values.indexOf(max);
  const activeDays = values.filter((value) => value > 0).length;
  const peakDate = peakIndex >= 0 ? items[peakIndex]?.date : '';
  const average = values.length ? total / values.length : 0;

  const parts = [
    `${formatTotal(total)} total`,
    activeDays ? `${activeDays} active day${activeDays === 1 ? '' : 's'}` : 'No activity yet',
  ];

  if (max > 0 && peakDate) {
    parts.push(`Peak ${formatValue(max)} on ${formatChartDate(peakDate)}`);
  } else if (total > 0) {
    parts.push(`Average ${formatValue(average)} per day`);
  }

  return {
    total,
    max,
    peakDate,
    activeDays,
    summary: parts.join(' · '),
  };
}

/**
 * SVG area chart for 30-day admin trends.
 */
export function renderTrendChart(container, options = {}) {
  if (!container) return;

  const {
    items = [],
    valueKey,
    variant = 'signups',
    formatValue = (value) => String(value),
    formatTotal = (value) => String(value),
    metaEl = null,
    title = 'Trend chart',
  } = options;

  const palette = CHART_VARIANTS[variant] || CHART_VARIANTS.signups;
  const values = items.map((item) => Number(item[valueKey] || 0));
  const stats = summarizeSeries(items, valueKey, formatValue, formatTotal);

  if (metaEl) metaEl.textContent = stats.summary;

  if (!items.length || stats.total <= 0) {
    container.innerHTML = `<div class="adm-chart__empty">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 19V5M4 19h16M8 15l3-3 3 2 4-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <p>No data in the last 30 days yet.</p>
    </div>`;
    container.setAttribute('role', 'img');
    container.setAttribute('aria-label', `${title}. No data in the last 30 days.`);
    return;
  }

  const width = 640;
  const height = 220;
  const pad = { top: 14, right: 16, bottom: 34, left: 52 };
  const chartW = width - pad.left - pad.right;
  const chartH = height - pad.top - pad.bottom;
  const yMax = niceAxisMax(stats.max);
  const yTickValues = axisTicks(yMax, 4);
  const xTickIndexes = pickTickIndexes(items.length, 5);

  const points = values.map((value, index) => {
    const x = pad.left + (index / Math.max(items.length - 1, 1)) * chartW;
    const y = pad.top + chartH - (value / yMax) * chartH;
    return { x, y, value, date: items[index].date };
  });

  const baselineY = pad.top + chartH;
  const linePath = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`).join(' ');
  const areaPath = `${linePath} L ${points[points.length - 1].x.toFixed(2)} ${baselineY} L ${points[0].x.toFixed(2)} ${baselineY} Z`;

  const gridLines = yTickValues.map((tick) => {
    const y = pad.top + chartH - (tick / yMax) * chartH;
    return `<line class="adm-chart__grid" x1="${pad.left}" y1="${y.toFixed(2)}" x2="${width - pad.right}" y2="${y.toFixed(2)}" />`;
  }).join('');

  const yLabels = yTickValues.map((tick) => {
    const y = pad.top + chartH - (tick / yMax) * chartH;
    return `<text class="adm-chart__axis-y" x="${pad.left - 8}" y="${y.toFixed(2)}" dominant-baseline="middle" text-anchor="end">${esc(formatValue(tick))}</text>`;
  }).join('');

  const xLabels = xTickIndexes.map((index) => {
    const point = points[index];
    return `<text class="adm-chart__axis-x" x="${point.x.toFixed(2)}" y="${height - 10}" text-anchor="middle">${esc(formatChartDate(point.date))}</text>`;
  }).join('');

  const markers = points.map((point) => {
    const isPeak = point.value === stats.max && stats.max > 0;
    const radius = isPeak ? 4.5 : 3;
    const opacity = point.value > 0 ? 1 : 0;
    return `<circle class="adm-chart__point${isPeak ? ' is-peak' : ''}" cx="${point.x.toFixed(2)}" cy="${point.y.toFixed(2)}" r="${radius}" opacity="${opacity}" stroke="${palette.point}" tabindex="0" role="button" aria-label="${esc(formatChartDate(point.date))}: ${esc(formatValue(point.value))}">
      <title>${esc(formatChartDate(point.date))}: ${esc(formatValue(point.value))}</title>
    </circle>`;
  }).join('');

  const peakLabel = stats.peakDate && stats.max > 0
    ? `<text class="adm-chart__peak" x="${points[values.indexOf(stats.max)].x.toFixed(2)}" y="${(points[values.indexOf(stats.max)].y - 10).toFixed(2)}" text-anchor="middle">${esc(formatValue(stats.max))}</text>`
    : '';

  container.innerHTML = `
    <div class="adm-chart__frame">
      <svg class="adm-chart__svg" viewBox="0 0 ${width} ${height}" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
        ${gridLines}
        <path class="adm-chart__area" d="${areaPath}" fill="${palette.fill}" />
        <path class="adm-chart__line" d="${linePath}" fill="none" stroke="${palette.stroke}" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
        ${markers}
        ${peakLabel}
        ${yLabels}
        ${xLabels}
        <line class="adm-chart__axis" x1="${pad.left}" y1="${baselineY}" x2="${width - pad.right}" y2="${baselineY}" />
      </svg>
      <div class="adm-chart__tooltip" data-adm-chart-tooltip hidden></div>
    </div>
    <details class="adm-chart__table">
      <summary>View daily data</summary>
      <table>
        <thead><tr><th>Date</th><th>Value</th></tr></thead>
        <tbody>${items.map((item) => `<tr><td>${esc(formatChartDate(item.date))}</td><td>${esc(formatValue(Number(item[valueKey] || 0)))}</td></tr>`).join('')}</tbody>
      </table>
    </details>
  `;

  container.setAttribute('role', 'img');
  container.setAttribute('aria-label', `${title}. ${stats.summary}`);

  const tooltip = container.querySelector('[data-adm-chart-tooltip]');
  const svg = container.querySelector('.adm-chart__svg');
  const pointEls = container.querySelectorAll('.adm-chart__point');

  const showTip = (event, pointEl) => {
    if (!tooltip || !pointEl) return;
    const label = pointEl.getAttribute('aria-label') || '';
    if (!label) return;
    tooltip.textContent = label;
    tooltip.hidden = false;
    const frame = container.querySelector('.adm-chart__frame');
    const frameRect = frame.getBoundingClientRect();
    const pointRect = pointEl.getBoundingClientRect();
    const left = pointRect.left - frameRect.left + pointRect.width / 2;
    const top = pointRect.top - frameRect.top - 8;
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
  };

  const hideTip = () => {
    if (tooltip) tooltip.hidden = true;
  };

  pointEls.forEach((pointEl) => {
    pointEl.addEventListener('mouseenter', (event) => showTip(event, pointEl));
    pointEl.addEventListener('focus', (event) => showTip(event, pointEl));
    pointEl.addEventListener('mouseleave', hideTip);
    pointEl.addEventListener('blur', hideTip);
  });

  svg?.addEventListener('mouseleave', hideTip);
}

/**
 * Horizontal bars for category breakdowns (AI by service).
 */
export function renderCategoryBars(container, options = {}) {
  if (!container) return;

  const {
    items = [],
    formatValue = (value) => String(value),
    metaEl = null,
    emptyLabel = 'No data yet.',
  } = options;

  const rows = items
    .map((item) => ({
      label: String(item.label || ''),
      value: Number(item.value || 0),
      sub: item.sub ? String(item.sub) : '',
    }))
    .filter((item) => item.label)
    .sort((a, b) => b.value - a.value);

  const total = rows.reduce((sum, row) => sum + row.value, 0);

  if (metaEl) {
    metaEl.textContent = total > 0
      ? `${formatValue(total)} total · ${rows.length} service${rows.length === 1 ? '' : 's'}`
      : 'Last 30 days · USD';
  }

  if (!rows.length || total <= 0) {
    container.innerHTML = `<div class="adm-chart__empty adm-chart__empty--compact"><p>${esc(emptyLabel)}</p></div>`;
    return;
  }

  const max = Math.max(...rows.map((row) => row.value), 0.0001);
  const colors = ['#2563eb', '#dc2626', '#d97706', '#0f766e', '#7c3aed', '#475569'];

  container.innerHTML = `<div class="adm-hbars" role="list">${rows.map((row, index) => {
    const pct = Math.max(4, Math.round((row.value / max) * 100));
    const color = colors[index % colors.length];
    const share = total > 0 ? Math.round((row.value / total) * 100) : 0;
    return `<div class="adm-hbars__row" role="listitem">
      <div class="adm-hbars__label">
        <span class="adm-hbars__name">${esc(row.label)}</span>
        ${row.sub ? `<span class="adm-hbars__sub">${esc(row.sub)}</span>` : ''}
      </div>
      <div class="adm-hbars__track" aria-hidden="true">
        <span class="adm-hbars__bar" style="width:${pct}%;background:${color}"></span>
      </div>
      <div class="adm-hbars__value">
        <strong>${esc(formatValue(row.value))}</strong>
        <span>${share}%</span>
      </div>
    </div>`;
  }).join('')}</div>`;
}
