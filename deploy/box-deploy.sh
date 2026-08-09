#!/usr/bin/env bash
# box-deploy.sh — pull-based deployer for the Bethany Hub.
#
# Runs ON THE VPS (via the box-deploy.timer systemd unit, every ~60s).
# No inbound SSH from GitHub is ever needed: the box polls the repo's main
# branch sha via the public GitHub API, and when it changes it pulls the new
# images from GHCR and redeploys itself — replicating exactly what the old
# push-based SSH step in .github/workflows/deploy.yml used to do.
#
# Flow per tick:
#   1. Poll GET api.github.com/repos/<repo>/commits/main (unauthenticated,
#      ETag-conditional so steady-state ticks are 304s that don't count
#      against the 60/hr rate limit).
#   2. Compare to /opt/bethany-house/.deployed-sha. Unchanged -> exit 0.
#   3. Changed -> docker login ghcr.io (token from .ghcr-token, operator-
#      provisioned, chmod 600) and verify all three sha-tagged images exist
#      on GHCR. If CI hasn't finished publishing them yet, exit quietly and
#      retry next tick.
#   4. Sync /opt/bethany-house/repo to the sha, copy docker-compose.yml into
#      /opt/bethany-house/, pin GITHUB_REPOSITORY in .env, compose pull,
#      run `php artisan migrate --force`, compose up -d --remove-orphans,
#      prune, then advance .deployed-sha.
#
# Failure semantics: ANY failure leaves .deployed-sha unchanged, so the next
# tick retries the whole deploy. Migration failures are logged loudly.
# A flock prevents concurrent runs. Everything external is timeout-wrapped.
#
# Bootstrap (see docs/DEPLOY.md): until .ghcr-token exists and the systemd
# units are enabled, merges to main build images but nothing deploys — the
# currently running containers just keep running.

set -euo pipefail

REPO="mosesmwicigi24-pixel/bethany-house"
GHCR_USER="mosesmwicigi24-pixel"
BRANCH="main"

BASE_DIR="/opt/bethany-house"
REPO_DIR="${BASE_DIR}/repo"
SHA_FILE="${BASE_DIR}/.deployed-sha"
ETAG_FILE="${BASE_DIR}/.poll-etag"
TOKEN_FILE="${BASE_DIR}/.ghcr-token"
LOG_FILE="${BASE_DIR}/deploy.log"
LOCK_FILE="${BASE_DIR}/.box-deploy.lock"

# The three images CI publishes; all must exist at the target sha before we
# touch anything (guards against deploying mid-build).
IMAGES=(bethany-laravel bethany-nextjs bethany-react-admin)

ts() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }
log() { printf '%s %s\n' "$(ts)" "$*" >> "$LOG_FILE"; echo "$*"; }

# ── Single instance ──────────────────────────────────────────────────────────
exec 9> "$LOCK_FILE"
if ! flock -n 9; then
    # Another tick is still deploying; that's fine.
    exit 0
fi

# ── 1. Poll main's sha (ETag-conditional) ────────────────────────────────────
etag=""
[ -f "$ETAG_FILE" ] && etag="$(cat "$ETAG_FILE")"

hdr_file="$(mktemp)"
body_file="$(mktemp)"
trap 'rm -f "$hdr_file" "$body_file"' EXIT

set +e
if [ -n "$etag" ]; then
    http_code="$(curl -sS --max-time 20 \
        -H 'Accept: application/vnd.github.sha' \
        -H "If-None-Match: ${etag}" \
        -D "$hdr_file" -o "$body_file" -w '%{http_code}' \
        "https://api.github.com/repos/${REPO}/commits/${BRANCH}")"
else
    http_code="$(curl -sS --max-time 20 \
        -H 'Accept: application/vnd.github.sha' \
        -D "$hdr_file" -o "$body_file" -w '%{http_code}' \
        "https://api.github.com/repos/${REPO}/commits/${BRANCH}")"
fi
curl_rc=$?
set -e

if [ "$curl_rc" -ne 0 ]; then
    # Transient network problem; stay quiet in the log, retry next tick.
    echo "poll: curl failed (rc=${curl_rc}); retrying next tick"
    exit 0
fi

if [ "$http_code" = "304" ]; then
    # Nothing changed since the last successfully handled poll.
    exit 0
fi

if [ "$http_code" != "200" ]; then
    echo "poll: GitHub API returned HTTP ${http_code}; retrying next tick"
    exit 0
fi

sha="$(tr -d '[:space:]' < "$body_file")"
new_etag="$(awk 'tolower($1) == "etag:" { $1=""; sub(/^ /,""); print; exit }' "$hdr_file" | tr -d '\r')"

