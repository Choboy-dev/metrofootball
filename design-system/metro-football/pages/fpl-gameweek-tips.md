# FPL Gameweek Tips Page Overrides

> **PROJECT:** Metro Football  
> **Page Type:** Weekly public FPL tips briefing (Gameweek X Tips)

> Rules here override `MASTER.md`.

---

## Purpose

SEO articles at `/fpl-gameweek-{X}-tips`. Each gameweek is archived on the server. The nav always links to the **upcoming** gameweek URL. Free. **Written once per gameweek** via `scripts/publish-gameweek-tips.php`.

## Cost controls

- 8 tip stories per gameweek (fixed roles)
- FPL compute for candidate selection first
- Tavily: 2 searches per tip player + up to 4 fixture preview searches
- Research stored in `storage/cache/gameweek-tips-research-v1-{gw}.json`
- Rewrites and edits use stored research + OpenAI only
- Page visits never regenerate
- HTTP generate/edit requires `GAMEWEEK_TIPS_GENERATE_SECRET`

## Operator commands

```bash
php scripts/publish-gameweek-tips.php --research
php scripts/publish-gameweek-tips.php --write
php scripts/publish-gameweek-tips.php --edit "your note"
php scripts/publish-gameweek-tips.php --force-research
```

## Layout

- Focus keyphrase: **FPL Gameweek X tips**
- TOC, quick picks strip (4 cards), 8 deep tip sections, archive links, closing
- Reuses `fin-*` article styling from Injury Risk Digest
- Empty state when this gameweek is not published yet

## Copy

- Plain English for a 10 year old
- We / Metro Football voice
- What do we suggest on every tip
- One inline source link per tip in body text
