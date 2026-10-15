#!/usr/bin/env bash
# Run as the existing deployment user, never as root or the PHP worker.
set -Eeuo pipefail
umask 027

APP_DIR=${APP_DIR:-/serverdata/wwwroot/links}
STATE_DIR=${STATE_DIR:-/serverdata/deploy/links}
NODE_BIN_DIR=${NODE_BIN_DIR:-/serverdata/runenv/node/bin}
WEB_GROUP=${WEB_GROUP:-www-data}
PHP_FPM_SERVICE=${PHP_FPM_SERVICE:-php7.4-fpm}
: "${HEALTH_URL:?Set HEALTH_URL to the site HTTPS /api/health URL}"
export PATH="$NODE_BIN_DIR:/usr/local/bin:/usr/bin:/bin"
export GIT_TERMINAL_PROMPT=0
export GIT_SSH_COMMAND='ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15'

log() { printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail() { log "ERROR: $*" >&2; exit 1; }
stage=''
index_next=''
phase=preflight
cleanup() {
  local status=$?
  trap - EXIT
  # Only remove temporary paths created by this invocation.
  if [[ -n "$index_next" && "$index_next" == "$APP_DIR/dist/.index-"* ]]; then rm -f -- "$index_next"; fi
  if [[ -n "$stage" && "$stage" == "$STATE_DIR/build."* ]]; then rm -rf -- "$stage"; fi
  if (( status != 0 )); then log "FAILED during $phase; no success recorded. Inspect the journal before retrying." >&2; fi
  exit "$status"
}
trap cleanup EXIT

[[ $(id -u) != 0 ]] || fail 'Use a non-root deployment user.'
[[ "$HEALTH_URL" == https://* ]] || fail 'HEALTH_URL must use HTTPS.'
[[ -d "$APP_DIR/.git" && -d "$STATE_DIR" ]] || fail 'Install the checkout and private state directory first.'
APP_DIR=$(cd "$APP_DIR" && pwd -P)
STATE_DIR=$(cd "$STATE_DIR" && pwd -P)
[[ "$STATE_DIR/" != "$APP_DIR/"* ]] || fail 'STATE_DIR must be outside the website checkout.'
for tool in git node npm flock tar curl python3 sudo systemctl; do command -v "$tool" >/dev/null || fail "Missing command: $tool"; done
exec 9>"$STATE_DIR/update.lock"
flock -n 9 || { log 'Another deployment is running; skipped.'; exit 0; }
cd "$APP_DIR"
[[ $(git branch --show-current) == main ]] || fail 'The checkout must be on main.'
[[ -z $(git status --porcelain) ]] || fail 'The checkout has local changes; automatic update stopped.'
[[ -f backend/.env && -f dist/index.html ]] || fail 'Finish the first manual deployment before enabling automatic updates.'

phase=fetch
git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main
current=$(git rev-parse HEAD)
target=$(git rev-parse refs/remotes/origin/main)
deployed=''
if [[ -f "$STATE_DIR/deployed-revision" ]]; then deployed=$(cat "$STATE_DIR/deployed-revision"); fi
if [[ "$current" == "$target" && "$deployed" == "$target" ]]; then log "Already deployed ${target:0:12}."; exit 0; fi
git merge-base --is-ancestor "$current" "$target" || fail 'Local main and origin/main diverged; inspect manually.'
systemctl is-active --quiet "$PHP_FPM_SERVICE" || fail "PHP service is not active: $PHP_FPM_SERVICE"
sudo -n -l /usr/bin/systemctl reload "$PHP_FPM_SERVICE" >/dev/null || fail 'Configure the documented PHP reload sudo rule.'

phase=build
log "Preparing ${target:0:12}; the live checkout stays unchanged during the build."
stage=$(mktemp -d "$STATE_DIR/build.XXXXXXXX")
git archive "$target" | tar -xf - -C "$stage"
(
  cd "$stage"
  npm --cache="$APP_DIR/.npm-cache" ci --include=dev
  npm --cache="$APP_DIR/.npm-cache" run build
)
[[ -s "$stage/dist/index.html" && -d "$stage/dist/assets" ]] || fail 'The build did not produce dist/index.html and dist/assets.'
# Stop if a person changed the checkout while the build was running.
[[ $(git rev-parse HEAD) == "$current" && $(git branch --show-current) == main && -z $(git status --porcelain) ]] || fail 'The checkout changed during the build.'
chgrp -R "$WEB_GROUP" "$stage/dist"
chmod -R u=rwX,g=rX,o= "$stage/dist"

phase=backup
mkdir -p "$STATE_DIR/backups"
backup=$(mktemp -d "$STATE_DIR/backups/$(date -u +%Y%m%dT%H%M%SZ)-${current:0:12}.XXXXXX")
git archive "$current" | gzip > "$backup/source.tar.gz"
tar -czf "$backup/dist.tar.gz" -C "$APP_DIR" dist
cp backend/.env "$backup/backend.env"
chmod 600 "$backup/backend.env"
log "Code, dist and configuration backup: $backup (database backup is separate)."

phase=publish
# Copy new assets first and retain old hashed assets for already-open pages.
# The entry HTML is replaced separately with a rename on the same filesystem.
tar -cf - --exclude='./index.html' -C "$stage/dist" . | tar --no-same-owner -xf - -C "$APP_DIR/dist"
# Extraction as an unprivileged user may assign the primary group instead.
chgrp -R "$WEB_GROUP" "$APP_DIR/dist"
chmod -R u=rwX,g=rX,o= "$APP_DIR/dist"
index_next=$(mktemp "$APP_DIR/dist/.index-XXXXXXXX")
cp "$stage/dist/index.html" "$index_next"
chgrp "$WEB_GROUP" "$index_next"
chmod 640 "$index_next"
git merge --ff-only "$target"
# PHP writes only backend/storage; do not change its runtime files or ownership.
find backend -path backend/storage -prune -o -exec chgrp "$WEB_GROUP" {} +
find backend -path backend/storage -prune -o -type d -exec chmod 2750 {} +
find backend -path backend/storage -prune -o -type f -exec chmod 640 {} +
sudo -n /usr/bin/systemctl reload "$PHP_FPM_SERVICE"
mv -f -- "$index_next" "$APP_DIR/dist/index.html"
index_next=''

phase=health-check
curl --fail --silent --show-error --connect-timeout 10 --max-time 30 "$HEALTH_URL" |
  python3 -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("ok") is True and d.get("database") is True else "API health check failed: database must be connected")'
printf '%s\n' "$target" > "$STATE_DIR/deployed-revision.next"
mv -f -- "$STATE_DIR/deployed-revision.next" "$STATE_DIR/deployed-revision"
log "Deployed ${target:0:12}; HTTPS and database health check passed."
