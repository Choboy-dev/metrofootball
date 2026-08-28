# Metro Football — hosting and cron notes

Last updated: 28 August 2026

## Cron jobs (recommended)

Check what is installed on the account:

```bash
crontab -l
```

### Safe to run

| Schedule | Command | Purpose |
|----------|---------|---------|
| `15 4 * * *` | `php scripts/build-player-ai-scores.php` | Nightly AI scout scores for pick enrich |

Example (adjust paths):

```cron
15 4 * * * /usr/bin/php /home/USER/domains/metrofootball.co.uk/public_html/scripts/build-player-ai-scores.php >> /home/USER/domains/metrofootball.co.uk/public_html/storage/logs/ai-scout.log 2>&1
```

### Do NOT run frequently on shared hosting

Avoid scheduling pick cache warmers every 5–15 minutes. Each warm run holds PHP workers and calls FPL.

| Script | When to use |
|--------|-------------|
| `scripts/warm-pick-cache.php` | Manual only, or at most once per hour if traffic grows |
| `scripts/warm-all-pick-caches.php` | Manual only before a demo or load test |
| `scripts/cron-warm-caches.sh` | **Not installed in crontab by default** |

## Manual cache warm (demo / after deploy)

```bash
cd /home/USER/domains/metrofootball.co.uk/public_html
php scripts/warm-pick-cache.php ENTRY_ID
```

## Pick load behaviour (after Aug 2026 tuning)

- **Shell** (`/api/fpl/pick`): fast squad load, session released before FPL work, HTTP cache 60s
- **Enrich** (`/api/fpl/pick-enrich`): AI scores and transfers, deferred in browser until idle, HTTP cache 120s
- **503 responses**: no automatic retries (avoids worker pile-up on Hostinger)
- **Pick entry file cache**: 10 min fresh, 2 hr stale fallback (`PickTeamService`)

## Resource limits (Hostinger Business / Unlimited)

“Unlimited” refers to websites and bandwidth, not CPU/RAM/processes. Typical caps:

- RAM ~3 GB
- Max processes ~120
- PHP workers ~60

Do not run Cursor remote IDE + heavy pick warm cron + multiple pick retries at the same time.

## Footer Terms link batch fix

```bash
bash scripts/fix-terms-footer.sh
```
