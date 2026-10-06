#!/usr/bin/env python3
"""Replace blocking AdSense head tag with idle-loaded loader on all public HTML pages."""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / 'public'
OLD = (
    '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-9101848035950927" crossorigin="anonymous"></script>'
)
NEW = (ROOT / 'public/partials/google-adsense.html').read_text().rstrip()


def main() -> None:
    updated = 0
    for path in sorted(PUBLIC.glob('*.html')):
        html = path.read_text()
        if OLD not in html:
            continue
        path.write_text(html.replace(OLD, NEW, 1))
        print(path.name)
        updated += 1
    print(f'Updated {updated} page(s).')


if __name__ == '__main__':
    main()
