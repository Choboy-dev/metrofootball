#!/usr/bin/env python3
"""Inject footer social markup and sync hrefs from config/.env."""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TAGLINE = 'Metro Football builds FPL tools and auto reports for managers who want clear answers before the deadline.'
FOOTER_SNIPPET = (ROOT / 'public/partials/mf-social.html').read_text()
MARKER = 'mf-social mf-social--footer'


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


def apply_env_urls(html: str, env: dict[str, str]) -> str:
    mapping = [
        ('aria-label="Metro Football on Facebook"', env.get('SOCIAL_FACEBOOK_URL', '')),
        ('aria-label="Metro Football on X"', env.get('SOCIAL_X_URL', '')),
        ('aria-label="Metro Football on Instagram"', env.get('SOCIAL_INSTAGRAM_URL', '')),
    ]

    for label, url in mapping:
        if not url or not url.startswith('http'):
            continue
        pattern = rf'(<a class="mf-social__link" href=")[^"]*(" {re.escape(label)}[^>]*>)'
        html, count = re.subn(pattern, rf'\1{url}\2', html)
        if count == 0:
            pass
    return html


def inject_footer_social(path: Path) -> bool:
    text = path.read_text()
    if MARKER in text:
        return False

    needle = f'<p>{TAGLINE}</p>'
    if needle not in text:
        return False

    updated = text.replace(
        needle,
        f'{needle}\n{FOOTER_SNIPPET.rstrip()}',
        1,
    )
    path.write_text(updated)
    return True


def main() -> None:
    env = load_env(ROOT / 'config/.env')
    html_files = sorted((ROOT / 'public').glob('*.html'))

    injected = 0
    for path in html_files:
        if inject_footer_social(path):
            injected += 1

    synced = 0
    for path in html_files:
        original = path.read_text()
        updated = apply_env_urls(original, env)
        if updated != original:
            path.write_text(updated)
            synced += 1

    print(f'footer social injected: {injected}')
    print(f'env urls synced: {synced}')


if __name__ == '__main__':
    main()
