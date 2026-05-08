#!/usr/bin/env bash
# Chrome bundled by Puppeteer needs X11/stack libraries on headless Amazon Linux.
# Run on EC2 (fixes: libxkbcommon.so.0: cannot open shared object file).
# Usage: sudo bash bin/install-puppeteer-chrome-deps-amzn.sh

set -euo pipefail

if [[ "${EUID:-}" -ne 0 ]]; then
	echo "Run with sudo: sudo bash $0" >&2
	exit 1
fi

if command -v dnf >/dev/null 2>&1; then
	PM=(dnf install -y)
elif command -v yum >/dev/null 2>&1; then
	PM=(yum install -y)
else
	echo "Neither dnf nor yum found." >&2
	exit 1
fi

# Core set for Puppeteer/Chrome on AL2023 / Amazon Linux 2 (adjust names if yum reports "No match").
"${PM[@]}" \
	alsa-lib \
	atk \
	at-spi2-atk \
	cups-libs \
	gtk3 \
	libdrm \
	libX11 \
	libXcomposite \
	libXdamage \
	libXext \
	libXfixes \
	libXi \
	libxkbcommon \
	libXrandr \
	libxshmfence \
	mesa-libgbm \
	nspr \
	nss \
	nss-util \
	pango

echo "Done. Restart PHP-FPM (e.g. sudo systemctl reload php-fpm) and retry scraping."
