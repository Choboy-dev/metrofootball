#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TUS_BASE="${TUS_BASE:?missing TUS_BASE}"
AUTH_KEY="${AUTH_KEY:?missing AUTH_KEY}"
REST_AUTH_KEY="${REST_AUTH_KEY:?missing REST_AUTH_KEY}"

upload_file() {
  local rel="$1"
  local file="$ROOT/$rel"
  if [[ ! -f "$file" ]]; then
    echo "SKIP missing $rel" >&2
    return 0
  fi

  local size
  size=$(stat -f%z "$file" 2>/dev/null || stat -c%s "$file")
  local url="${TUS_BASE}/${rel}?override=true"

  curl -sS -o /dev/null -X POST "$url" \
    -H "X-Auth: ${AUTH_KEY}" \
    -H "X-Auth-Rest: ${REST_AUTH_KEY}" \
    -H "Tus-Resumable: 1.0.0" \
    -H "Upload-Length: ${size}" \
    -H "Upload-Offset: 0"

  curl -sS -o /dev/null -X PATCH "$url" \
    -H "X-Auth: ${AUTH_KEY}" \
    -H "X-Auth-Rest: ${REST_AUTH_KEY}" \
    -H "Tus-Resumable: 1.0.0" \
    -H "Content-Type: application/offset+octet-stream" \
    -H "Upload-Offset: 0" \
    --data-binary "@${file}"

  echo "OK $rel"
}

FILES=(
  app/Mail/MailService.php
  app/Mail/MailHtml.php
  app/Mail/MailCsrf.php
  app/Mail/MailSession.php
  app/Mail/ResendMailClient.php
  public/mail/bootstrap.php
  public/mail/index.php
  public/mail/login.php
  public/mail/logout.php
  public/mail/change-password.php
  public/mail/message.php
  public/mail/compose.php
  public/mail/settings.php
  public/mail/actions.php
  public/mail/updates.php
  public/mail/download.php
  public/mail/upload-image.php
  public/mail/admin/users.php
  public/mail/partials/head-meta.php
  public/mail/partials/layout-start.php
  public/mail/partials/layout-end.php
  public/mail/partials/compose-panel.php
  public/mail/partials/editor-toolbar.php
  public/css/mail.css
  public/css/mail-modal.css
  public/css/mail-dropdown.css
  public/js/mail.js
  public/js/mail-modal.js
  public/js/mail-dropdown.js
  public/webhooks/resend-mail.php
  public/api/mail/setup.php
  public/.htaccess
  database/migrations/mail_036_core.sql
  database/migrations/mail_037_signatures.sql
  database/migrations/mail_038_avatars.sql
  scripts/run-mail-migrations.php
  scripts/setup-mail-admin.php
  config/.env
)

for rel in "${FILES[@]}"; do
  upload_file "$rel"
done

echo "Deploy complete."
