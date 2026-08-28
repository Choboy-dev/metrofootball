# AI Tips Page Overrides

> **PROJECT:** Metro Football
> **Page Type:** Premium personal advisory (team unique)

> Rules here override `MASTER.md` for `/ai-tips`.

---

## Purpose

Five tips unique to each Premium manager’s linked squad for the current gameweek. Numbers from the scout sheet. AI narrates once per user per gameweek. Never invent players or scores.

## Cache / cost

- Tips are fixed for the gameweek (`userId` + `eventId`)
- No refresh CTA
- No force-refresh API param
- Long TTL; next gameweek uses a new cache key

## Sections (fixed order)

1. **AI Transfers**
2. **Who to captain**
3. **Player drop watch**
4. **Chip advice**
5. **Differential finder**

## Layout

- Natural page, not a bordered modal card
- Wide shell (`max-width: 72rem`), sits on page background
- Page header: gameweek kicker + `AI Tips for [Team Name]` only
- Tip blocks as stacked sections with spacing / soft dividers, not outer borders
- Recommendation rows may use soft fills without heavy frames
- No red eyebrow labels
- No Refresh / Open Pick CTAs on this page

## Who to captain visual

- Two separate cards side by side: Captain left, Vice right
- Same circular Premier League headshot treatment as AI Transfers
- Captain card highlighted (primary tint + ring); vice softer slate tint
- Simple per-card reason sentences (no AI overlay note)
- Fixture on its own line under club · position, with FDR colour badge
- Rebuilds from scout on cache hits without OpenAI re-spend

## Player drop watch visual

- Up to four separate cards in a responsive grid
- Face, club · position, next five fixtures with FDR under each label
- One simple centred reason sentence per card
- Rebuilds from scout on cache hits without OpenAI re-spend

## Chip advice visual

- Full-width status panel (not a bare text line)
- Hold / empty: soft slate panel, shield icon, Hold badge, “No chip needed right now”
- Play: primary-tinted panel, play icon, Play badge, chip name + simple reason
- Reason lives inside the panel (no separate bland note above)

## Differential finder visual

- Same card grid as Player drop watch
- Face, club · position, next five fixtures with FDR under each label
- One simple reason sentence (ownership, predicted points, price)
- Rebuilds from scout on cache hits without OpenAI re-spend

## AI Transfers visual

- Side-by-side Premier League headshots with a centre switcher orb (swap arrows)
- Out player: greyscale, muted name with strike, soft slate tag
- In player: full colour, primary ring glow, solid In tag
- Soft dual-tone arena fill (slate → primary tint), not a bordered card
- One plain centred sentence under each swap (price + projection vs the out player)
- No meta chips (pos / price / certainty pills)
- Two different outgoing players (never two options for the same out)
- Transfer section rebuilds from scout on cache hits; no OpenAI re-spend for copy
- Motion: staggered reveal + face/switcher entrance; honor `prefers-reduced-motion`

## Motion

- Soft `inView` opacity/y on sections
- Honor `prefers-reduced-motion`

## Copy

- We / Metro Football voice
- Simple enough for a 10 year old
- No dashes, no exclamation marks
- No status fluff (“Fresh tips ready…”)
- No “Live for … Built from …” lead

## Anti-patterns

- Outer bordered white card wrapping the whole page
- Narrow 42–48rem content column for this tool
- Refresh buttons that burn OpenAI credits
- Daily tip counters
