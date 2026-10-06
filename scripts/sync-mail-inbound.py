#!/usr/bin/env python3
"""Backfill Metro Mail inbox from Resend received emails."""

from __future__ import annotations

import hashlib
import hmac
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def main() -> int:
    env = load_env(ROOT / 'config/.env')
    app_key = env.get('APP_KEY', '')
    app_url = env.get('APP_URL', 'https://metrofootball.co.uk').rstrip('/')
    if not app_key:
        print('APP_KEY missing in config/.env', file=sys.stderr)
        return 1

    token = hmac.new(app_key.encode(), b'metro-mail-sync-inbound', hashlib.sha256).hexdigest()
    url = f'{app_url}/api/mail/sync-inbound.php?pages=10'
    req = urllib.request.Request(
        url,
        data=b'{}',
        method='POST',
        headers={
            'Content-Type': 'application/json',
            'X-Mail-Sync-Token': token,
        },
    )

    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            body = resp.read().decode()
    except urllib.error.HTTPError as e:
        print(e.read().decode(), file=sys.stderr)
        return 1

    data = json.loads(body)
    print(json.dumps(data, indent=2))
    return 0 if data.get('ok') else 1


if __name__ == '__main__':
    raise SystemExit(main())
