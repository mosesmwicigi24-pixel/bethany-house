# Deploys: pull-based (the box deploys itself)

Since 2026-08-10 the Bethany Hub uses **pull-based deploys**. GitHub Actions
only builds and pushes images to GHCR; **the VPS deploys itself** by polling
the repo's `main` sha and pulling the new images. **No inbound SSH from
GitHub to the VPS is ever needed.**

Why: runner→VPS SSH (the old `scp` + `ssh` deploy steps) repeatedly timed
out — Hostinger-level filtering of GitHub runner IPs plus fail2ban bans.
The sibling Neema repo has deployed pull-based successfully for months; this
is the same pattern.

## How it works

```
push to main
  └─ GitHub Actions: tests → build 3 images → push to GHCR
       ghcr.io/mosesmwicigi24-pixel/bethany-house/bethany-laravel:{latest,<sha>}
       ghcr.io/mosesmwicigi24-pixel/bethany-house/bethany-nextjs:{latest,<sha>}
       ghcr.io/mosesmwicigi24-pixel/bethany-house/bethany-react-admin:{latest,<sha>}
       (no deploy step — just a notice)

VPS, every ~60s (systemd timer → deploy/box-deploy.sh):
  1. GET api.github.com/repos/mosesmwicigi24-pixel/bethany-house/commits/main
     (public repo, unauthenticated; ETag-conditional so steady-state polls
     are 304s that don't count against the 60/hr rate limit)
  2. sha == /opt/bethany-house/.deployed-sha?  → done, exit
  3. sha changed → docker login ghcr.io (token from .ghcr-token) and check
     all three <sha>-tagged images exist on GHCR. Not yet? CI is still
     building → exit, retry next tick.
  4. Deploy (replicates the old SSH step exactly):
     - git sync /opt/bethany-house/repo to the sha
     - copy repo's docker-compose.yml → /opt/bethany-house/  (old scp step)
     - pin GITHUB_REPOSITORY in .env
     - docker compose pull laravel nextjs react-admin
     - docker compose run --rm --no-deps -T laravel php artisan migrate --force
     - docker compose up -d --remove-orphans
     - docker logout; docker image prune -f
     - write the sha to .deployed-sha
```

Failure semantics: **any failure leaves `.deployed-sha` unchanged**, so the
next tick retries the whole deploy. Pull happens **before** containers are
touched, so a failed pull leaves the running stack untouched. A migration
failure is logged loudly and does **not** recreate containers. A `flock`
prevents overlapping runs.

## Operator bootstrap (one-time, on the VPS)

> **Until this bootstrap is done, merges to main build images but NOTHING
> deploys — the currently running containers just keep running (safe).**

All commands as root on the VPS.

### 1. Provision a GHCR read token

Create a token that can *read packages only*:

- GitHub → Settings → Developer settings → Personal access tokens →
  **Fine-grained tokens** → Generate new token
  - Resource owner: `mosesmwicigi24-pixel`
  - Repository access: `bethany-house` (or all repos)
  - Permissions: **Packages: Read-only** — nothing else
  - (If fine-grained tokens won't offer Packages for your account, use a
    classic PAT with the single scope `read:packages`.)

Install it on the box:

```bash
install -m 600 /dev/null /opt/bethany-house/.ghcr-token
printf '%s' 'ghp_...token-here...' > /opt/bethany-house/.ghcr-token
```

### 2. Clone the repo checkout the box deploys from

```bash
git clone https://github.com/mosesmwicigi24-pixel/bethany-house.git /opt/bethany-house/repo
chmod +x /opt/bethany-house/repo/deploy/box-deploy.sh
```

The deploy script runs from this checkout, so it is versioned with the repo
and self-updates: each deploy syncs the checkout, and the *next* tick runs
the newest script.

### 3. Install and enable the systemd units

```bash
cp /opt/bethany-house/repo/deploy/box-deploy.service /etc/systemd/system/
cp /opt/bethany-house/repo/deploy/box-deploy.timer   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now box-deploy.timer
```

### 4. Seed the deployed sha (recommended)

So the first tick doesn't redeploy what is already running:

```bash
git -C /opt/bethany-house/repo rev-parse HEAD | tr -d '\n' > /opt/bethany-house/.deployed-sha
```

(Skip this if you *want* the first tick to run a full deploy — that is also
a fine way to verify the pipeline end-to-end.)

## Verification

```bash
# Timer is scheduled and firing
systemctl status box-deploy.timer
systemctl list-timers box-deploy.timer

# Per-tick logs (poll results, errors)
journalctl -u box-deploy.service -n 50 --no-pager

# What sha is deployed
cat /opt/bethany-house/.deployed-sha

# Full deploy transcript (pull/migrate/up output lives here)
tail -50 /opt/bethany-house/deploy.log

# End-to-end: merge a trivial change to main, wait for CI to publish the
# images (~5-10 min), then within ~60s the box should deploy it:
tail -f /opt/bethany-house/deploy.log
docker compose -f /opt/bethany-house/docker-compose.yml ps
```

## Rollback to push-based deploys

1. In `.github/workflows/deploy.yml`, uncomment the legacy
   "ROLLBACK PATH" block (strip the leading `  # ` from each line) and
   delete the pull-based notice `deploy:` job (two `deploy:` keys is
   invalid YAML).
2. Make sure the `VPS_HOST` / `VPS_SSH_KEY` (ideally `VPS_KNOWN_HOSTS`)
   repo secrets still exist.
3. On the VPS, stop the poller so the two mechanisms don't race:
   ```bash
   systemctl disable --now box-deploy.timer
   ```

## Rolling back a bad *release* (not the mechanism)

The box deploys whatever `main` points at. To roll back a bad deploy,
revert the offending commit on `main` (`git revert` + merge) — the box will
deploy the revert within ~60s of the images publishing. In an emergency you
can also pin manually on the VPS:

```bash
systemctl stop box-deploy.timer          # pause the poller
cd /opt/bethany-house
docker compose pull ...                  # or retag a known-good <sha> image
docker compose up -d --remove-orphans
# ...fix main, then:
systemctl start box-deploy.timer
```

## Files

| Path (repo)               | Path (VPS)                              | Purpose                          |
| ------------------------- | --------------------------------------- | -------------------------------- |
| `deploy/box-deploy.sh`    | `/opt/bethany-house/repo/deploy/…`      | The deployer (runs every tick)   |
| `deploy/box-deploy.timer` | `/etc/systemd/system/box-deploy.timer`  | Fires the service every ~60s     |
| `deploy/box-deploy.service` | `/etc/systemd/system/box-deploy.service` | One deploy tick (oneshot)     |
| —                         | `/opt/bethany-house/.ghcr-token`        | GHCR read token (600, operator)  |
| —                         | `/opt/bethany-house/.deployed-sha`      | Currently deployed commit        |
| —                         | `/opt/bethany-house/.poll-etag`         | ETag cache for the GitHub poll   |
| —                         | `/opt/bethany-house/deploy.log`         | Timestamped deploy transcript    |
| —                         | `/opt/bethany-house/.box-deploy.lock`   | flock guard                      |
