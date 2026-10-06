#!/usr/bin/env python3
"""Deploy site updates to live via SFTP (reads config/sftp.json)."""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
CFG = json.loads((ROOT / "config/sftp.json").read_text())

FILES = [
    "public/js/fpl-guides.js",
    "public/js/auth.js",
    "public/fpl-guides.php",
    "public/fpl-guide.php",
    "public/css/app.css",
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

    sftp.close()
    transport.close()
    print(f"Uploaded {uploaded} files ({skipped} skipped).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
