# SMVS Karyalay Portal - Project Change Notes

## 2026-09-08 - Application v13.01 Coolify npm-ci Deployment Fix

- Fixed Coolify build failure caused by npm 10.9.x strict peer-resolution validation against cross-platform optional WASM lock metadata.
- Added `.npmrc` with `legacy-peer-deps=true`; production Docker build continues to use `npm ci`.
- Media bind mounts remain unchanged for app/worker/scheduler.
- No schema or business-feature change.


## 2026-09-07 - v04.00

- Added Metadata & Master Data foundation with Super Admin UI.
- Added controlled Country/State/City/Mandir/Event/Person/Language/Media Type values and aliases.
- Added mandatory structured metadata to normal/resumable upload flows.
- Added Portal/Email/WhatsApp/Text SMS notification configuration foundation with Super Admin channel/event controls and encrypted secrets.

# SMVS Karyalay Portal - Fresh Main-Branch Build

## Purpose

Prepared for deleting/recreating the Coolify Karyalay application/resource and deploying the GitHub `main` branch as a fresh installation.

## Changes

- Removed `database/init/01_karyalay_data.sql`.
- Removed legacy/demo seeders and demo user bootstrap.
- Added idempotent `SuperAdminSeeder` driven by Coolify environment variables.
- Replaced manually supplied Super Admin, MariaDB user, and MariaDB root passwords with Coolify Magic Environment Variables (`SERVICE_PASSWORD_64_*`).
- Only `SUPER_ADMIN_EMAIL` remains a required login identity input; generated Super Admin password is revealed from Coolify Environment Variables after resource creation.
- Normal startup uses `migrate --force`; no destructive migration command is present.
- Added dynamic Laravel trusted-proxy support suitable for recreated Coolify networks.
- Default `TRUSTED_PROXIES=*` avoids coupling the repo to old Traefik IP `172.25.0.2`.
- Preserved `HandleInertiaRequests` and the `role` middleware alias.
- Added production canonical HTTPS URL enforcement using `APP_URL` so login redirects remain HTTPS.
- Enabled Secure session cookies by default.
- Added persistent automatic APP_KEY generation when Coolify does not supply one.
- Kept DB data in a named volume and uploads at `/srv/media/projects/karyalayportal/uploads`.
- No DNS-01, wildcard certificate, Cloudflare, or Traefik ACME configuration is included or changed by this repo.

## 2026-09-03 - MariaDB fresh-install root password compatibility fix

Live Coolify 4.3.14 deployment showed `SERVICE_PASSWORD_64_DATABASE` populated correctly but `SERVICE_PASSWORD_64_DATABASE_ROOT` resolved to an empty runtime value, causing MariaDB 11.8 to restart with: `Database is uninitialized and password option is not specified`.

The root-password magic variable identifier is now the simpler single-token `SERVICE_PASSWORD_64_DBROOT`. It remains an independent Coolify-generated 64-character password and is mapped only to `MARIADB_ROOT_PASSWORD`.

No database password is committed to Git. No DNS, TLS, Traefik resolver, wildcard certificate, application URL, trusted-proxy, storage, migration, or Super Admin behavior was changed by this fix.


## 2026-09-07 - Application v05.00

- Added immutable version history and current-version tracking.
- Added chunked Upload New Version with actual progress, Changed By/Date/Note, and restore-as-new-version.
- Added Recycle Bin/restore, Super Admin-only permanent delete, Archive/Unarchive and user-visible duplicate warning.
- Updated application version to 05.00.

## 2026-09-07 - Application v06.00

- Search & Discovery Completion delivered.
- Added structured metadata/date/uploader filters, Search Everywhere vs Current Department, Saved Searches, Favorites/Bookmarks and Recently Viewed.
- Extended Meilisearch/Hybrid indexing with uploader and master-data aliases; Semantic/AI remains OFF.
- Added DB fallback search improvements and search-focused indexes.
- Updated master BRD/development tracker to v2.6 with 39 Done / 26 WIP / 63 Pending (30.5%).


## 2026-09-07 - Application v08.00

- Added actual queued Email/WhatsApp/Text SMS delivery routing and Portal delivery through Central Admin event matrix.
- Added Notification Center/preferences, escalation scheduler and delivery tracking/report.
- Added role dashboards, Access/Activity reports and audited CSV export.
- Added hash-chained append-only audit logs with IP/session/source context and production DB tamper triggers.
- Application version is now 08.00.


## 2026-09-07 - Application v09.00

- Added Active/Disabled/Inactive user lifecycle, operational ownership succession and controlled department transfer.
- Added Department -> Sub-department -> Team hierarchy, custom least-privilege Permission Sets, access review CSV and user import foundation.
- Added temporary protected-access expiry/auto revoke, signed download links, bulk access requests and delegated approvals.
- Added configurable login lockout/session timeout/password minimum and active-session logout controls.


## 2026-09-07 - Application v10.00

- Added Local/NAS, Google Drive and YouTube source integration foundation with multiple sources per logical asset.
- Added scheduled source health/freshness checks, Active/Inactive/Missing/Broken states, storage capacity health and owner/admin alerts.
- Added Integrations & Health UI, reference-only assets and per-asset retry/repair/replace workflow.
- Integration credential secrets are encrypted and omitted from browser responses.

## 2026-09-07 - Application v11.00

- Added encrypted portal DB/metadata/config/audit backup archives and Super Admin Backup & DR controls.
- Added protected-local / filesystem destination, daily/weekly/monthly schedules, approved-retention controls and automatic integrity verification.
- Added guarded fresh-database restore command, restore-test evidence records and RPO/RTO DR readiness monitoring.
- Added backup failure / RPO warning notification events and explicit NAS/Drive/YouTube source-content ownership boundary.
- Updated master BRD/development tracker to v3.1: 88 Done / 19 WIP / 21 Pending (68.8%).


## 2026-09-07 - Application v12.00

- Added UAT evidence/sign-off and server-enforced Go-Live Readiness control center.
- Added automated readiness snapshots, scheduler heartbeat and `readiness:check` CLI gate.
- Added Management dependency and deployment/rollback/business/smoke/support ownership controls.
- Added final go-live approval gate, CSV evidence export, UAT guide and go-live runbook.
- Real staging acceptance cases remain WIP until executed and signed off; no UAT is pre-passed by development.


## 2026-09-08 - Application v12.01

- Pre-Go-Live stabilization only; no acceptance criteria pre-passed.
- Release-bound UAT execution/sign-off evidence added.
- Readiness snapshot/review now store app version.
- Backup readiness requires current Passed checksum verification and current-release restore evidence.
- Formal Management dependency decisions require notes.
- Planned go-live time server validation and unsaved-UAT sign-off UX guard added.


## 2026-09-08 - Application v13.00 Remaining Feature Completion

- Completed remaining non-UAT feature coding: Maintenance, password reset, 2FA, network/VPN policy, Folder/Collection/Related Assets, type-specific metadata, lifecycle transitions, watermark, XLSX, Scheduled Reports, retention engines, quotas, localization, Help/FAQ and Branding.
- Added security/data-consistency hardening for login network enforcement, password-reset lifecycle, branding assets, XLSX limits, scheduled-report scope and physical storage accounting.
- UAT remains intentionally open for one consolidated staging cycle after development completion.
