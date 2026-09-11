# Karyalay Portal - Fresh Coolify Installation

Coolify target: `4.3.14`. This version supports `SERVICE_PASSWORD_64_*` Magic Environment Variables for Git-source Docker Compose deployments.

This repository is intentionally prepared for **a new Coolify application/resource**.

## Fresh-vs-redeploy behavior

- **New Coolify resource:** Coolify creates a new Compose project / named volumes. `db-data` starts empty, MariaDB initializes, Laravel runs all migrations, then `SuperAdminSeeder` creates the initial Super Admin.
- **Redeploy same resource:** existing named volumes are reused. Only pending migrations run. Existing data and the existing Super Admin password are preserved.
- The repository contains **no legacy SQL restore**, `migrate:fresh`, or `db:wipe` startup action.

## Coolify variables

Set before the first successful deployment:

- `SUPER_ADMIN_EMAIL` - the email you will use to log in

Passwords are **not manually entered**. Coolify generates and persists three independent 64-character secrets from the Compose file:

- `SERVICE_PASSWORD_64_SUPERADMIN` - initial Super Admin password
- `SERVICE_PASSWORD_64_DATABASE` - MariaDB application-user password
- `SERVICE_PASSWORD_64_DBROOT` - MariaDB root password

After resource creation, reveal/copy `SERVICE_PASSWORD_64_SUPERADMIN` in Coolify Environment Variables for first login. Do not commit any generated value to Git.

Recommended / defaulted:

- `APP_URL=https://karyalay-portal.divyajivan.com`
- `TRUSTED_PROXIES=*`
- `SESSION_SECURE_COOKIE=true`
- `TZ=Asia/Kolkata`

`APP_KEY` is optional. When absent, the container generates a key once and stores it at `storage/app/.runtime-app-key` inside the persistent `app-storage` volume. The key value is never printed.

## HTTPS / login URL fix

There are two permanent layers:

1. `bootstrap/app.php` trusts Traefik forwarded HTTPS headers. The default is `TRUSTED_PROXIES=*` because a recreated Coolify resource can change Traefik's Docker IP and this app has no published host HTTP port.
2. `AppServiceProvider` uses the canonical HTTPS `APP_URL` for absolute URL generation in production, preventing `route('login')` from falling back to `http://.../login`.

Expected public check after deploy:

```text
HTTPS / -> HTTP 302
Location: https://karyalay-portal.divyajivan.com/login
```

Session cookies should include `Secure` over the production HTTPS origin.

## Database bootstrap

Startup performs:

```text
MariaDB healthy
  -> php artisan migrate --force
  -> php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force
  -> Laravel caches
  -> Apache
```

The seeder creates only the configured Super Admin on an empty DB, using the Coolify-generated `SERVICE_PASSWORD_64_SUPERADMIN` secret passed internally as `SUPER_ADMIN_PASSWORD`. It does not insert legacy/demo users, categories, or media.

## v02.01 additional services

A fresh v02.01 installation also starts:

- Meilisearch with persistent `meili-data`
- Laravel queue worker with access to the same media/storage volumes as the app

Coolify generates one additional search secret referenced by the Compose file:

- `SERVICE_PASSWORD_64_MEILI` - Meilisearch master/application key

After first login as Super Admin:

1. Open `Settings -> Appearance -> Fonts`; Hind Vadodara is the recommended/default Global and Gujarati font.
2. Open `Settings -> Search`; choose Option B Meilisearch or Option E Hybrid and review the per-feature guides/toggles.
3. Click `Sync Settings to Meilisearch` once to configure the search index and index current media.

Semantic/vector AI search is intentionally not enabled in v02.01.

## v03.00 first access-policy setup

After the first v03.00 login:

1. Run `Settings -> Search -> Sync Settings to Meilisearch` once after migrations.
2. Create/confirm departments and assign users before testing Private/Protected behavior.
3. Upload representative Public, Protected and Private test assets.
4. Test the approval workflow with a requester in one department and the owner Department Admin in another.
5. Verify View Only approval cannot download, while View + Download approval can.
6. Verify Private assets do not appear in other-department browse/search results.

Public in this application means registered internal portal users, not public Internet exposure.


## v04.00 initial administration

After the first migration/login, Super Admin should configure official master data under `Settings -> Metadata & Master Data`, confirm mandatory upload fields, and then sync Search settings to Meilisearch. Notification provider setup is under `Settings -> Notifications`; Portal is enabled by default while Email/WhatsApp/Text SMS are disabled until provider delivery is validated.


## v05.00 lifecycle verification

After the first login as Super Admin, confirm `Settings -> System -> System Information` shows **v05.00**. Upload a test file, open File Details and confirm version `v1`. Upload a second version with a Change Note, restore v1, and confirm the restore creates a new higher current version. Move the test asset to `Settings -> Lifecycle / Recycle Bin` and restore it. Permanent deletion should be available only to Super Admin.


## v06.00 Search & Discovery verification

