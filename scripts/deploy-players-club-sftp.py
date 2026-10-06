#!/usr/bin/env python3
"""Deploy club hub / players SEO updates via SFTP."""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
CFG = json.loads((ROOT / "config/sftp.json").read_text())

FILES = [
    ".htaccess",
    "app/PlayerProfileService.php",
    "app/PlayersService.php",
    "public/.htaccess",
    "public/players.php",
    "public/players.html",
    "public/players-club.php",
    "public/player-profile.php",
    "public/js/players.js",
    "public/js/players-club.js",
    "public/css/players.css",
    "public/sitemap-players.php",
    "public/sitemap-pages.xml",
    "public/sitemap.xml",
]


def ensure_remote_dir(sftp: paramiko.SFTPClient, remote_dir: str) -> None:
    parts = remote_dir.strip("/").split("/")
    path = ""
    for part in parts:
        if not part:
            continue
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
    assert sftp is not None

    for rel in FILES:
        local = ROOT / rel
        if not local.is_file():
            print(f"MISSING {rel}", file=sys.stderr)
            continue
        if rel.startswith("public/"):
            remote = remote_public + "/" + rel[len("public/") :]
        elif rel.startswith("app/"):
            remote = remote_root + "/app/" + rel[len("app/") :]
        else:
            remote = remote_root + "/" + rel
        ensure_remote_dir(sftp, os.path.dirname(remote))
        sftp.put(str(local), remote)
        print(f"OK {rel} -> {remote}")

    sftp.close()
    transport.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
