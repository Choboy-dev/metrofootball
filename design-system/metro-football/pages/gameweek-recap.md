# Gameweek Recap Page Overrides

> **PROJECT:** Metro Football  
> **Page Type:** Auto weekly report (not a blog)

> Rules here override `MASTER.md`.

---

## Purpose

Free for all managers. No login and no premium required. Shared weekly FPL debrief after a gameweek finishes. Computed from official FPL data only (no OpenAI). Optional personal strip when a linked team is signed in. Points into Pick and other tools.

## Layout

- One composition report, not a dashboard farm
- Max width ~1120px for reading comfort
- Section stack: wrap (KPIs + summary with match results then highlight cards) → your GW → top scorers → captains → bonus → surprises → popular → next step
- No GW switcher; always latest finished gameweek
- No red eyebrow section labels
- Captains: row of 4 story cards (most owned, most captained, best captain, worst captains list)
- Surprises: 5 player cards for more points than predicted, then up to 5 equal-width surprise winner fixture cards (or a calm empty state)
- Popular players: two rows of 5 story cards (best performing, worst performing among players owned by 15%+)
- Match results live under Gameweek Summary (before highlight player cards); blank side when a team scored none
- No “What to do next” footer CTAs on this page
- Free FPL compute + disk cache only. No OpenAI on this page.
- Cards only where they hold interaction (filters, personal strip, share)

## Typography

- Titles / display numbers: Google Sans (`--font-display`)
- Body / filters / table chrome: Inter (`--font-body`)
- Brand “Metro Football” stays visible in site header; page H1 is the gameweek name

## Colour

- Follow Master Metro red tokens
- Positive deltas: calm green; negative: muted ink (not alarmist)
- Bonus / dream team accents use primary red sparingly

## Motion

- Import from `motion` (CDN ESM). Never `framer-motion`
- Section reveals via `inView` + opacity/y; list stagger on top scorers
- Honor `prefers-reduced-motion`
- Progress bar pattern matches League Table / Live

## Copy

- We / Metro Football voice
- Simple enough for a 10 year old
- No dashes, no exclamation marks, no AI filler
- Numbers first, then one short sentence

## Components

- Mandatory `.mf-btn` CTAs only
- Filters: position chips + ownership band + sort
- Share: copy plain text summary
- Personal strip when `fpl_team_id` is linked

## Anti patterns

- No newsroom journalism tone
- No emoji as icons
- No cramming xG full report / BPS full report into this page (sibling reports later)
- No inset hero collage; keep report header text led with subtle pitch atmosphere
