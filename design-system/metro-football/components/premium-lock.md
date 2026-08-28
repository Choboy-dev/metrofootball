# Premium lock card

Shown on My Team tools that require an active Premium subscription.

## Free / unpaid

Hub-style gate adapted to Metro tokens:

- Dark ink banner: Exclusive content for members only
- Pale body panel with lock icon
- Title: Unlock exclusive content
- Lead: Upgrade for access to this tool
- CTA: `.mf-btn.mf-btn--primary` Upgrade → `/join` (or `/payment` if Premium unpaid)

## Active Premium

Hide the lock card. Show a short unlocked shell until the tool UI ships.

## Pages

- `/ai-tips` (Premium personal tips pack)
- `/ai-transfers` redirects to `/ai-tips`
- `/ai-teams` (Premium)
- `/mini-leagues` (Premium)

Free tools such as `/rate-my-team` do not use this lock (`data-tool-access="free"`).

Logic: `public/js/team-tool.js` / `public/js/ai-tips.js` (`plan === premium` and `subscription_status === active`).
