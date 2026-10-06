#!/usr/bin/env python3
"""Inject AdSense head snippet into public HTML pages."""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIPPET = (ROOT / 'public/partials/google-adsense.html').read_text().rstrip()
MARKER = 'adsbygoogle.js?client=ca-pub-9101848035950927'
VIEWPORT = '<meta name="viewport" content="width=device-width, initial-scale=1">'


def inject(path: Path) -> bool:
    html = path.read_text()
    if MARKER in html:
        return False
    if VIEWPORT not in html:
        return False
    updated = html.replace(VIEWPORT, f'{VIEWPORT}\n{SNIPPET}', 1)
    path.write_text(updated)
    return True


def main() -> None:
    public = ROOT / 'public'
    injected = 0
    for path in sorted(public.glob('*.html')):
        if inject(path):
            print(path.name)
            injected += 1
    print(f'Injected AdSense snippet into {injected} page(s).')


if __name__ == '__main__':
    main()
