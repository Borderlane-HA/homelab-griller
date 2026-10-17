#!/usr/bin/env bash
# Runs in GitHub Actions on an ordinary Linux Docker runner.
set -Eeuo pipefail
NAME="griller-smoke-$$"
PORT=19091
BASE="http://127.0.0.1:${PORT}"
DIR="$(mktemp -d)"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$DIR"; }
trap cleanup EXIT

docker build -t homelab-griller:smoke .
docker run -d --name "$NAME" -p "127.0.0.1:${PORT}:80" -e GRILLER_DEFAULT_LANGUAGE=en homelab-griller:smoke >/dev/null
ready=0
for i in $(seq 1 30); do
  if curl -fsS -L "$BASE/?r=setup" -o "$DIR/setup.html" && grep -q 'name="setup_token"' "$DIR/setup.html"; then ready=1;break;fi
  sleep 2
done
[[ "$ready" == 1 ]] || { docker logs "$NAME"; echo 'Setup page not ready' >&2; exit 1; }

# Data/ contains private SQLite and setup credentials. Apache must deny it.
status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/data/setup-token")"
[[ "$status" == 403 ]] || { echo "Private data endpoint returned HTTP $status, expected 403" >&2; exit 1; }

token="$(docker exec "$NAME" cat /var/www/html/data/setup-token)"
curl -fsS -c "$DIR/cookies" "$BASE/?r=setup" -o "$DIR/setup.html"
csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/setup.html" | head -1 | cut -d '"' -f4)"
[[ -n "$csrf" && -n "$token" ]] || { echo 'Missing setup token or CSRF' >&2; exit 1; }
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "setup_token=$token" --data-urlencode "csrf=$csrf" \
  --data-urlencode "username=Griller" --data-urlencode "password=SmokeTestSecure123!" \
  --data-urlencode "password_confirm=SmokeTestSecure123!" --data-urlencode 'theme=ember' \
  "$BASE/?r=setup"
curl -fsS -b "$DIR/cookies" "$BASE/?r=admin" -o "$DIR/admin.html"
grep -q 'New BBQ event' "$DIR/admin.html" || { echo 'Admin first-run setup failed'; cat "$DIR/admin.html" | tail -20; exit 1; }
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/data/griller.sqlite")" == 403 ]] || { echo 'SQLite DB exposed!' >&2; exit 1; }
echo 'HomeLab Griller smoke checks passed: first-run UI, admin login/session, SQLite file access denied.'
