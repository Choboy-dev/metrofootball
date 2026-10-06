#!/usr/bin/env python3
"""Deploy FPL Guides CMS (admin editor, APIs, backend) to live via SFTP."""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
CFG = json.loads((ROOT / "config/sftp.json").read_text())

FILES = [
    # Backend
    "app/GuidePostService.php",
    "app/FplGuidesService.php",
    "app/FplGuides/GuideBlockRenderer.php",
    "app/GuideAiAssistService.php",
    "app/OpenAiClient.php",
    "app/TavilyClient.php",
    # API bootstrap
    "public/api/bootstrap.php",
    "public/api/bootstrap-admin.php",
    "public/api/admin/guides.php",
    "public/api/admin/guide-upload.php",
    "public/api/admin/guide-ai.php",
    # Public pages
    "public/fpl-guide.php",
    "public/fpl-guides.php",
    "public/css/fpl-guides.css",
    # Admin UI
    "public/admin/index.html",
    "public/admin/guides.html",
    "public/admin/guide-edit.html",
    "public/admin/css/admin.css",
    "public/admin/css/guides-editor.css",
    "public/admin/js/guides-editor.js",
    "public/admin/js/guides-list.js",
]

REMOTE_DIRS = [
    "images/guides/posts",
]


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

    for rel_dir in REMOTE_DIRS:
        ensure_remote_dir(sftp, f"{remote_public}/{rel_dir}")
        print(f"OK dir {rel_dir}/")

    sftp.close()
    transport.close()
    print(f"Uploaded {uploaded} files ({skipped} skipped).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
