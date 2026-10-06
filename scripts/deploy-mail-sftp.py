#!/usr/bin/env python3
"""Deploy Metro Mail files to live via SFTP (reads config/sftp.json)."""

import json
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
CFG = json.loads((ROOT / "config/sftp.json").read_text())

FILES = [
    # Backend (outside web root)
    "app/Mail/MailService.php",
    "app/Mail/MailHtml.php",
    "app/Mail/MailCsrf.php",
    "app/Mail/MailSession.php",
    "app/Mail/ResendMailClient.php",
    "config/.env",
    "database/migrations/mail_036_core.sql",
    "database/migrations/mail_037_signatures.sql",
    "database/migrations/mail_038_avatars.sql",
    "scripts/run-mail-migrations.php",
    "scripts/setup-mail-admin.php",
    # Web root (public/)
    "public/mail/bootstrap.php",
    "public/mail/index.php",
    "public/mail/login.php",
    "public/mail/logout.php",
    "public/mail/change-password.php",
    "public/mail/message.php",
    "public/mail/compose.php",
    "public/mail/settings.php",
    "public/mail/actions.php",
    "public/mail/updates.php",
    "public/mail/download.php",
    "public/mail/upload-image.php",
    "public/mail/admin/users.php",
    "public/mail/partials/head-meta.php",
    "public/mail/partials/layout-start.php",
    "public/mail/partials/layout-end.php",
    "public/mail/partials/compose-panel.php",
    "public/mail/partials/list-toolbar.php",
    "public/mail/partials/editor-toolbar.php",
    "public/css/mail.css",
    "public/css/mail-modal.css",
    "public/css/mail-dropdown.css",
    "public/js/mail.js",
    "public/js/mail-modal.js",
    "public/js/mail-dropdown.js",
    "public/webhooks/resend-mail.php",
    "public/api/mail/setup.php",
    "public/api/mail/sync-inbound.php",
    "public/.htaccess",
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
    for rel in FILES:
        local = ROOT / rel
        if not local.is_file():
            print(f"SKIP missing {rel}", file=sys.stderr)
            continue

        if rel.startswith("public/"):
            remote = f"{remote_public}/{rel[len('public/'):]}"
        else:
            remote = f"{remote_root}/{rel}"

        ensure_remote_dir(sftp, os.path.dirname(remote))
        sftp.put(str(local), remote)
        print(f"OK {rel} -> {remote}")
        uploaded += 1

    sftp.close()
    transport.close()
    print(f"Uploaded {uploaded} files.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
