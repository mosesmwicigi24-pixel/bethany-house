# Audit trail

Every action on the hub can be attributed to a person, a time and a place, and
none of it can be quietly removed. Owner decision, 2026-09-21.

## The three records

| Table | Holds | Written by |
|---|---|---|
| `activity_log` | What **changed**: every create/update/delete of a business record with before and after values; logins (and refused logins, and refused 2FA codes); settings changes old → new; approvals; the named business events 48 controllers already announce (`pos_payment_recorded`, …) | `App\Observers\AuditObserver` (automatic, `config/audit.php` → `observed_models`) and `ActivityLogService` |
| `request_logs` | What was **looked at**: every staff API call — who, path, status, IP, device, and for list endpoints how many records came back | `App\Http\Middleware\AuditStaffRequests` (after the response is sent; GETs de-duplicated per user+URL for 5 min) |
| `audit_seals` | A daily SHA-256 chain over both tables | `audit:seal` (00:20), checked by `audit:verify --days=7` (00:40) and a full `audit:verify` (Sun 01:30) |

Every entry carries a `request_id`; the same id is on the `request_logs` row
and in the `X-Request-Id` response header, so "who changed this" and "what else
did that request do" are one query.

## Why it cannot be quietly rewritten

- **The database refuses** `UPDATE`, `DELETE` and `TRUNCATE` on all three
  tables (trigger `audit_append_only`, migration `2026_09_21_000001`). The only
  exception is the retention prune of `request_logs`, which must run
  `SET LOCAL audit.allow_prune = 'on'` inside its transaction.
- **Nothing in the app deletes it.** `logs:purge-old` prunes `request_logs`
  past 365 days and never touches `activity_log`; "clear by date" no longer
  lists the audit tables; "full wipe" preserves them; `POST /activity-logs/clear`
  answers 403 and records the attempt; a backup **restore** restores everything
  except the audit objects (filtered `pg_restore -L` list — it refuses to run
  if the backup's table of contents cannot be read).
- **Tampering by someone who can drop the trigger is still detected**: the seal
  chain breaks from the first edited or deleted row onward. The newest seal is
  sent off the server daily (Phase 3), so a rewritten chain cannot also rewrite
  the copy in the owner's mailbox.
- **Retention and the owner address come from the environment**
  (`AUDIT_REQUEST_LOG_RETENTION_DAYS`, `AUDIT_OWNER_EMAIL`,
  `AUDIT_OWNER_ACCOUNT_EMAIL`), never from a setting a super admin can edit.

## Who can read it

Super admins only (`role:super_admin` on `/api/v1/admin/activity-logs/*`). It
used to be anyone with `users.view`.

API: `GET /activity-logs` (changes), `/activity-logs/requests` (staff calls,
filter `min_rows` for bulk reads), `/activity-logs/record/{type}/{id}` (one
record's history; `type` is a short model name from `observed_models`),
`/activity-logs/integrity` (latest seals + last verification).

## What it does not see (stated plainly)

- Query-builder mass updates (`Model::where(...)->update()`) fire no model
  events. The request log still records the call.
- Settings rows are written with the query builder; `SettingController`,
  `PaymentMethodController` and `DatabaseManagementController` record those
  changes explicitly (`ActivityLogService::settingsSaved`). A new settings
  writer must do the same.
- Values of sensitive fields (passwords, tokens, secrets, payment credentials)
  are never stored — the entry says the field changed.

## Operating it

```bash
php artisan audit:seal              # extend the chain now
php artisan audit:verify            # re-hash the whole history; exit 1 on any mismatch
php artisan audit:verify --days=7   # recent seals only
```

A failed verification writes `audit_verification_failed` to the trail.

## Incident — 2026-09-21: the database and the trail were both exposed

**What.** `hub.bethanyhouse.co.ke/db` served Adminer — a full database login
page — to the whole internet (HTTP 200). The nginx IP allowlist for it was
present but commented out (`# allow YOUR.IP.ADDRESS.HERE; # deny all;`).
Separately, four code paths could erase the audit trail: the weekly
`logs:purge-old` (90-day retention, read from a settings row a super admin
could lower to 30 — the oldest row was exactly 90 days old, so the next run
would have started deleting), "clear by date" (audit log pre-listed in the
ledger group), "full wipe", and `POST /activity-logs/clear`.

**Why undetected.** Adminer's container is bound to 127.0.0.1, which reads as
safe in `docker ps`; the exposure lived only in the host nginx config, which is
not in this repo. The purge had logged `deleted_count: 0` every week, so it
looked harmless until the history reached 90 days.

**Fix.** nginx `location /db { return 404; }` (backup of the previous config:
`/etc/nginx/backups/hub.bethanyhouse.co.ke.2026-09-21-pre-adminer-lockdown`);
Adminer stays reachable through an SSH tunnel —
`ssh -L 8085:127.0.0.1:8085 root@<host>` → `http://localhost:8085`. The trail:
this change (append-only trigger, purge rewritten, audit tables removed from
clear/wipe/restore, `/clear` refused, seal chain).

**Also audited.** Every nginx vhost on the box for database UIs: only
`neemadb.bethanyhouse.co.ke` (Neema's pgAdmin), which sits behind HTTP basic
auth (401) in front of pgAdmin's own login — left as is. No Postgres port is
published to the host; ufw exposes none.

**Prevention.** The trigger makes the next deleting code path fail loudly
instead of succeeding silently; `AuditTrailTest` pins every guarantee above.
