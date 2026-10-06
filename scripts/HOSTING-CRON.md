# Metro Football — hosting and cron notes

Last updated: 29 August 2026

## Installed on Hostinger (u665258891)

| UID | Schedule (UTC) | Script | Purpose |
|-----|----------------|--------|---------|
| `6HUpptjEyj` | `0 6 * * *` | `scripts/cron-subscriptions.php` | Expire overdue Premium, renewal emails, expired emails |
| `VQMWSqPs7U` | `15 4 * * *` | `scripts/build-player-ai-scores.php` | Nightly player projections for Pick, profiles, AI tools |
| `fX2Hlt7yiD` | `0 7 * * 2` | `scripts/publish-gameweek-tips.php` | Auto publish Gameweek Tips when a new post is needed (Tue) |
| `CVVidHCFLE` | `30 7 * * 1,4` | `scripts/publish-injury-news.php` | Auto publish Injury Risk Digest when needed (Mon/Thu) |

Log files (server):

- `/tmp/mf-subscriptions.log`
- `/tmp/mf-ai-scout.log`
- `/tmp/mf-gw-tips.log`
- `/tmp/mf-injury-news.log`

Check crontab:

```bash
crontab -l
```

## What each job does

### Required for billing

**`cron-subscriptions.php`** — Without this, Premium users who never log in may stay active past expiry until their next visit. Reminder and expired emails also stop.

### Required for accurate projections

**`build-player-ai-scores.php`** — Rebuilds shared player projection scores for the planning gameweek using full FPL history. Pick, player profiles, and Premium tools still work without it (they fall back to lighter on demand scoring), but nightly runs give better predicted points and faster Pick loads.

### Optional content automation

**`publish-gameweek-tips.php`** and **`publish-injury-news.php`** — Safe to schedule. Each script exits without API spend if the current gameweek is already published. Without these crons you must run the scripts manually or use the secret protected generate API endpoints.

### Do NOT schedule by default

| Script | Why |
|--------|-----|
| `scripts/cron-warm-caches.sh` | Heavy FPL calls; manual or pre demo only |
| `scripts/warm-pick-cache.php` | Same; at most hourly if traffic grows |
| `scripts/warm-all-pick-caches.php` | Manual only |
| `scripts/build-opta-player-links.php` | One off or occasional rebuild |
| `scripts/seed-*.php` | Setup only, never cron |

## No cron needed (works on page visit or webhook)

| Feature | How it runs |
|---------|-------------|
| Gameweek Recap | Built and cached when `/api/fpl/gameweek-recap` is called |
| Price Change Predictions | Built and cached when the price changes API is called |
| Flutterwave payments | Webhook at `/api/billing/webhook` |
| Metro Mail scheduled send | Resend API `scheduled_at` handles delivery |
| Metro Mail inbound sync | Webhook at `/webhooks/resend-mail.php` plus optional cron backfill (see below) |
| Live team refresh | Browser calls `/api/fpl/live-refresh` after page load |

## Metro Mail inbound backfill (recommended)

If Resend receives mail but Metro Mail inbox is empty, run a backfill:

```bash
python3 scripts/sync-mail-inbound.py
```

On Hostinger, schedule every 15 minutes as a safety net when webhooks are missed:

```bash
*/15 * * * * cd /home/u665258891/domains/metrofootball.co.uk/public_html && /usr/bin/python3 scripts/sync-mail-inbound.py >> /tmp/mf-mail-sync.log 2>&1
```

Requires `APP_KEY` in `config/.env`. The script calls `POST /api/mail/sync-inbound.php` with a signed token.

## Not built yet (cron would not help)

| Feature | Status |
|---------|--------|
| Mini league rival **email** alerts | UI toggle exists; no outbound alert job yet |
| Laravel queue worker | Plain PHP app; no queue daemon |

## Manual commands

```bash
cd /home/u665258891/domains/metrofootball.co.uk/public_html

# One user pick cache warm
php scripts/warm-pick-cache.php ENTRY_ID

# Force injury digest (research + write)
php scripts/publish-injury-news.php

# Rewrite tips from stored research only
php scripts/publish-gameweek-tips.php --write
```

## Pick load behaviour

- **Shell** (`/api/fpl/pick`): fast squad load, session released before FPL work
- **Enrich** (`/api/fpl/pick-enrich`): projections and transfers, deferred in browser
- **Pick entry file cache**: 10 min fresh, 2 hr stale fallback

## Resource limits (Hostinger)

Do not run cache warmers every 5–15 minutes alongside heavy traffic. Typical shared hosting caps RAM and PHP workers.
