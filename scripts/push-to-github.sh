#!/usr/bin/env bash
# Push Metro Football to a private GitHub repo.
# Run from project root: bash scripts/push-to-github.sh
#
# Requires: GitHub CLI (gh) logged in — gh auth login

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v gh >/dev/null 2>&1; then
  echo "GitHub CLI (gh) is not installed."
  echo "Install: https://cli.github.com/ then run: gh auth login"
  exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
  echo "GitHub CLI is not authenticated. Run: gh auth login"
  exit 1
fi

REPO_NAME="${1:-metrofootball}"
DESC="Metro Football FPL tools and reports — metrofootball.co.uk"

echo "Checking git status..."
git status --short

if git remote get-url origin >/dev/null 2>&1; then
  echo "Remote origin already set. Pushing main..."
  git push -u origin main
else
  echo "Creating private repo: ${REPO_NAME}"
  if gh repo view "${REPO_NAME}" >/dev/null 2>&1; then
    USER="$(gh api user -q .login)"
    git remote add origin "https://github.com/${USER}/${REPO_NAME}.git"
    git push -u origin main
  else
    gh repo create "${REPO_NAME}" \
      --private \
      --source=. \
      --remote=origin \
      --push \
      --description "${DESC}"
  fi
fi

USER="$(gh api user -q .login)"
echo ""
echo "Done. Repository:"
echo "  https://github.com/${USER}/${REPO_NAME}"
echo ""
echo "Secrets (.env, database) are gitignored and were NOT pushed."
