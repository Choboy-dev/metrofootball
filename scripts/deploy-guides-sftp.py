#!/usr/bin/env python3
"""Deploy FPL guides content to live via SFTP."""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
CFG = json.loads((ROOT / "config/sftp.json").read_text())

FILES = [
    "app/FplGuidesService.php",
    "app/FplGuides/ArticleLoader.php",
    "app/FplGuides/ArticleToc.php",
    "app/FplGuides/ArticleLinker.php",
    "public/api/bootstrap.php",
    "public/fpl-guide.php",
    "public/fpl-guides.php",
    "public/css/fpl-guides.css",
    "public/images/guides/pitch.svg",
    "public/images/guides/bench.svg",
]

ARTICLE_DIR = ROOT / "app/FplGuides/articles"
GUIDE_IMAGES_DIR = ROOT / "public/images/guides"
FEATURED_DIR = ROOT / "public/images/guides/featured"


def ensure_remote_dir(sftp: paramiko.SFTPClient, remote_dir: str) -> None:
    parts = remote_dir.strip("/").split("/")
    path = ""
    for part in parts:
        path += "/" + part
        try:
            sftp.stat(path)
        except FileNotFoundError:
            sftp.mkdir(path)


def main() -> int:
    remote_public = CFG["remotePath"].rstrip("/")
    remote_root = str(Path(remote_public).parent)

    transport = paramiko.Transport((CFG["host"], CFG["port"]))
    transport.connect(username=CFG["username"], password=CFG["password"])
    sftp = paramiko.SFTPClient.from_transport(transport)

    uploaded = 0
    skipped = 0

    for rel in FILES:
        local = ROOT / rel
        if not local.is_file():
            print(f"SKIP missing {rel}", file=sys.stderr)
            skipped += 1
            continue

        if rel.startswith("public/"):
            remote = f"{remote_public}/{rel[len('public/'):]}"
        else:
            remote = f"{remote_root}/{rel}"

        ensure_remote_dir(sftp, os.path.dirname(remote))
        sftp.put(str(local), remote)
        print(f"OK {rel}")
        uploaded += 1

    remote_articles = f"{remote_root}/app/FplGuides/articles"
    ensure_remote_dir(sftp, remote_articles)
    for article in sorted(ARTICLE_DIR.glob("*.html")):
        remote = f"{remote_articles}/{article.name}"
        sftp.put(str(article), remote)
        print(f"OK app/FplGuides/articles/{article.name}")
        uploaded += 1

    remote_guides_images = f"{remote_public}/images/guides"
    ensure_remote_dir(sftp, remote_guides_images)
    for image in sorted(GUIDE_IMAGES_DIR.glob("*.svg")):
        remote = f"{remote_guides_images}/{image.name}"
        sftp.put(str(image), remote)
        print(f"OK public/images/guides/{image.name}")
        uploaded += 1

    remote_featured = f"{remote_public}/images/guides/featured"
    ensure_remote_dir(sftp, remote_featured)
    for image in sorted(list(FEATURED_DIR.glob("*.webp")) + list(FEATURED_DIR.glob("*.jpg")) + list(FEATURED_DIR.glob("*.jpeg")) + list(FEATURED_DIR.glob("*.png"))):
        remote = f"{remote_featured}/{image.name}"
        sftp.put(str(image), remote)
        print(f"OK public/images/guides/featured/{image.name}")
        uploaded += 1

    sftp.close()
    transport.close()
    print(f"Uploaded {uploaded} files ({skipped} skipped).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
