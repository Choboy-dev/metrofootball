#!/usr/bin/env python3
"""Swap master logo tags for responsive srcset markup across public pages."""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / 'public'
HEADER = (PUBLIC / 'partials' / 'mf-logo-header.html').read_text().strip()
FOOTER = (PUBLIC / 'partials' / 'mf-logo-footer.html').read_text().strip()
HEADER_ABS = HEADER.replace('./images/', '/images/')
FOOTER_ABS = FOOTER.replace('./images/', '/images/')

HEADER_PATTERNS = [
    re.compile(
        r'<img class="site-logo__img" src="(?:\./|/)images/metro-football-correct-dark-logo(?:-\d+)?\.webp(?:\?v=[^"]*)?" alt="" width="938" height="313" decoding="async">',
        re.I,
    ),
    re.compile(
        r'<img class="site-logo__img" src="(?:\./|/)images/metro-football-logo-dark(?:-\d+)?\.webp(?:\?v=[^"]*)?" alt="" width="938" height="313" decoding="async">',
        re.I,
    ),
]

FOOTER_PATTERNS = [
    re.compile(
        r'<img class="site-logo__img site-logo__img--light" src="(?:\./|/)images/metro-football-logo-light(?:-\d+)?\.webp(?:\?v=[^"]*)?" alt="" width="938" height="313" decoding="async">',
        re.I,
    ),
]

MAIL_HEADER_PATTERN = re.compile(
    r'<img src="/images/metro-football-correct-dark-logo(?:-\d+)?\.webp(?:\?v=[^"]*)?" alt="" width="938" height="313" decoding="async">',
    re.I,
)


def replace_first(patterns: list[re.Pattern[str]], html: str, replacement: str) -> tuple[str, int]:
    count = 0
    for pattern in patterns:
        html, n = pattern.subn(replacement, html, count=1)
        count += n
        if n:
            break
    return html, count


def replace_all(patterns: list[re.Pattern[str]], html: str, replacement: str) -> tuple[str, int]:
    total = 0
    for pattern in patterns:
        html, n = pattern.subn(replacement, html)
        total += n
    return html, total


def sync_file(path: Path) -> tuple[int, int]:
    html = path.read_text()
    original = html
    header_replacement = HEADER_ABS if path.suffix == '.php' and 'mail/' in str(path) else HEADER
    if 'mail/' in str(path):
        html, headers = MAIL_HEADER_PATTERN.subn(HEADER_ABS, html)
    else:
        html, headers = replace_all(HEADER_PATTERNS, html, HEADER)
    html, footers = replace_all(FOOTER_PATTERNS, html, FOOTER)
    if html != original:
        path.write_text(html)
    return headers, footers


def main() -> None:
    targets = sorted(PUBLIC.glob('*.html'))
    targets += [PUBLIC / 'partials' / 'site-header.php', PUBLIC / 'partials' / 'site-footer.php']
    targets += sorted((PUBLIC / 'mail').glob('*.php'))

    total_h = total_f = 0
    for path in targets:
        if not path.is_file():
            continue
        headers, footers = sync_file(path)
        if headers or footers:
            print(f'{path.relative_to(ROOT)}: header={headers}, footer={footers}')
        total_h += headers
        total_f += footers
    print(f'Updated headers={total_h}, footers={total_f}')


if __name__ == '__main__':
    main()
