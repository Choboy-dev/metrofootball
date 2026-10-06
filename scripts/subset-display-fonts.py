#!/usr/bin/env python3
"""Subset Google Sans WOFF files to compact latin WOFF2."""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FONTS = ROOT / 'public' / 'fonts'
UNI = 'U+0020-007F,U+00A0-00FF,U+2013-2014,U+2018-2019,U+2026'
WEIGHTS = (500, 600, 700)


def pyftsubset() -> str:
    found = shutil.which('pyftsubset')
    if found:
        return found
    candidate = Path.home() / 'Library/Python/3.9/bin/pyftsubset'
    if candidate.is_file():
        return str(candidate)
    raise SystemExit('pyftsubset not found. Run: pip3 install fonttools brotli zopfli')


def main() -> None:
    tool = pyftsubset()
    for weight in WEIGHTS:
        source = FONTS / f'google-sans-{weight}.woff'
        dest = FONTS / f'google-sans-{weight}-latin.woff2'
        if not source.is_file():
            raise SystemExit(f'Missing {source}')
        subprocess.run(
            [
                tool,
                str(source),
                f'--unicodes={UNI}',
                '--flavor=woff2',
                f'--output-file={dest}',
            ],
            check=True,
        )
        print(f'OK {dest.name} ({dest.stat().st_size} bytes)')


if __name__ == '__main__':
    main()
