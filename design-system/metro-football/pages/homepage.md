# Homepage Page Overrides

> **PROJECT:** Metro Football
> **Page Type:** Marketing homepage

> Rules here override `MASTER.md` for the homepage only.

---

## Layout

- Hero: split LandingAccordionItem layout (copy left, 5-tool image accordion right)
- Content max width ~72rem
- Interactive accordion cards in the hero; pricing/tools sections may use light containers

## Density

- Spacious marketing spacing (not dashboard density)

## Motion

- Hero entrance with Motion `animate` + `stagger`
- Section reveals with Motion `inView` + `animate`
- Tool grid stagger on enter
- Honor `prefers-reduced-motion`

## Page-Specific Components

- Hero uses LandingAccordionItem split layout: copy left, 5-tool image accordion right
- Desktop default open card: center index `2` (Rate My Team)
- Card images use placeholders until final assets are supplied (`img[data-hero-image]`)
- CTAs remain sliding-icon pill buttons (`.mf-btn`) only

## Typography

- Titles: Google Sans
- Body and UI text: Inter

## Copy

- Company voice: We or Metro Football (never lone I)
- Simple grammar
- No dashes and no exclamation marks
- Do not use red/eyebrow labels or report category tags. Heads start with the real title only.
