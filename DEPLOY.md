# Karyalay Portal - Coolify Fresh Main-Branch Deployment

Target domain: `https://karyalay-portal.divyajivan.com`

Coolify target: `4.3.14`. This version supports `SERVICE_PASSWORD_64_*` Magic Environment Variables for Git-source Docker Compose deployments.

This repository is prepared for this workflow:

1. Keep/backup the old deployment if required.
2. Delete the old Karyalay Coolify application/resource.
3. Create a **new** Coolify resource from GitHub `main` using Docker Compose.
4. Set only the non-generated fresh-install variables; Coolify generates all passwords.
5. Deploy.

DNS-01, wildcard certificates, Cloudflare, and Traefik ACME configuration are **outside this repository and must not be recreated by this deployment**.

## Why a new resource is a fresh database

The Compose file declares `db-data` and `app-storage` as named volumes. A newly created Coolify application/resource uses a new Compose project identity, therefore it receives new named volumes. The MariaDB `db-data` volume starts empty and Laravel migrations create the schema from scratch.

The repository contains no old database SQL dump and no demo database restore.

A later redeploy of the **same** resource reuses its existing named volumes and therefore preserves the database.

## Coolify variables before first successful start

Only one required user-supplied login value:

```text
SUPER_ADMIN_EMAIL=<new super admin email>
```

Do **not** type database or Super Admin passwords. `docker-compose.yml` uses Coolify Magic Environment Variables and Coolify generates/persists these automatically:

```text
SERVICE_PASSWORD_64_SUPERADMIN
SERVICE_PASSWORD_64_DATABASE
SERVICE_PASSWORD_64_DBROOT
```

After the new resource is parsed/created, open Coolify **Environment Variables** and reveal/copy `SERVICE_PASSWORD_64_SUPERADMIN` for the first Super Admin login. The two database passwords normally never need to be manually copied anywhere.

Recommended values:

```text
APP_NAME=SMVS Storage
APP_URL=https://karyalay-portal.divyajivan.com
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
TZ=Asia/Kolkata
DB_DATABASE=karyalay
DB_USERNAME=karyalay
```

`APP_KEY` is optional and is not a password field you need to create. If absent, the app generates a valid Laravel key once and stores it in persistent `app-storage` without printing the key.

## Host upload directory

The existing media policy is preserved:

```bash
sudo mkdir -p /srv/media/projects/karyalayportal/uploads/{images,videos,audios,documents}
sudo chown -R 33:33 /srv/media/projects/karyalayportal
sudo chmod -R 775 /srv/media/projects/karyalayportal
```

## First startup sequence

```text
MariaDB initializes empty db-data
        |
        v
php artisan migrate --force
        |
        v
DatabaseSeeder -> SuperAdminSeeder
        |
        | uses Coolify-generated SERVICE_PASSWORD_64_SUPERADMIN
        |
        v
config/route/view cache
        |
        v
Apache starts
```

No `migrate:fresh`, `db:wipe`, legacy SQL import, demo users, demo categories, or demo media are executed.

## HTTPS / Laravel redirect fix

The old production issue was:

```text
HTTP/2 302
Location: http://karyalay-portal.divyajivan.com/login
```

The repo now has two permanent protections:

- Laravel trusts Traefik forwarded headers in `bootstrap/app.php`. The default `TRUSTED_PROXIES=*` avoids hard-coding the old Traefik address because a new Coolify resource can create a different Docker network/IP.
- `AppServiceProvider` pins production absolute URL generation to HTTPS `APP_URL`, so `route('login')` generates the canonical HTTPS URL.

Expected result:

```text
HTTP/2 302
Location: https://karyalay-portal.divyajivan.com/login
```

Production session cookies are configured `Secure`.

## Health checks after deploy

Verify in this order:

