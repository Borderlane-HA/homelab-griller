#!/usr/bin/env bash
# GitHub Actions / Docker runner integration test.
set -Eeuo pipefail
NAME="griller-smoke-$$"
PORT=19091
HTTPS_PORT=19443
BASE="http://127.0.0.1:${PORT}"
DIR="$(mktemp -d)"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$DIR"; }
trap cleanup EXIT

docker build -t homelab-griller:smoke .
docker run -d --name "$NAME" -p "127.0.0.1:${PORT}:80" -p "127.0.0.1:${HTTPS_PORT}:443" -e GRILLER_DEFAULT_LANGUAGE=en homelab-griller:smoke >/dev/null
ready=0
for i in $(seq 1 30); do
  if curl -fsS -L "$BASE/?r=setup" -o "$DIR/setup.html" && grep -q 'name="password_confirm"' "$DIR/setup.html"; then ready=1;break;fi
  sleep 2
done
[[ "$ready" == 1 ]] || { docker logs "$NAME"; echo 'Setup page not ready' >&2; exit 1; }
! grep -q 'name="setup_token"' "$DIR/setup.html" || { echo 'Log token still required' >&2; exit 1; }
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/data/griller.sqlite")" == 403 ]] || { echo 'SQLite exposed!' >&2; exit 1; }
[[ "$(curl -ksS -o /dev/null -w '%{http_code}' "https://127.0.0.1:${HTTPS_PORT}/?r=setup")" == 200 ]] || { echo 'HTTPS failed' >&2; exit 1; }
curl -fsS -c "$DIR/cookies" "$BASE/?r=setup" -o "$DIR/setup.html"
csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/setup.html" | head -1 | cut -d '"' -f4)"
[[ -n "$csrf" ]] || { echo 'Missing CSRF' >&2; exit 1; }
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode "username=Griller" \
  --data-urlencode "password=Test123" --data-urlencode "password_confirm=Test123" \
  --data-urlencode 'theme=ember' "$BASE/?r=setup"
curl -fsS -b "$DIR/cookies" "$BASE/?r=admin" -o "$DIR/admin.html"
grep -q 'New BBQ event' "$DIR/admin.html" || { echo 'Admin setup failed'; tail -25 "$DIR/admin.html"; exit 1; }
csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/admin.html" | head -1 | cut -d '"' -f4)"
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=create_event' --data-urlencode 'title=Smoke BBQ' \
  --data-urlencode 'event_date=2026-10-17' "$BASE/?r=admin"
curl -fsS -b "$DIR/cookies" "$BASE/?r=admin" -o "$DIR/admin.html"
grep -q 'Smoke BBQ' "$DIR/admin.html" || { echo 'Event creation failed'; exit 1; }
grep -q 'class="pill public"' "$DIR/admin.html" || { echo 'Expected public default for event'; exit 1; }
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/data/tls/server.key")" == 403 ]] || { echo 'Private key exposed!' >&2; exit 1; }
echo 'Smoke checks passed: no token setup, admin login, public events, HTTP/HTTPS and private data protection.'
