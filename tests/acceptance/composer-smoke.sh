#!/usr/bin/env bash
# End-to-end check of the Composer routes against a deployed stage.
# Uploads a throwaway package reason-dev/wpup-smoke at a unique version,
# installs it with Composer using Bearer auth, and checks the lockfile.
#
#   tests/acceptance/composer-smoke.sh https://<api-id>.execute-api.us-east-1.amazonaws.com <bucket> <key>
set -euo pipefail

BASE_URL="${1%/}"; BUCKET="$2"; KEY="$3"
# This script writes permanent objects to dist/, so refuse to run it against a production bucket.
case "$BUCKET" in *-prod-*) echo "Refusing to run against a production bucket ($BUCKET); use a dev stage." >&2; exit 1;; esac
HOST="$(printf '%s' "$BASE_URL" | sed -E 's#^https?://([^/]+).*#\1#')"
VERSION="0.0.$(date +%s)"
SLUG="wpup-smoke"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/pkg/$SLUG"
printf '<?php\n/*\n * Plugin Name: wpup smoke\n * Version: %s\n */\n' "$VERSION" > "$WORK/pkg/$SLUG/$SLUG.php"
(cd "$WORK/pkg" && zip -qr "$WORK/$SLUG-$VERSION.zip" "$SLUG")
SHA1="$(shasum -a 1 "$WORK/$SLUG-$VERSION.zip" | cut -d' ' -f1)"
DIST_KEY="dist/reason-dev/$SLUG/$SLUG-$VERSION.zip"

cat > "$WORK/p2.json" <<JSON
{"packages":{"reason-dev/$SLUG":[{"name":"reason-dev/$SLUG","version":"$VERSION","type":"wordpress-plugin",
"extra":{"installer-name":"$SLUG"},
"dist":{"type":"zip","url":"$BASE_URL/$DIST_KEY","shasum":"$SHA1","reference":"$VERSION"}}]}}
JSON

aws s3api put-object --bucket "$BUCKET" --key "$DIST_KEY" --body "$WORK/$SLUG-$VERSION.zip" --if-none-match '*' >/dev/null
# Replaces the smoke package's index on purpose (see docs/runbook.md, "The one exception").
aws s3api put-object --bucket "$BUCKET" --key "p2/reason-dev/$SLUG.json" --body "$WORK/p2.json" --content-type application/json >/dev/null

echo "== unauthenticated request is refused"
code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/packages.json")"
[ "$code" = "401" ] || { echo "FAIL: expected 401, got $code"; exit 1; }

echo "== query-string key is refused"
code="$(curl -s -o /dev/null -w '%{http_code}' --get --data-urlencode "key=$KEY" "$BASE_URL/packages.json")"
[ "$code" = "401" ] || { echo "FAIL: expected 401, got $code"; exit 1; }

echo "== composer install with Bearer auth"
mkdir "$WORK/site"
cat > "$WORK/site/composer.json" <<JSON
{"repositories":[{"type":"composer","url":"$BASE_URL","only":["reason-dev/*"]},{"packagist.org":false}],
 "require":{"reason-dev/$SLUG":"$VERSION"}}
JSON
COMPOSER_AUTH="{\"bearer\":{\"$HOST\":\"$KEY\"}}" composer install --working-dir "$WORK/site" --no-interaction --no-progress

[ -f "$WORK/site/vendor/reason-dev/$SLUG/$SLUG.php" ] || { echo "FAIL: package files not installed at vendor/reason-dev/$SLUG/"; exit 1; }
if grep -qF -- "$KEY" "$WORK/site/composer.lock"; then echo "FAIL: key found in composer.lock"; exit 1; fi

echo "PASS: Composer routes work end to end ($SLUG $VERSION)"
