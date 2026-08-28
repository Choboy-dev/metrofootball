# League Table Page Overrides

> **PROJECT:** Metro Football
> **Page Type:** Free Explore page (live Premier League table + player leaders)

> Rules here override `MASTER.md` for `/league-table`.

---

## Purpose

Show the live Premier League table and season leaders for goals, assists, and clean sheets in one calm, premium view.

## Layout

- Soft page shell matching AI Tips / Fixture Difficulty (soft gradient page, wide container)
- Header: title + one short lead only (no red eyebrows, no hero clutter)
- Segmented view switch: Table · Goals · Assists · Clean sheets
- One job per view
- Soft inset panels, not heavy cards

## Table

- Rank, badge, club, P W D L GF GA GD Pts, last five form pills
- Soft zone rails: top four, Europe places, bottom three
- Horizontal scroll on small screens; keep club column sticky if needed
- Tabular nums, Inter for UI, Google Sans for title and big numbers

## Leaders

- Ranked player rows: face, name, club, value
- Same soft panel language as table
- Face fallback to shirt or initial if CDN fails

## Motion

- Soft stagger on rows with Motion `animate` + `stagger`
- Honour `prefers-reduced-motion`

## Copy

- We / Metro Football voice
- Simple enough for a 10 year old
- No dashes, no exclamation marks
- Lead example: "Live Premier League standings, plus the players leading goals, assists, and clean sheets."

## Access

- Free Explore page. Login optional for site nav only.
