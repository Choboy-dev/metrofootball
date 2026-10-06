#!/usr/bin/env python3
"""Inject Meta Pixel head snippet into public HTML pages."""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIPPET = (ROOT / 'public/partials/meta-pixel.html').read_text().rstrip()
MARKER = "1033677469478224"
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
    print(f'Injected Meta Pixel snippet into {injected} page(s).')


if __name__ == '__main__':
    main()
