# FPL Injury News Page Overrides

> **PROJECT:** Metro Football  
> **Page Type:** Weekly public FPL injury briefing (Injury Risk Digest)

> Rules here override `MASTER.md`.

---

## Purpose

One live SEO article at `/fpl-injury-news`. Same post for every visitor. Free. **Written once per gameweek** via `scripts/publish-injury-news.php` (page visits never regenerate).

## Cost controls

- 6 to 8 player stories (cap 8, min 6)
- Full Tavily research for every player (club/PL, trusted desks, club social snippets)
- Advanced search; tier 1 official/club first, tier 2 desks second
- Research stored once per gameweek in `storage/cache/injury-news-research-v1-{gw}.json`
- Rewrites and tone edits use stored research + OpenAI only (no new Tavily)
- Published post stored ~120 days; page visits never regenerate
- HTTP generate/edit requires `INJURY_NEWS_GENERATE_SECRET`

## Operator commands

```bash
php scripts/publish-injury-news.php --research
php scripts/publish-injury-news.php --write
php scripts/publish-injury-news.php --edit "your note"
php scripts/publish-injury-news.php --force-research
```

## Layout

- Title first: focus keyphrase **FPL injury news**
- Clean crawler friendly TOC near the top
- Deep player sections (6 to 8)
- Hub style stats strip under each player head
- Watch list + short closing
- Empty state when this gameweek is not published yet
- No red eyebrow section labels
- No KPI strip clutter in the first viewport beyond title, lead, and TOC

## Look

- Light grey page atmosphere with soft red wash
- White article panels, circular photos, compact stats grid
- Status pills for injured / doubtful / suspended
- Sticky TOC on desktop
- Internal links to price changes and gameweek recap

## Motion

- `inView` + opacity/y reveals for intro, players, watch list
- Stagger stats cells lightly
- Honor `prefers-reduced-motion`
- Import from `motion` CDN. Never `framer-motion`

## Copy

- We / Metro Football voice
- Simple enough for a 10 year old
- No dashes, no exclamation marks, no AI filler
- Meta always: `FPL injury news for Gameweek X. See who is out, who may return, and what Metro Football thinks managers should do before the deadline.`

## Components

- Site CTAs: `.mf-btn` only
- Research: Tavily; writing: OpenAI; ownership/status: FPL API
