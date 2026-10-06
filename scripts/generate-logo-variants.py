#!/usr/bin/env python3
"""Create responsive WebP logo sizes from the master assets."""

from __future__ import annotations

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
IMAGES = ROOT / 'public' / 'images'
VERSION = '20260830logo'
WIDTHS = (315, 630)

PAIRS = (
    ('metro-football-correct-dark-logo.webp', 'metro-football-correct-dark-logo'),
    ('metro-football-logo-light.webp', 'metro-football-logo-light'),
)


def resize_logo(source: Path, base: str, width: int) -> Path:
    with Image.open(source) as img:
        ratio = width / img.width
        height = max(1, round(img.height * ratio))
        resized = img.resize((width, height), Image.Resampling.LANCZOS)
        dest = IMAGES / f'{base}-{width}.webp'
        resized.save(dest, format='WEBP', quality=86, method=6)
        return dest


def main() -> None:
    for filename, base in PAIRS:
        source = IMAGES / filename
        if not source.is_file():
            raise SystemExit(f'Missing source logo: {source}')
        for width in WIDTHS:
            dest = resize_logo(source, base, width)
            print(f'OK {dest.name} ({dest.stat().st_size} bytes)')
    print(f'version={VERSION}')


if __name__ == '__main__':
    main()
