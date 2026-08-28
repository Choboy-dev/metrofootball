# Metro Football Select (`mf-select`)

Reusable custom dropdown. Never use native `<select>` in dashboard tools.

## Markup

```html
<div class="mf-select mf-select--inline" data-mf-select data-value="easiest" id="fdr-sort">
  <span class="mf-select__label" id="fdr-sort-label">Sort</span>
  <button
    type="button"
    class="mf-select__trigger"
    data-mf-select-trigger
    aria-labelledby="fdr-sort-label fdr-sort-value"
  >
    <span class="mf-select__value" id="fdr-sort-value" data-mf-select-value>Easiest first</span>
  </button>
  <div class="mf-select__panel" data-mf-select-panel hidden>
    <button type="button" class="mf-select__option is-selected" data-mf-select-option data-value="easiest">Easiest first</button>
    <button type="button" class="mf-select__option" data-mf-select-option data-value="hardest">Hardest first</button>
    <button type="button" class="mf-select__option" data-mf-select-option data-value="name">A to Z</button>
  </div>
</div>
```

## Variants

- `mf-select--inline` label beside trigger (toolbars)
- `mf-select--block` label above, full width (filter panels)

## JS

```js
import { initMfSelects, getMfSelectValue, setMfSelectValue } from './mf-select.js';

initMfSelects();
root.addEventListener('mf-select:change', (e) => {
  // e.detail.value, e.detail.label
});
setMfSelectValue(root, 'hardest');
```

## Rules

- Soft white trigger, soft panel, Inter labels, Google Sans not required
- Keyboard: arrows, Enter/Space, Escape, Home/End
- Honour `prefers-reduced-motion`
- Only one menu open at a time
