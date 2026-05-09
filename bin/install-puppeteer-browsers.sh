#!/usr/bin/env bash
# Download Puppeteer's pinned Chrome + chrome-headless-shell into PUPPETEER_CACHE_DIR.
# Run as the SAME Linux user as php-fpm (often apache/nginx), from project root:
#   sudo -u apache bash bin/install-puppeteer-browsers.sh
#
# Full Chrome: required for default headless (SCRAPER_CHROME_HEADLESS unset or "true").
# headless-shell: required if .env has SCRAPER_CHROME_HEADLESS=shell
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PUPPETEER_CACHE_DIR="${PUPPETEER_CACHE_DIR:-$ROOT/storage/app/puppeteer-cache}"
mkdir -p "$PUPPETEER_CACHE_DIR"

echo "PUPPETEER_CACHE_DIR=$PUPPETEER_CACHE_DIR"
echo "Installing chrome..."
npx puppeteer browsers install chrome
echo "Installing chrome-headless-shell..."
npx puppeteer browsers install chrome-headless-shell
echo "Done. Ensure SCRAPER_CHROME_USER_DATA_DIR (if set) is writable by php-fpm."
