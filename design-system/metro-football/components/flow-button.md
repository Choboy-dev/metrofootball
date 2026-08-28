# App button (mandatory)

Source pattern: ButtonWithIconDemo (pill + sliding ArrowUpRight bubble).

## Markup

```html
<button type="button" class="mf-btn mf-btn--ink">
  <span class="mf-btn__label">Let's Collaborate</span>
  <span class="mf-btn__icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </span>
</button>
```

## Variants

| Class | Use |
|-------|-----|
| `mf-btn--ink` | Default on light surfaces |
| `mf-btn--primary` | Brand / primary CTA |
| `mf-btn--accent` | Secondary warm CTA |
| `mf-btn--light` | On dark / pricing panels |
| `mf-btn--sm` | Compact (nav) |
| `mf-btn--block` | Full width |

This is the only button language in the product.
