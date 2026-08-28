# Fixture Difficulty Ticker Page Overrides

> **PROJECT:** Metro Football
> **Page Type:** Premium planning tool (official FPL fixture difficulty)

> Rules here override `MASTER.md` for `/fixture-difficulty`.

---

## Purpose

Show every Premier League club’s upcoming fixture run with official FDR colours so managers can spot easy and hard stretches in one glance.

## Layout

- Natural page like AI Tips (wide shell, soft panels, no outer bordered modal card)
- First viewport: brand-consistent header (gameweek kicker + title + one short sentence), FDR legend, horizon + sort controls
- Main body: one team card per club, sorted by average FDR
- No red eyebrow labels
- No dashboard clutter (no stats strip of unrelated KPIs in the hero)

## Team cards

- Club badge + short name + average FDR for the selected horizon
- Upcoming fixtures as a neat ticker row: opponent, home/away, FDR colour cell
- Blank gameweeks shown clearly when a club has no fixture
- Soft fills / subtle inset borders matching AI Tips cards

## Controls

- Horizon: next 3 / 5 / 8 gameweeks
- Sort: easiest first, hardest first, A to Z
- Default: 5 gameweeks, easiest first

## Motion

- Soft staggered card reveal with Motion `animate` + `stagger`
- Honor `prefers-reduced-motion`

## Copy

- We / Metro Football voice
- Simple enough for a 10 year old
- No dashes, no exclamation marks
- Example lead: "See which clubs have easy games next, and which ones face a tough run."

## Access

- Premium members only. Guests go to login. Non-premium see the upgrade lock. API enforces the same gate.