```text
1. app container healthy
2. db container healthy
3. Laravel /up returns success internally/publicly as applicable
4. HTTPS / returns 302
5. Location header is HTTPS /login
6. login page loads
7. Super Admin can log in
8. session cookie is Secure
9. DB has the new Super Admin and no legacy/demo records
10. uploads path remains writable
```

## Rollback

Because this workflow intentionally creates a new Coolify resource, the safest rollback is to keep the old deployment backup/commit/DB backup until the new resource is verified. Application source can be rolled back by Git commit. Database rollback requires restoring the DB backup into an appropriate clean database volume; do not run destructive database commands without a verified backup.

## v02.01 services and search setup

Release `02.01` adds two production services to the Compose stack:

- `meilisearch` - internal full-text/fuzzy search engine with persistent `meili-data`
- `worker` - Laravel database queue worker for thumbnails, checksums, metadata and search indexing

The Compose file also uses one additional Coolify-generated secret:

```text
SERVICE_PASSWORD_64_MEILI
```

Optional search environment values:

```text
MEILISEARCH_INDEX=karyalay_media_files
```

`MEILISEARCH_HOST` is already set to the internal Docker service URL and normally should not be changed in Coolify.

After the first v02.01 deployment:

```text
1. Log in as Super Admin.
2. Open Settings -> Appearance -> Fonts and confirm the desired language font assignments.
3. Open Settings -> Search.
4. Review Option B / Option E and feature toggles.
5. Click Sync Settings to Meilisearch once to apply settings and rebuild the index for existing media.
6. Test a normal search and a typo example such as Gurupurnma -> Gurupurnima.
7. Upload a test file and confirm the queue worker creates processing metadata/thumbnail as supported.
```

The application falls back to basic database name/tag search if the search engine cannot return results, so a Meilisearch outage does not make basic search unusable.

### Frontend build note

v02.01 adds self-hosted Fontsource npm packages. The Docker asset stage uses `npm ci` against the updated lockfile. The build environment needs npm registry access to download the self-hosted Fontsource packages.

### Media volumes

The host media folder remains `/srv/media/projects/karyalayportal/uploads`, but v02.01 mounts it inside the container at private `storage/app/media/uploads` rather than public storage. Existing host media therefore remains in place while direct `/storage/uploads/...` access is blocked.

The queue worker shares the same `app-storage` and uploads bind mount as the web application. Do not remove those shared volume mappings; otherwise background thumbnails/checksums will not see uploaded media.

## v03.00 access-control migration and verification

Release `03.00` adds the access-policy workflow and three database tables: `media_access_requests`, `portal_notifications`, and `audit_logs`. It also adds `access_policy` and `download_allowed` to `media_files`.

After deploy/redeploy:

```text
1. Run normal Laravel migrations (entrypoint already uses migrate --force).
2. Log in as Super Admin.
3. Open Settings -> Search and run Sync Settings to Meilisearch once.
4. Upload three small test files: Public, Protected, Private.
5. Log in as a user from another department.
6. Confirm Public previews/downloads according to the download toggle.
7. Confirm Protected metadata is visible but thumbnail/preview/download is locked.
8. Submit View Only request; owner Department Admin approves; preview should work and download should remain denied.
9. Submit/approve a View + Download request; download should work.
10. Confirm Private file is absent from browse/search for the other department.
11. Confirm notifications appear in the header bell and requests appear under Access Requests.
12. Confirm access request/decision and delivery events are written to audit_logs.
```

Existing media rows receive `access_policy=public` and `download_allowed=true` during the migration to avoid silently hiding existing content. Review/reclassify existing sensitive content after deployment.


## v04.00 post-deploy configuration

