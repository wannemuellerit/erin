#!/bin/sh

set -eu

stamp_file="node_modules/.erin-package-lock.sha256"
lock_hash="$(sha256sum package-lock.json | cut -d ' ' -f 1)"

if [ -f "$stamp_file" ] && [ "$(sed -n '1p' "$stamp_file")" = "$lock_hash" ]; then
    echo "Node-Abhängigkeiten sind bereits aktuell."
    exit 0
fi

npm ci --no-audit --no-fund
printf '%s\n' "$lock_hash" > "$stamp_file"