if ! printf '%s' "$sha" | grep -Eq '^[0-9a-f]{40}$'; then
    echo "poll: response was not a 40-char sha ('${sha}'); retrying next tick"
    exit 0
fi

deployed=""
[ -f "$SHA_FILE" ] && deployed="$(tr -d '[:space:]' < "$SHA_FILE")"

# persist_etag: only once the polled sha is fully handled, so a failed deploy
# is retried on the next tick instead of being masked by a 304.
persist_etag() {
    if [ -n "$new_etag" ]; then
        printf '%s' "$new_etag" > "$ETAG_FILE"
    fi
}

if [ "$sha" = "$deployed" ]; then
    persist_etag
    exit 0
fi

# ── 2. Preconditions (bootstrap must be complete) ────────────────────────────
if [ ! -f "$TOKEN_FILE" ]; then
    log "ERROR: ${TOKEN_FILE} missing — bootstrap incomplete (see docs/DEPLOY.md). Cannot deploy ${sha}."
    exit 1
fi
if [ ! -f "${BASE_DIR}/.env" ]; then
    log "ERROR: ${BASE_DIR}/.env missing — bootstrap incomplete. Cannot deploy ${sha}."
    exit 1
fi

# ── 3. GHCR login + image-readiness gate ─────────────────────────────────────
# Ephemeral login (logged out on exit) so other stacks on this box keep
# pulling anonymously — same behaviour as the old push-based deploy.
trap 'docker logout ghcr.io >/dev/null 2>&1 || true; rm -f "$hdr_file" "$body_file"' EXIT

if ! timeout 60 docker login ghcr.io -u "$GHCR_USER" --password-stdin < "$TOKEN_FILE" >/dev/null 2>&1; then
    log "ERROR: docker login ghcr.io failed — is ${TOKEN_FILE} a valid read:packages token?"
    exit 1
fi

for img in "${IMAGES[@]}"; do
    if ! timeout 60 docker manifest inspect "ghcr.io/${REPO}/${img}:${sha}" >/dev/null 2>&1; then
        # CI for this sha hasn't finished (or failed). Retry next tick; a
        # newer sha supersedes this one automatically.
        log "sha ${sha}: ${img}:${sha:0:12} not on GHCR yet (CI still building?); retrying next tick"
        exit 0
    fi
done

# ── 4. Deploy ────────────────────────────────────────────────────────────────
log "deploying ${sha} (was ${deployed:-<none>}) — full output follows in ${LOG_FILE}"
exec >> "$LOG_FILE" 2>&1
echo "$(ts) ── deploy ${sha} start ──"

# Sync the repo checkout to the exact sha (clone on first run).
if [ ! -d "${REPO_DIR}/.git" ]; then
    timeout 300 git clone "https://github.com/${REPO}.git" "$REPO_DIR"
fi
timeout 120 git -C "$REPO_DIR" fetch origin "$BRANCH"
if ! git -C "$REPO_DIR" cat-file -e "${sha}^{commit}" 2>/dev/null; then
    echo "$(ts) sha ${sha} not found after fetch (force-push or API/clone race); retrying next tick"
    exit 0
fi
git -C "$REPO_DIR" checkout --force --detach "$sha"

# Compose file sync (replaces the old workflow's scp step).
install -m 644 "${REPO_DIR}/docker-compose.yml" "${BASE_DIR}/docker-compose.yml"

cd "$BASE_DIR"

# Pin the compose image namespace to this repo (survives .env drift) —
# identical to the old push-based deploy.
if grep -q '^GITHUB_REPOSITORY=' .env; then
    sed -i "s|^GITHUB_REPOSITORY=.*|GITHUB_REPOSITORY=${REPO}|" .env
else
    echo "GITHUB_REPOSITORY=${REPO}" >> .env
fi

# Pull BEFORE touching running containers — a failed pull leaves the stack
# exactly as it was.
timeout 900 docker compose pull laravel nextjs react-admin

# -T + </dev/null: without them `compose run` eats stdin and the script dies
# here "successfully" (same guard as the old workflow).
if ! timeout 600 docker compose run --rm --no-deps -T laravel php artisan migrate --force < /dev/null; then
    echo "$(ts) ***** MIGRATION FAILED for ${sha} — .deployed-sha NOT advanced; will retry next tick. Containers were NOT recreated. *****"
    exit 1
fi

timeout 300 docker compose up -d --remove-orphans

docker image prune -f || true
docker compose ps || true

# Success — advance the pointer; only now does the poll go quiet again.
printf '%s' "$sha" > "$SHA_FILE"
persist_etag
echo "$(ts) ── deploy ${sha} OK ──"