1. Run Laravel migrations.
2. Sign in as Super Admin and open `Settings -> Metadata & Master Data`.
3. Create/verify official Country -> State -> City -> Mandir hierarchy, Event / Prasang, Guruji / Person, Language and Media Type lists.
4. Review the mandatory metadata rules and year range before normal users upload new files.
5. Open `Settings -> Search` and run `Sync Settings to Meilisearch` once so the new structured metadata attributes become searchable/filterable.
6. Open `Settings -> Notifications` and configure Portal/Email/WhatsApp/Text SMS channel settings. Keep Email/WhatsApp/SMS disabled until provider credentials and delivery workers are staging-validated.
7. Existing media is preserved with nullable new metadata fields; backfill can be done in a later operational pass.


## v05.00 lifecycle migration and staging checks

After deploying v05.00:

1. Run normal Laravel migrations. The migration creates `media_file_versions`, adds soft-delete/lifecycle fields, and backfills every existing current file as version 1 without moving bytes.
2. Start the queue worker and verify a new upload updates checksum on its current version row.
3. In staging, open a file and upload a replacement version larger than 50 MB to verify chunk progress and version history.
4. Restore an older version and confirm it becomes a NEW higher version number.
5. Move a file to `Settings -> Lifecycle / Recycle Bin`, restore it as Department Admin, then verify permanent delete is rejected for Department Admin and allowed only for Super Admin.
6. Verify archive/unarchive and duplicate-warning UI.
7. Do not configure automatic Recycle Bin purge yet; retention is a Management Decision Pending.


## v06.00 Search & Discovery deployment checks

1. Run Laravel migrations to create `saved_searches`, `media_favorites`, `media_recent_views` and new search indexes.
2. Login as Super Admin and confirm System Information shows **v06.00**.
3. Open `Settings -> Search` and run `Sync Settings to Meilisearch` once to apply uploader/metadata-alias/filterable attribute changes and rebuild documents.
4. Validate Search Everywhere vs Current Department as users from at least two departments, including Private files.
5. Validate advanced filters, date ranges and Best Match/Recently Updated sorting.
6. Save/apply/delete a Saved Search; verify another user cannot delete it.
7. Favorite/unfavorite assets and verify Favorites is user-specific.
8. Open assets and verify Recently Viewed order/count is user-specific.


## v07.00 Upload / Bulk / Integrity deployment checks

1. Run the normal migration command (`php artisan migrate --force`; the container entrypoint already does this).
2. Login as Super Admin and confirm `Settings -> System -> System Information` shows **v07.00**.
3. Open `Settings -> Upload & Integrity`; review allowed extensions, max file size, batch limit, chunk threshold/size, retry count and duplicate settings.
4. Remember that `0` means no **application-level** max size/count; confirm reverse proxy, PHP and container limits separately for the largest production AV file.
5. Test one direct upload and one file above the chunk threshold. Confirm UI reports transferred bytes / total bytes + percentage and that Cancel/Resume works.
6. Interrupt a chunked upload, resume it, and verify changing chunk settings mid-upload forces a safe restart instead of mixing chunks.
7. Test replacement-version chunk upload and verify final bytes exactly match the declared size before a version record is committed.
8. In Browse Files select multiple own-department assets and test Bulk Metadata, Tags, Move, Archive/Unarchive and Access Policy.
9. Confirm Department Operator cannot bulk archive, a non-owner department cannot edit another department's media, and only Super Admin can transfer owner department.
10. Enable exact duplicate detection, upload two identical files in the same department, let the queue worker process them and confirm the later asset shows an Exact Duplicate warning without automatic deletion.
11. If possible-duplicate warning is enabled, verify preflight can suggest same-name + same-size visible assets but never reveals Private files from another department.
12. Run the v07 feature test suite in staging/CI and then perform browser UAT before production.


## v08.00 Notifications / Audit / Reports deployment checks

