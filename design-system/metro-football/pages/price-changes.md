# Price Change Predictions Page Overrides

> **PROJECT:** Metro Football  
> **Page Type:** Hub-style FPL price change list tool

> Rules here override `MASTER.md`.

---

## Purpose

Straight list of players close to a price rise or fall. Match Fantasy Football Hub structure and density. No long copy. No filler sections. No “Free for everyone.”

## Layout

- Title only: Fantasy Premier League price change predictions
- One white panel: Search + Position chips, then one table
- Columns: Player · Progress · Price · Price prediction · Hourly change · Change time
- Initial rows: 100 for the active position/search filter
- Load more: batches of 50 via `.mf-btn`
- Hide empty accuracy / KPI / how-it-works / tonight card grids

## Look

- Follow Hub table chrome on this page: soft blue active position chip, blue/pink progress pills, green Increase / red Decrease, orange Tonight warning
- Circular player photos
- Horizontal scroll on small screens

## Motion

- Light load progress only. Prefer quiet list updates. Honor `prefers-reduced-motion`.
- Import from `motion` CDN when animating. Never `framer-motion`.

## Copy

- We / Metro Football voice where needed
- No dashes, no exclamation marks, no AI filler
- No “Free for everyone.”

## Components

- Load more and site CTAs: `.mf-btn` only
