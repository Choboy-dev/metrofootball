# Players Club Page Overrides

> **PROJECT:** Metro Football
> **Generated:** 2026-09-06
> **Page Type:** Club squad hub (`/players/{club}`)

> Rules in this file **override** the Master file (`design-system/metro-football/MASTER.md`).
> Only deviations from the Master are documented here.

---

## Page-Specific Rules

### Layout Overrides

- Reuse the existing Players index visual language (`.players-page`, dark hero, club badge grid, roster rows).
- Do not introduce a second card system or a competing colour palette.
- Max content width matches Players (`--pp-shell`).

### Spacing Overrides

- Position groups stack with ~1.15rem top margin.
- Roster rows stay list-dense (same as `.pp-roster-player`).

### Typography Overrides

- Keep Google Sans for titles and Inter for UI (Master fonts). Ignore generic skill suggestions (Bebas / Source Sans).

### Color Overrides

- Keep Players tokens (`--pp-hero-bg`, `--pp-accent` cyan, light page bg). Do not switch to Master red backgrounds on this surface.

### Component Overrides

- Club chips are real `<a class="pp-club">` links to hubs.
- Squad rows are real `<a class="pp-roster-player">` links to profiles.
- CTAs use `.mf-btn` sliding-icon pills only.
- Breadcrumbs: Players → Club → Player (three levels on profiles).

### Motion

- Optional stagger on roster rows via Motion `animate` + `stagger`.
- Respect `prefers-reduced-motion` (skip stagger).

### SEO

- Full squad must be server-rendered HTML links (no JS-only discovery).
- Club hubs listed in `sitemap-players.php` alongside player profiles.