1. Backup the staging database and media, then deploy and run pending migrations.
2. Confirm `Settings -> System -> System Information` shows **v08.00**.
3. Confirm both Docker services are healthy: `worker` (queued Email/WhatsApp/SMS) and `scheduler` (access-request escalation scan).
4. In `Settings -> Notifications`, configure only the providers you intend to use. Secrets are encrypted; plaintext is not returned to the browser after save.
5. Before turning a channel ON, use **Test Channel**. Portal should deliver immediately; external tests should appear in `Dashboards & Reports -> Notification Delivery Report` and transition from queued to sent/failed.
6. For WhatsApp/SMS, confirm the provider endpoint accepts the JSON contract used by this portal and validate on provider staging/test credentials first.
7. Configure the event matrix. Test Protected Request -> Admin notification and Admin Decision -> User notification end-to-end with Portal plus each intended external channel.
8. If escalation is approved, enable it and configure first/repeat/max values. Verify `php artisan notifications:escalate-access` manually, then confirm the scheduler repeats automatically.
9. Test Notification Center read/unread state and user channel/category preferences. Central Admin master switches must override user preferences.
10. Open `Dashboards & Reports` as Super Admin, Department Admin and a normal user; confirm role-scoped counts and reports do not leak another department's activity.
11. Export Access and Activity CSV reports and confirm a `report.exported` audit event is appended.
12. Run Audit Integrity verification. Production MariaDB should reject direct UPDATE/DELETE attempts against `audit_logs`.
13. Keep audit retention automation OFF until Management approves retention.
14. Run PHPUnit + frontend build + browser UAT before production.


## v09.00 Organization / User Lifecycle / Access deployment checks

1. Run migrations; confirm users are backfilled to `status=active` and existing media receives `owner_user_id=uploaded_by`.
2. Keep the scheduler service healthy; `access:expire-temporary` runs every 15 minutes.
3. Open `Settings -> Security & Access` as Super Admin and review failed-login limit, lockout duration, session timeout, password minimum and protected-access defaults.
4. Leave Default Approval Expiry at `0` unless Management has approved a formal duration.
5. Create a test Sub-department and Team, assign a user, and verify department/unit hierarchy validation.
6. Give a test user an owned asset, then try Disable/Inactive and Department Transfer. Confirm a same-department successor is required and `uploaded_by` is not rewritten.
7. Verify approved access becomes Revoked and pending requests become Cancelled during lifecycle/department transitions.
8. Verify Disabled/Inactive login is blocked, repeated invalid login reaches lockout, inactivity timeout redirects to login, and temporary-password users are forced to change password.
9. In Profile verify Active Sessions, individual logout and Logout All Other Sessions. As Super Admin verify Logout All User Sessions.
10. Approve a protected request with a short expiry, confirm preview/download works before expiry, confirm signed download URL expires, and confirm scheduler changes the request to Expired.
11. Submit bulk access by Event/Category/Sub-category and validate configured maximum size.
12. Create a Department Admin delegation window to another active department user and verify that delegate can review only during the active window.
13. Run PHPUnit and browser UAT before production rollout.


## v10.00 Integrations & Storage Health deployment checks

1. Run migrations. Existing `media_files.file_path` values are registered as primary `media_sources`; source bytes are not moved.
2. Confirm `Portal Local Media Storage` appears in `Integrations & Health` and run **Check Now**. Verify capacity/used/free values.
3. For NAS, mount the share into the application/scheduler/worker containers read-only or with the approved permissions, then configure the root path. Source locators must be relative to that root.
4. Configure Google Drive. For private/account health use an access token with appropriate Drive scope; public per-file references may use an API key or public URL fallback. Credentials are encrypted in the database.
5. Create/test a YouTube reference and confirm iframe playback plus Retry Check/oEmbed status.
6. Create a reference-only asset and verify configured mandatory metadata rules are enforced.
7. Attach a second source to an existing logical asset, make it Primary, then disable/re-enable it and verify source badges/status.
8. Simulate a missing NAS/local file or inaccessible external reference. Verify Missing/Broken date, Last Success, owner/Super Admin notification and issue queue.
9. Replace/repair the locator and Retry Check; confirm the source returns Active and the audit trail records the repair.
10. Keep the scheduler service running: `integrations:health-check` is scheduled every 15 minutes but respects each connection's configured frequency.
11. Open `Settings -> Search` and run **Sync Settings to Meilisearch** once so v10 source arrays/status attributes become filterable/indexed.
12. Runtime UAT must cover real production mounts/network/firewall/OAuth permissions before rollout.


