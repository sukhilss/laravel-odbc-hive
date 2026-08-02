#!/bin/sh
set -eu

# Pinned to the pre-port commit so this stays reproducible forever.
PINNED_SHA=ea23f65

rm -rf /tmp/v6
mkdir -p /tmp/v6
git -C /app archive "$PINNED_SHA" src | tar -x -C /tmp/v6

cd /app/docker/legacy-capture
composer install --no-interaction --quiet

php /app/tools/capture-golden.php
