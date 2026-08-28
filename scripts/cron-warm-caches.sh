#!/bin/bash
# Warm FPL pick + score caches. Schedule every 15 minutes before deadlines, e.g.:
# */15 * * * * /home/u665258891/domains/metrofootball.co.uk/public_html/scripts/cron-warm-caches.sh >> /tmp/mf-warm.log 2>&1

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
php "$ROOT/scripts/warm-all-pick-caches.php"