## v11.00 Backup / Restore / DR deployment checks

1. Run migrations and confirm System Information shows **v11.00**.
2. Ensure the Laravel scheduler is running; `backup:scheduled` is checked hourly and only creates classes explicitly enabled in Admin UI.
3. Open `Settings -> Backup & DR` as Super Admin. Keep schedules, retention, RPO and RTO at their Policy Pending defaults until Management/IT approves actual values.
4. Choose Protected Local Storage or an approved existing writable absolute filesystem/NAS destination. Never place backups under the public web root.
5. Record the responsible backup owner and the explicit external-source responsibility boundary. NAS/Google Drive/YouTube source content is not embedded in the portal backup.
6. Securely escrow the production **APP_KEY** outside this backup. A v11 backup cannot be decrypted without the matching APP_KEY. Do not store APP_KEY inside the same archive/location only.
7. Click **Create Backup Now**. Confirm the run becomes Success and Verified. Download the encrypted `.kpbackup` if an approved off-host copy is required.
8. Verify the backup history shows SHA-256, size, scope and automatic verification time. Test manual Verify as well.
9. Prepare a **fresh migrated staging database** with the same application version and matching APP_KEY. Do not restore over a live/non-empty portal DB.
10. Restore using `php artisan backup:restore-file "/secure/path/file.kpbackup" --confirm=RESTORE-EMPTY-DATABASE`. In production, first run `php artisan down` and also pass `--force-production`.
11. Confirm restored row-count verification passes, then smoke-test login, departments, roles, settings, media metadata, access rules, integrations and audit history.
12. In `Backup & DR`, record the restore test as Passed/Failed with duration and evidence notes.
13. After Management approves RPO/RTO, enter targets and confirm DR Readiness changes from Policy Pending to Ready/Warning based on actual backup age and restore-test duration.
14. After Management approves daily/weekly/monthly retention windows, configure them and run a Retention Scan in staging before production. Manual backups are intentionally never auto-pruned.
15. Validate backup-failure and RPO-warning notification routing using approved Portal/Email/WhatsApp/Text SMS channels.
16. Run PHPUnit, frontend build and browser UAT before production rollout.


## v12.00 staging UAT / go-live gate

1. Run migrations and verify `Settings -> System -> System Information` shows **v12.00**.
2. Ensure cron/scheduler invokes `php artisan schedule:run` every minute; the UAT/Go-Live page must show a fresh scheduler heartbeat.
3. Follow `UAT_EXECUTION_GUIDE.md`; record evidence and sign off every Passed P0 case.
4. Execute a real v11/v12 backup restore into a fresh staging database with the matching APP_KEY and record the restore verification.
5. Test real NAS/Google Drive/YouTube/network/browser behavior and notification provider delivery used in production.
6. Resolve or formally risk-accept every Management dependency.
7. Record deployment/rollback/business/smoke/support owners and confirm change freeze.
8. Run `php artisan readiness:check`; a non-zero exit blocks deployment approval.
9. Follow `GO_LIVE_RUNBOOK.md` for deployment, smoke test, final server-enforced approval and rollback.


## v12.01 pre-go-live stabilization deployment checks

