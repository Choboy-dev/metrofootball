#!/usr/bin/env bash
# Fix footer Terms links across public HTML pages.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)/public"
count=0
while IFS= read -r -d '' file; do
  if grep -q 'href="#">Terms</a>' "$file"; then
    sed -i 's|href="#">Terms</a>|href="./terms">Terms</a>|g' "$file"
    echo "fixed: $file"
    count=$((count + 1))
  fi
done < <(find "$ROOT" -name '*.html' -print0)
echo "Updated $count file(s)."