After migrations, confirm `Settings -> System -> System Information` shows **v06.00**. Under `Settings -> Search`, keep either Option B Meilisearch or Option E Hybrid active and run Sync Settings to Meilisearch. In Browse Files verify Search Everywhere / Current Department scope, Advanced Filters, Saved Searches, Favorites and Recently Viewed. Semantic/AI remains intentionally OFF.


## v07.00 first-login upload policy verification

After migrations, confirm `Settings -> System -> System Information` shows **v07.00**. As Super Admin open `Settings -> Upload & Integrity`, review the default allowed extensions and choose production limits. Defaults keep max file size and max batch count at `0` (no application-level limit), chunk threshold at 50 MB, chunk size at 10 MB and retry count at 3. Test direct and resumable uploads before enabling production use. Multi-select several test assets in Browse Files and verify Bulk Edit authorization. Keep exact SHA-256 duplicate detection enabled unless Management chooses otherwise; it only warns/links and never auto-deletes.


## v08.00 first-login notification and audit verification

After migrations, verify **v08.00**, then open `Settings -> Notifications`. Portal is the safe default channel; configure Email/WhatsApp/Text SMS only with approved provider credentials and use Test Channel before enabling event routing. Ensure the queue worker and scheduler services are running. Submit and decide one Protected access request, verify Notification Center and Delivery Report, then open `Dashboards & Reports` and run Audit Integrity verification. Do not enable automated audit retention purge; retention remains a Management Decision Pending.


## v09.00 first-login organization/security setup

After migrations, confirm System Information shows **v09.00**. As Super Admin create Department -> Sub-department -> Team hierarchy as required, review users under Access Control, and configure `Settings -> Security & Access`. The default protected-access expiry is `0` because Management has not approved a mandatory duration. Test a temporary approval and session controls before production use.


## v10.00 integration setup

After first migrations/login, confirm v10.00 in System Information. Open `Integrations & Health` as Super Admin. The seeded `Portal Local Media Storage` connection should exist. Add NAS/Google Drive/YouTube connections as required, set health-check frequency and storage warning threshold, then test each connection. Use `Create Reference-only Asset` for content that should remain at its source rather than being copied into portal storage. Keep scheduler + worker services running and perform Search sync after source configuration.


## v11.00 backup / DR first-login verification

After migrations, confirm **v11.00** and open `Settings -> Backup & DR` as Super Admin. Review the backup scope and choose the approved destination. Schedule, retention, RPO and RTO defaults remain deliberately uncommitted (`OFF` / `0`) until Management/IT decides them. Create one manual backup and verify it. Secure the matching APP_KEY separately. For an actual restore test, use a fresh migrated staging database and the guarded `backup:restore-file` command, then record result/duration/evidence in the Admin UI.


## v12.00 readiness setup

After migrations, v12 seeds the P0 UAT matrix and Management dependency list. Super Admin should open `Settings -> UAT / Go-Live`, record operational owners and run Automated Checks. The scheduler heartbeat is expected within five minutes after the scheduler is correctly configured. Do not approve go-live until staging UAT evidence and sign-off are complete.


## v12.01 readiness evidence verification

After migrations, confirm System Information shows **12.01**. Open `Settings -> UAT / Go-Live`: the panel should show v12.01, prior-release UAT approvals must not count toward the current release, and Sign Off must remain disabled until the current result is saved. A Passed restore-test must reference a backup with a Passed checksum verification. Resolved/Risk Accepted Management dependencies must contain a decision/approval note.


## v16.00 first-login identity/network/theme setup

After the fresh migration and initial Super Admin login, confirm **Settings -> System -> System Information** reports `16.00`. Then:

1. Open **Settings -> Users & Roles**. Verify built-in roles, create a staging user, test enable/disable, role assignment, User Group membership and password-reset email.
2. Configure real office CIDRs and approved VPN ranges before turning Network Policy ON. Default production posture after verified configuration is **Internal/VPN Only**; specific users may receive time-bounded External Internet exceptions.
3. Test Department Admin scope using a separate department account. External-access delegation must remain unavailable unless Super Admin explicitly enables it.
4. Verify Profile Change Password and per-user SMVS/Slack/Google-inspired color theme persistence.
5. Review Branding/default language, Maintenance Mode, 2FA, lifecycle transitions, type-specific metadata, watermark, storage quotas, Scheduled Reports, Audit Retention, Recycle Bin Retention and 22-page User Guide Access rights.
6. Confirm Integrations & Health shows persistent host `/srv/media/projects/karyalayportal/uploads` and container/probe `/var/www/html/storage/app/media/uploads`. Upload a staging asset and prove it survives a redeploy before production acceptance.

Retention/RPO/RTO values intentionally remain policy-driven. A zero retention value means **Policy Pending / no automatic destructive pruning**, not a missing code feature. Configure production values only after Management/IT approval, and execute one complete v16.00 staging UAT cycle before production.


## v16.00 default theme check

New accounts default to **SMVS Teal - Dark**. Users can choose any of 16 Light/Dark combinations in Profile -> Color Theme and save the selection as their account default.