1. Deploy the exact v12.01 artifact and run migrations; the stabilization migration adds release-version evidence columns to UAT, readiness snapshots and go-live reviews.
2. Confirm `VERSION` and `config/version.php` both report **12.01**. `php artisan readiness:check` now blocks a mismatch.
3. Any UAT approval created by an earlier release is stale for v12.01. Re-run/save/sign-off the required P0 case against v12.01.
4. Create a new backup after v12.01 deployment and confirm its latest checksum verification is Passed.
5. Perform the real staging restore using a verified v12.01 backup and record Passed restore-test evidence; older-release restore evidence does not satisfy the v12.01 gate.
6. For every Management dependency moved to Resolved or Risk Accepted, record a decision/owner/approval reference note.
7. Record a parseable planned go-live date/time, owners, rollback window and change freeze; run `php artisan readiness:check` immediately before final approval.



## v16.00 deployment checks

1. Confirm the exact deployed artifact reports **16.00** and `VERSION` matches `config/version.php` (previous `15.01`).
2. Run migrations and confirm existing users retain their stored theme values without database errors.
3. Sign in with an existing user and open Profile -> Color Theme. Confirm all 16 presets are available.
4. Preview/save both Light and Dark presets and verify the saved account default survives reload.
5. Confirm the header Sun/Moon button remains a temporary viewing-mode override.
6. Verify app, worker and scheduler still mount `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads`.
7. Confirm the app healthcheck remains configured and `/up` is healthy.
8. Execute the v16.00 UAT regression focus before production Go-Live approval.

## v15.01 deployment checks

1. Confirm the exact deployed artifact reports **15.01** and `VERSION` matches `config/version.php` (previous `15.00`).
2. Run `php artisan migrate --force`; verify the v15 identity/role/network/theme migration completes and existing users are mapped to built-in Portal Roles without losing legacy role/department/permission data.
3. Run queue workers and `php artisan schedule:run` every minute. Confirm the app/worker/scheduler all retain `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads`.
4. Before enabling Network Policy, configure and test real office CIDRs and approved VPN ranges. Keep enforcement OFF during first migration if the production ranges are not yet verified, to prevent administrative lockout.
5. In **Settings -> Users & Roles**, verify Super Admin user creation, role/page-right management, User Groups, enable/disable and individual/bulk password-reset email. Verify Department Admin actions stay inside its department and cannot assign admin-level roles.
6. Configure/test per-user External Internet Access: allowed/blocked, optional start/expiry, reason, approver, revoke, Department Admin delegation boundary and audit events. Test one Internal, one VPN, one blocked External and one explicitly allowed External login before production acceptance.
7. Verify Profile Change Password and user-persisted SMVS/Slack/Google-inspired color theme.
8. Review friendly Super Admin forms for Maintenance, Branding, Network/VPN, 2FA, Lifecycle, Type Required Metadata, Watermark, Storage Quotas, Audit Retention, Recycle Bin Retention and User Guide Access. Keep destructive retention at `0 / OFF` until Management-approved values are entered.
9. Validate Forgot Password/admin reset SMTP delivery, TOTP 2FA, Folder/Collection/Related Assets, governed lifecycle transitions, XLSX import/export limits and Scheduled Report scope revalidation.
10. Verify User Guide 0/partial/full 22-page rights, HTML/DOCX controls and Local Storage host/container/probe path. Upload a representative file, redeploy, and confirm preview/download still works from the persistent `/srv/media` bind.
11. Run the full `UAT_EXECUTION_GUIDE.md` against exact v15.01 and record release-bound evidence.
12. Run `php artisan readiness:check` only after current-release UAT evidence, current verified backup/restore evidence and Management dependencies are complete.

Do not mark UAT/Go-Live rows Done merely because v15 source coding/static validation exists.

## v15.01 Coolify healthcheck verification

v15.01 carries the same Laravel health endpoint at `/up`, now declared both in Docker Compose and in the final Docker image. After redeploy, verify the app container reports a configured healthcheck. From the Coolify app-container terminal, `curl -fsS http://127.0.0.1/up` must exit successfully. Do not enable traffic-blocking health routing until this probe is green. Worker, scheduler and Meilisearch are explicitly excluded from Coolify aggregate health because they do not expose the app HTTP readiness endpoint; MariaDB retains its own healthcheck.
