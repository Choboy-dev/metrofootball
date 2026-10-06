#!/usr/bin/env python3
"""Create mobile-sized hero WebP variants."""

from __future__ import annotations

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
HERO_DIR = ROOT / 'public' / 'images' / 'hero'
VERSION = '20260830hero'
WIDTHS = (350, 700)


def main() -> None:
    for source in sorted(HERO_DIR.glob('hero-*.webp')):
        if re_match_size_suffix(source.name):
            continue
        with Image.open(source) as img:
            for width in WIDTHS:
                ratio = width / img.width
                height = max(1, round(img.height * ratio))
                dest = source.with_name(f'{source.stem}-{width}.webp')
                resized = img.resize((width, height), Image.Resampling.LANCZOS)
                resized.save(dest, format='WEBP', quality=82, method=6)
                print(f'OK {dest.name} ({dest.stat().st_size} bytes)')


def re_match_size_suffix(name: str) -> bool:
    import re
    return re.search(r'hero-\d+-\d+\.webp$', name) is not None


if __name__ == '__main__':
    main()
