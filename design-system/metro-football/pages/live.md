# Live page

> Overrides Master for `/live` after an FPL team is linked.

## Purpose

Show the manager's live squad on a pitch with GW points, ranks, captain, and bench.

## Access

- Free and Premium once `fpl_team_id` is linked
- Missing team → `/link-team`
- Unpaid Premium → `/payment` first

## Data

API: `GET /api/fpl/live`

Uses FPL:
- `/bootstrap-static/` (cached)
- `/entry/{id}/`
- `/entry/{id}/history/`
- `/entry/{id}/event/{gw}/picks/`
- `/event/{gw}/live/` (short cache)

## Layout

- Same site header + compact My Team tabs (Live active)
- Four summary stats
- Pitch with GKP / DEF / MID / FWD rows
- Bench strip with position labels
- Club-coloured jersey SVGs, C/V badges, event icons

## Motion

- Tab and player chip fade/stagger via Motion `animate` + `stagger`
- Load progress uses Motion number `animate` → `scaleX` fill (top rail + optional panel)
- Honor `prefers-reduced-motion`
- Progress UI delayed ~120ms so cache HITs do not flash a bar

## Loading feedback

- Fixed 3px brand gradient rail at the top of the viewport
- Inline panel (label + percent + track) only when no local snapshot yet
- Determinate progress with trickle while waiting; never regresses; caps near 90% until done
- `aria-busy` on main; `role="progressbar"` with `aria-valuenow`
