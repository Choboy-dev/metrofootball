# Pick / Rate My Team

> Overrides Master for `/pick` (also `/rate-my-team` → redirect).

## Purpose

Plan the next gameweek squad: rate strength, edit lineup, make position-safe transfers, and see bank / hit points. Same tool as Rate My Team in the Tools menu.

## Cheap AI architecture

Numeric projections come from **`PlayerProjectionEngine` (`xp-engine-v10`)**, a position-aware expected-points model. OpenAI is not used for raw point totals.

1. `php scripts/build-player-ai-scores.php` builds rich player cards (bootstrap + element-summary history) and writes engine projections to `player_ai_scores`
2. `/api/fpl/pick` joins the scout sheet and composes team strength / predicted points locally (`TeamScoreComposer`)
3. Transfers/subs recompute instantly in JS from `player.ai.projections`
4. Premium unlocks predicted points + per-fixture projections + suggested transfers
5. `/api/fpl/pick-tip` is rate-limited (3/day) narrative only

Squad strength % uses captain-adjusted XI expected points. Bands: 62–65 → 89%, then +1% per 4 pts up to 100 → 99%. Below 62 uses the same 4-pt step downward.

### Engine inputs (every player, every position)

- Minutes certainty + availability / news
- Position rules: appearance, goals (6/5/4), assists, clean sheets (GK/DEF/MID), GC deductions, GK saves, bonus
- Rates: xG90, xA90, xGC90, saves90 with early-season shrink (one haul cannot become xG90 ≈ 1.0)
- Role: threat / ICT / creativity / influence fill thin rates only (do not stack on strong xG)
- Set pieces: moderate pens / corners / FK order EV
- Fixture: FDR weighted harder for attack; hard games cut premiums clearly
- Opponent + team form from finished fixtures
- Soft H2H (needs 2+ games); haul regression on thin recent samples
- Soft GW1 blend with FPL `ep_next` (stronger when minutes sample is thin)
- Soft ceiling compresses extreme single-GW projections
- Secure starters: matchup floor on good fixtures, reduced on FDR 4–5
- Position scorecards (hub-style):
  - GK: CS, GC/xGC, saves (+ pen saves)
  - DEF: CS, GC/xGC, aerial/attack threat, set pieces, defensive contribution
  - MID: creativity/xA, xG/threat, set-piece duties, mild defensive contribution
  - FWD: xG, threat, pens, xA (no CS path)
- Hub-band align: FDR + opponent strength × position-specific quality (no player-name overrides)

If the scout sheet is empty or still on an old model, Pick auto-rebuilds `xp-engine-v10` from bootstrap fields.

## Scout inputs (what we feed)

From official FPL only:

- Minutes certainty: minutes, starts, starts_per_90, last up to 5 GW history (mins/pts/CS/goals/assists/GC/bonus), availability + news
- Advanced rates: xG/xA/xGI/xGC season + per 90, ICT, saves_per_90, CS
- Team strength: attack/defence home/away from FPL team table
- Fixtures: FDR, kickoff, home/away, opponent attack/defence, congestion hours, blanks/doubles
- Market: ownership %, transfers in/out this GW, price deltas
- Set pieces: penalties / corners / free-kick order
- News: FPL `news`, status, chance of playing, scout_risks
- Calibration: up to 3 past seasons from element-summary `history_past`
- Tip path only: bank, free transfers, hit points, chips, overall rank

Still **not** available from free FPL (do not invent): progressive carries/passes, weather, live press conference transcripts, Opta goals prevented beyond FPL fields.

## Access

- Free once `fpl_team_id` is linked
- Missing team → `/link-team`
- Unpaid Premium → `/payment` first

## Free vs locked

Free:

- Shared-scout team strength (GW / 5GW)
- Bank, pitch, formation-safe subs, same-position transfers, hit points
- Player sidebar with locked per-fixture projections (3 padlocks)

Premium:

- Predicted points (GW / 5GW)
- Per-fixture projections on pitch cards and sidebar
- FPL-style FDR colour boxes under each fixture (1–5 official palette)
- Suggested transfers (non-GPT ranking)
- Get AI tip CTA → `/ai-tips` personal tips pack (no daily tip counter)

## Rules

- Formation must stay valid: 1 GK, ≥3 DEF, ≥2 MID, ≥1 FWD
- GK only swaps with GK
- Transfers only within the same position
- Max 3 players per club
