# Karyalay Portal Changelog

## 15.00 - 2026-09-10 - Major

**Release:** Identity, Role & External Internet Access Management

- Replaced raw JSON configuration blocks in the completion/settings area with administrator-friendly switches, fields, lists and guided controls for maintenance, branding/language, Network/VPN, 2FA, watermark, lifecycle, retention, storage quotas and User Guide governance.
- Added full **Users & Roles** administration: user creation, Department Admin scoped creation, built-in role page-right editing, safe custom roles based on least-privilege base roles, role assignment, status enable/disable, user groups and groupwise role application.
- Added administrator-triggered password-reset email for scoped users plus Super Admin all-user reset-email action; preserved public Forgot Password and user self-service Change Password.
- Added per-user **External Internet Access** entitlement with Allowed/Blocked state, optional start/expiry, reason, approver and request-time expiry enforcement. Default posture remains Internal/VPN Only when Network Policy is enabled.
- Added Super Admin policy switch controlling whether Department Admins may grant/revoke external Internet access for users in their own department. All external-access changes are audited.
- Login/network auditing now records Internal, VPN, External Internet or blocked access source. User-specific external exceptions are checked only after successful credential authentication.
- Added public-registration network restriction when Network Policy is enabled so account creation is limited to approved Internal/VPN sources.
- Added server-side page authorization middleware for governed sidebar modules so hidden/disabled role pages cannot be bypassed by direct protected endpoints.
- Hardened the shared dashboard payload so disabling a governed page also suppresses that page's supporting server-side data (access-request/delegation data, integration-health details, user/role/group administration data, network-policy summary, guide entitlement URLs and settings-only security values). Shared data that is legitimately required by Browse/Upload remains available.
- Tightened Department Admin group management so administrator accounts cannot be added/removed through department groups or accidentally demoted by group-role application; group roles remain limited to Operator/Viewer bases for Department Admin scope.
- Refined Profile UI with personal details, Change Password, network/external-access summary, 2FA/session controls and per-user SMVS/Slack/Google-inspired appearance selection persisted to the user profile.
- Expanded the governed User Guide to 22 logical pages covering Users & Roles, password administration, groups, profile themes and External Internet Access. Full DOCX remains restricted to users entitled to all guide pages.
- Preserved v14.00 persistent upload storage mapping on app/worker/scheduler: `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads`.
- UAT, real SMTP/provider delivery, real office/VPN/public-IP evidence, production-scale performance, Management policy approvals, backup/restore evidence and final Go-Live remain open gates.

## 14.00 - 2026-09-09 - Major

**Release:** Governed User Guide & Persistent Storage Verification

- Added a deployable **User Guide Manual** module with 18 logical pages, searchable in-portal navigation, authorized Print/HTML view, and bundled Word manual.
- Added Super Admin User Guide governance by role default, department override, and specific-user override (`0-18` pages). `0` hides the menu/direct guide content; precedence is User > Department > Role; Super Admin retains 18/18 pages for recovery.
- Enforced guide rights server-side. Partial entitlement returns only authorized HTML/UI pages; complete DOCX download requires 18/18 entitlement. Added no-store/nosniff/CSP/referrer protections for the printable HTML response.
- Integrated aggressive connection-audit fixes from the v13.06 stabilization baseline: real 2FA web middleware enforcement with safe mandatory-enrollment path and intended redirect after challenge; branding propagation into auth/shell/reset flows; dedicated lifecycle transition governance/status history; Scheduled Reports UI/API management and scheduler policy wiring.
- Corrected Portal Local storage observability so health/capacity and readiness write/read/delete probes target the actual nested persistent upload bind (`/var/www/html/storage/app/media/uploads`) instead of the parent `app-storage` volume. Integrations & Health now displays the authoritative host, container, and checked/probe paths.
- Kept the required persistent media bind on `app`, `worker`, and `scheduler`: `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads`. Container-reported host path is fixed to the same literal bind so UI cannot drift from Compose if an unrelated environment value is set.
- Added/expanded regression tests for User Guide access, 2FA enforcement, lifecycle governance, and current version binding.
- Updated the Final BRD to v3.5 / App v14.00 and bundled the complete User Guide DOCX + deployable HTML manual in the full source.
- Integrated UAT, production-scale performance verification, Management policy approvals, backup/restore runtime evidence, and final Go-Live remain open and are not marked Done by this release.

## 13.06 - 2026-09-09 - Minor

**Release:** Sidebar Navigation Blank-Screen Fix

- Fixed the long-standing dashboard navigation failure where selecting a sidebar module could leave the browser on a completely blank/dark React page while the URL remained unchanged; refreshing returned to Browse Files because the selected module existed only in local component state.
- Removed lazy/dynamic imports from the six critical sidebar modules (Batch Upload, Access Requests, Notifications, Dashboards & Reports, Integrations & Health, Settings). These modules are now part of the primary dashboard bundle, so navigation no longer depends on loading a separate JavaScript chunk after the click. Heavy modals remain lazy-loaded.
- Added URL-backed dashboard panel navigation using the `panel` query parameter (`?panel=upload`, `?panel=access`, `?panel=reports`, `?panel=integrations`, `?panel=settings`, `?panel=notifications`). Refresh and browser Back/Forward now restore the selected module instead of silently resetting to Browse Files.
- Added a panel-level React error boundary so an unexpected client-side rendering error is contained inside the affected module and cannot unmount the entire application shell into a blank screen.
- Browse/search/category actions intentionally return to the Browse Files panel and remove the panel query parameter.
- Preserved all existing completed features, database/data architecture, v13.05 Coolify runtime permission fix, and persistent media bind mount for app/worker/scheduler.
- No UAT, Management policy dependency, performance verification, or Go-Live item is marked complete by this bug-fix release.

## 13.05 - 2026-09-08 - Minor

**Release:** Coolify Runtime Cache Permission Fix

- Fixed the post-deployment Laravel health failure where `bootstrap/cache/config.php` was created as a root-only file and Apache (`www-data`) failed with `Permission denied`, followed by `ReflectionException: Class "config" does not exist`.
- Root cause was the v13.04 entrypoint using process-wide `umask 077` while generating the persistent runtime `APP_KEY`; that restrictive umask remained active for later `package:discover`, `config:cache`, `route:cache`, and `view:cache` commands.
- Scoped the private-key `umask 077` to a subshell so it applies only to `.runtime-app-key`, then explicitly restores runtime cache creation to `umask 022`.
- Added post-cache ownership/mode normalization for `bootstrap/cache` and compiled views so Apache can read generated Laravel cache files reliably.
- Preserved the persistent media bind mount for `app`, `worker`, and `scheduler`: `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads`.
- No business feature, database schema, or stored-media architecture change in this deployment-health release.

## 13.04 - 2026-09-08 - Minor

**Release:** Full Coolify Deployment Fix Bundle

- Full merge-ready source package based on v13.01 with the v13.02 npm/Coolify build fix and v13.04 lucide/Vite import fix applied directly to application source.
- Docker frontend build uses `npm install --no-audit --no-fund --ignore-scripts --legacy-peer-deps` to avoid npm 10.x lockfile peer-resolution failure around optional WASM dependencies.
- `IntegrationsPanel.tsx` now imports `Play as Youtube` from `lucide-react`; the invalid direct `Youtube` export that stopped Vite/Rollup is removed.
- Persistent media bind mount is unchanged and present on `app`, `worker`, and `scheduler`: `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads`.
- No business feature or database schema change in this deployment-fix release.

## 13.03 - 2026-09-08 - Minor

**Release:** Coolify Vite Icon Hotfix

- Identified the Vite/Rollup build failure caused by an unavailable `Youtube` named export from the resolved `lucide-react` package.
- Superseded by v13.04 because v13.03 patch delivery did not reliably modify the actual source file in the repository.

## 13.02 - 2026-09-08 - Minor

**Release:** Coolify npm Build Hotfix

- Replaced strict Docker-stage `npm ci` with `npm install --legacy-peer-deps` for the production asset stage after npm 10.x rejected cross-platform optional WASM lock metadata.
- Added project `.npmrc` with `legacy-peer-deps=true`, `audit=false`, and `fund=false`.


## 13.01 - 2026-09-08 - Minor

**Release:** Coolify npm-ci Deployment Fix

- Fixed Coolify/Docker frontend build failure where npm 10.9.x rejected the existing lockfile peer metadata for optional WASM dependencies (`@emnapi/*`).
- Added project `.npmrc` with `legacy-peer-deps=true` so the existing `npm ci` production build remains lockfile-driven while avoiding the cross-platform optional peer-resolution conflict.
- Confirmed `npm ci --dry-run` succeeds with npm 10.9.x using the updated project configuration.
- Persistent media bind mount remains configured for `app`, `worker`, and `scheduler` at `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads`.
- No business feature or database schema change in this patch release.

## 13.00 - 2026-09-08 - Major

**Release:** Remaining Feature Completion

This release completes the remaining **non-UAT application coding backlog**. Acceptance/UAT rows are deliberately not pre-passed; Management policy values that require approval remain explicit readiness dependencies.

### Security, login and administration

- Added Maintenance Mode with Super Admin bypass and a clear user-facing maintenance notice.
- Completed forgot-password/reset-token delivery flow with configurable expiry, single-use tokens, inactive-account rejection, case-insensitive user lookup and session revocation after reset.
- Added TOTP two-factor authentication, recovery codes, setup/confirm/disable/challenge flows and policy controls. Mandatory Super Admin 2FA cannot be enabled until the current Super Admin has enrolled.
- Added centralized IP/CIDR/VPN network policy enforcement for both authenticated requests **and login submission**, including trusted VPN proxy ranges and an explicit emergency Super Admin bypass.
- Added network-policy self-lockout prevention so a Super Admin cannot save a policy that immediately excludes the current administration session unless the configured emergency bypass remains valid.
- Hardened login and password-reset routes with throttling and case-insensitive identity handling.
- Added branding controls for portal name, login text, default language and logo. User-uploaded logos accept raster PNG/JPEG/WEBP only; SVG uploads are rejected and served branding assets use explicit MIME, `nosniff` and restrictive CSP headers.

### Information architecture, metadata and lifecycle

- Added Department-scoped Folder hierarchy with create/edit/enable-disable/delete controls, parent-cycle prevention and non-empty delete protection.
- Added Collections with create/edit/enable-disable/delete plus asset membership management.
- Added Related Assets linking for media/document relationships and Folder assignment from asset workflows.
- Added type-specific required metadata rules and centralized validation before governed lifecycle transitions.
- Completed controlled lifecycle transitions across Draft / Review / Approved / Published / Archived states with status history records and audit context.
- Added image/video derivative thumbnail handling plus generic fallback representation for supported content without a generated derivative.
- Added per-asset/global watermark policy controls and protected preview watermark overlay behavior.

### Reports, retention and storage governance

- Added native XLSX user import/export and report export while preserving CSV and browser Print / Save PDF.
- XLSX parsing is hardened with worksheet/shared-string size limits, row/column limits and decompression-bomb resistance checks.
- Added weekly/monthly Scheduled Reports with enable/pause, manual run, CSV/XLSX output, run history, SHA-256 verification and notification delivery.
- Scheduled report generation re-validates recipient role/status/department at runtime, preventing future delivery after a recipient loses scope.
- Added Audit Retention archive/prune engine with hash-chain verification before pruning, protected MySQL purge context and configurable retention. `0` remains Policy Pending and performs no automated deletion.
- Added Department storage quota and threshold-warning controls. Physical usage accounting includes active media, Recycle Bin content and stored historical-version bytes rather than only current logical rows.
- Added Recycle Bin retention engine with configurable days/max-per-run, daily scheduler, manual approved run, byte cleanup, audit record and search-index removal. Default is `0 / automatic purge OFF` until Management approves retention.
- Go-live readiness now cross-checks Recycle Bin retention policy or a formally accepted Management risk.

### UI/UX, localization and help

- Added Gujarati / English localization foundation with user language preference and localized core shell, Browse/Search, Upload, Access Request and Preview/Download flows.
- Added responsive and accessibility source improvements for critical flows, including clearer labels, focus-visible behavior and mobile/tablet layout handling.
- Added Help / FAQ covering Search, Upload, Access Requests, lifecycle/security and common operational tasks.
- Added remaining operational settings to Central Administration so branding, maintenance, network/VPN, 2FA, watermark, lifecycle, type-required metadata, quotas, report scheduling, Audit Retention and Recycle Bin retention do not require code edits.

### Hardening and regression coverage

- Added dedicated `RemainingFeatureCompletionTest` source coverage for Maintenance, network-login enforcement, password reset, 2FA, Folder/Collection/Related Assets, metadata/lifecycle rules, branding safety, storage quota, Scheduled Reports, localization, XLSX and Recycle Bin retention.
- Scheduled Reports snapshot recipient/department scope and validate current authorization again before every run/download.
- Version expectations and release-bound UAT/readiness tests now target `13.00` through `config('version.current')` where appropriate.
- Application version bumped `12.01 -> 13.00`.

### Development-completion boundary

All remaining non-testing feature coding tracked in the active P0/P1 scope is implemented in v13.00. The overall BRD tracker is intentionally **not** 100% because real staging UAT, production-scale performance validation, final Go-Live approval and Management-approved retention/RPO/RTO values require execution/decisions outside source coding.

### Runtime note

This build environment does not contain Composer `vendor/`, Node `node_modules/`, Docker/MariaDB or a deployed staging environment. PHP/TypeScript/config/package/ZIP static validation can be performed here; PHPUnit, migrations, queue/scheduler, real provider/integration/browser and full UAT execution must be performed against the exact v13.00 staging artifact.


## 12.01 - 2026-09-08 - Minor

**Release:** Pre-Go-Live Stabilization

- Bound UAT execution and sign-off evidence to the exact application release (`executed_app_version` / `approved_app_version`); prior-release approvals no longer satisfy the current readiness gate.
- Readiness snapshots and final go-live review records now capture the application version used for the decision.
- Replaced the hard-coded v12.00 readiness version check with a `VERSION` vs `config/version.php` consistency check so minor stabilization releases are validated correctly.
- Hardened backup readiness: the latest successful backup must also have a current Passed checksum verification.
- A Passed restore-test can no longer be recorded against an unverified backup.
- Go-live restore evidence must be linked to a currently verified backup created by the current application release.
- Backup readiness now cross-checks responsible owner and retention policy; blank/zero controls require explicit Management Risk Accepted status.
- Resolved / Risk Accepted Management dependencies now require a decision/owner/approval-reference note; blank formal decisions are rejected.
- Planned go-live date/time is server-validated as a date.
- UAT UI now prevents Sign Off while result/evidence changes are unsaved and displays execution/sign-off release version.
- UAT/readiness CSV export includes executed/approved release columns and uses the current application version in the filename.
- Added stabilization feature-test source covering release-bound sign-off, formal-risk notes and verified-backup restore evidence.
- Application version bumped `12.00 -> 12.01`; no acceptance criterion is marked Passed/Done by this stabilization release.

### Runtime note

This build environment still lacks Composer `vendor/`, Node `node_modules/`, Docker/MariaDB and required PHP runtime extensions for Laravel/PHPUnit boot. Real staging UAT remains mandatory.

## 12.00 - 2026-09-07 - Major

**Release:** UAT & Go-Live Readiness

- Added Super Admin `Settings -> UAT / Go-Live` control center with the 11 P0 acceptance cases mapped to AC-01 through AC-14.
- UAT cases support Pending/WIP/Passed/Failed/Blocked, execution notes, evidence references, executor/time and explicit Passed-case sign-off. Changing a signed result clears approval.
- Added automated readiness snapshots with blocking/advisory checks for application version, APP_KEY, database, migrations, media storage, queue failures, scheduler heartbeat, audit hash chain, search availability, integrations/sources, backup/RPO readiness, restore-test evidence, notification configuration, UAT sign-off, Management dependencies and operational owners/change freeze.
- Added scheduler heartbeat plus `php artisan readiness:check` CLI gate; CLI exits non-zero while blocking failures remain.
- Added explicit Management dependency tracking: production backup owner/destination, APP_KEY DR custody, retention, RPO, RTO, max upload size and Recycle Bin retention. Dependencies can be Resolved or formally Risk Accepted; Pending blocks go-live.
- Added go-live operational ownership/configuration: deployment owner, rollback owner, business sign-off owner, smoke-test owner, support contact, planned window, rollback window and change-freeze confirmation.
- Added server-enforced final Go-Live review. Approval creates a fresh readiness snapshot and is refused unless all blocking gates pass.
- Added CSV UAT/readiness evidence export and browser Print/Save PDF support.
- Added UAT/readiness/go-live tables to the encrypted application backup scope.
- Added `UAT_EXECUTION_GUIDE.md` and `GO_LIVE_RUNBOOK.md`.
- Added v12 feature-test source for UAT authorization/evidence/sign-off, dependency updates and blocked go-live approval.
- Bumped application version `11.00 -> 12.00`.

### Important

v12.00 delivers the **UAT/go-live execution and evidence system**. It does not falsely mark acceptance cases Passed. Real staging execution, actual restore/integration/network tests and business usability sign-off are still required before production go-live.

## 11.00 - 2026-09-07 - Major

**Release:** Backup, Restore & Disaster Recovery

- Added `backup_runs` and `restore_verifications` for recoverable backup history, integrity verification and restore-test evidence.
- Added encrypted application-native `.kpbackup` archives for portal DB/metadata, System Settings/configuration and audit/security records.
- Added protected-local or external/NAS filesystem backup destination configuration with public-web-root protection and explicit responsible-owner field.
- Added Super Admin `Settings -> Backup & DR` UI for backup scope, destination, daily/weekly/monthly schedules, retention windows, RPO/RTO targets and external-source backup responsibility.
- Schedules are OFF by default and all retention/RPO/RTO defaults are `0` = Policy Pending, so this release does not invent Management policy.
- Every successful backup is automatically read back, decrypted, payload-checksummed and table-count verified; Super Admin can also manually Verify and Download encrypted backup files.
- Added inner compressed-payload SHA-256 plus outer backup-file SHA-256. Backup archives are encrypted using APP_KEY; APP_KEY itself is intentionally excluded and must be held in separate DR custody.
- Added hourly `backup:scheduled` scheduler scan with due daily/weekly/monthly creation, approved-retention pruning and RPO monitoring. Manual backups are never auto-pruned.
- Added backup-failure and RPO-warning events to the existing Portal/Email/WhatsApp/Text SMS notification routing matrix, with RPO warning throttling.
- Added guarded `backup:restore-file` console restore for a fresh migrated database. Production restore requires maintenance mode, explicit confirmation and `--force-production`.
- Restore inserts only whitelisted portal tables, verifies restored row counts and appends a post-restore audit event.
- Added UI restore-test verification records with result, duration, target environment and evidence notes; DR readiness compares latest backup age against configured RPO and latest passed restore duration against configured RTO.
- Added explicit backup-boundary documentation: NAS/Drive/YouTube source bytes remain owned by source-system backup processes; media bytes require storage-level backup and are not embedded in the metadata archive.
- Added v11 feature-test source covering Super Admin authorization, encrypted backup/integrity verification, fresh-database restore, Policy Pending defaults and RPO/RTO readiness.
- Bumped application version `10.00 -> 11.00`.

### Deployment note

Run migrations, keep the scheduler/queue healthy, open `Settings -> Backup & DR`, choose the approved destination/scope and leave schedule/retention/RPO/RTO values at their Policy Pending defaults until Management/IT approves them. Create and verify a manual backup first, then perform an actual staging restore into a fresh migrated database using the same APP_KEY and record the restore verification evidence before production sign-off.

## 10.00 - 2026-09-07 - Major

**Release:** Integrations & Storage Health

- Added `integration_connections`, `media_sources` and `integration_health_checks` for first-class source/integration tracking.
- Added a default Portal Local Media Storage connection and migrated existing physical file paths into primary media-source records without moving source bytes.
- Added Local/NAS source integration with root-scoped path validation, storage capacity/free-space reporting and protected in-portal file delivery.
- Added Google Drive reference/access/embed support using Drive file IDs/URLs. Public references can be checked without credentials; API key or encrypted access-token modes are supported, with account/quota health when OAuth-style access token is configured.
- Added YouTube reference/embed/stream support using normalized video IDs/URLs and oEmbed availability checks.
- Added multiple sources per logical media asset; an authorized lifecycle manager can add, disable/enable, make primary, remove, retry-check or replace/repair source references from File Details.
- Added reference-only logical asset creation for NAS/Drive/YouTube/Local sources without duplicating the source bytes into portal storage; configured mandatory metadata rules still apply.
- Added source states Active / Inactive / Missing / Broken with Last Check, Last Success and Broken Detected timestamps.
- Added `Integrations & Health` admin dashboard with connection health, source issue queue, storage capacity/used/free metrics, configurable check/sync frequency and storage-warning threshold.
- Added scheduled `integrations:health-check` every 15 minutes; each connection/source is checked only when its configured freshness interval is due.
- Added owner + Super Admin alert routing for newly Missing/Broken sources and connection/storage warnings through the existing Portal/Email/WhatsApp/Text SMS event matrix. Recovery can also generate a file-update notification.
- Added repair/retry/replace workflow and audit events for integration create/update/delete/check and source add/update/check/remove actions.
- Added source type/status data to Meilisearch documents so alternate linked sources participate in source filtering/indexing after Search sync.
- Added Super Admin report-summary metrics for integration count, degraded/unavailable integrations, broken sources and aggregate integration capacity/free space.
- Added focused v10 feature-test source for encrypted integration credentials, source authorization/multiple sources, source disable/missing detection and Department Admin configuration masking.
- Bumped application version `09.00 -> 10.00`.

### Deployment note

Run migrations, keep both queue worker and scheduler healthy, configure connections under `Integrations & Health`, and run `Settings -> Search -> Sync Settings to Meilisearch` once so linked source types/statuses are indexed. Stage-test NAS mounts/permissions, Google Drive credential mode, YouTube embeds, missing/broken detection, notifications and repair workflow before production.


## 09.00 - 2026-09-07 - Major

**Release:** Organization, User Lifecycle & Access Maturity

- Added Active / Disabled / Inactive user lifecycle with status reason/history, session revocation and protected-access transition.
- Added operational asset custodian (`owner_user_id`) separate from immutable upload attribution. Disabling/inactivating or transferring a user with owned assets requires a same-department successor; uploader audit history is preserved.
- Added Department -> Sub-department -> Team hierarchy and per-user organization-unit assignment.
- Added controlled department transfer: approved protected entitlements are revoked, pending/more-info requests are cancelled and operational ownership is transferred before the user moves.
- Added custom Permission Sets that can reduce base-role privileges without escalating beyond the assigned role. Core upload/category/policy/lifecycle/delete routes enforce those reductions.
- Added user access-review administration with role/status/department/unit/permission-set/last-login visibility and CSV export. CSV user import is included; existing-user department/lifecycle changes are deliberately rejected from CSV and must use the controlled UI.
- Added Super Admin one-time temporary password issuance with mandatory change-on-login and immediate session revocation.
- Added failed-login lockout, disabled/inactive login blocking, configurable password minimum and inactivity session timeout under `Settings -> Security & Access`.
- Added Active Sessions visibility, individual session logout, Logout All Other Sessions and Super Admin Logout All User Sessions.
- Added optional temporary protected-access expiry on approval plus scheduled automatic revoke/notification. Default expiry remains `0` (no automatic default) until Management approves a formal duration.
- Added short-lived signed protected-download links, while delivery endpoints still revalidate the active entitlement.
- Added bulk protected access request by Event/Prasang, Category or Sub-category with an Admin-configurable maximum batch size.
- Added time-window delegated approvals: Department Admin can delegate approval authority to an active same-department user; decisions record the delegation used.
- Added notification routing to active delegates for new/resubmitted protected requests.
- Added focused v09 feature-test source for hierarchy, user lifecycle/ownership transfer, controlled department transfer, temporary expiry and delegated approval authorization.
- Bumped application version `08.00 -> 09.00`.

### Deployment note

Run migrations and keep the scheduler service healthy so temporary approvals expire automatically. Review `Settings -> Security & Access` before production. Management has not approved a mandatory protected-access duration, so the default remains `0`; reviewers may still assign explicit expiry hours. Stage-test disabled login, lockout, session timeout, ownership succession, controlled department transfer, bulk access and delegated approvals before rollout.


## 08.00 - 2026-09-07 - Major

**Release:** Notifications, Audit & Reports Completion

- Connected Protected access request/decision events to the Central Admin event-wise channel matrix.
- Added queued external delivery workers for SMTP Email, WhatsApp HTTP/Meta-style endpoints and generic HTTP Text SMS; Portal notifications remain synchronous.
- Added per-user channel/category notification preferences; Central Admin master channel/event switches always take priority.
- Added Notification Center panel with read/unread filters, categories and preference controls.
- Added provider delivery tracking (`queued/sent/skipped/failed`, attempts, recipient, provider, error) and Super Admin delivery report.
- Replaced placeholder notification tests with actual Portal delivery or queued provider test delivery.
- Added configurable pending-access escalation (first delay, repeat interval, max escalations), scheduled every 15 minutes with a dedicated production scheduler service.
- Added file-update notification events for new/restored versions and archive status changes.
- Added role-scoped Dashboards & Reports for Super Admin, Department Admin and users.
- Added Access/Download report and Activity/Security report with authorized CSV exports; browser Print/Save PDF is provided. Excel/server-generated PDF export remains pending.
- Added Notification Delivery report for Super Admin.
- Expanded audit coverage to login/logout/failed login, uploads, profile/password, user role/department, department/category/master-data/settings/font changes, report exports and v08 notification settings.
- Added audit Who/What/When + IP + session + source context.
- Added SHA-256 append-only audit hash chain verification. Production MariaDB/MySQL additionally receives DB triggers rejecting UPDATE/DELETE on `audit_logs`; model-level immutability protects application writes in all environments.
- Added Super Admin Audit Integrity verification UI/report.
- Added focused v08 feature-test source for notification routing/preferences/escalation, audit hash integrity/immutability and role-scoped reports.
- Added `scheduler` service to Docker Compose for escalation/scheduled tasks.
- Bumped application version `07.00 -> 08.00`.

### Deployment note

Run migrations, keep both `worker` and new `scheduler` services healthy, configure/test providers under `Settings -> Notifications`, then validate the Delivery Report before enabling external channels for production events. Audit retention is deliberately not automated until Management approves the retention period.


## 07.00 - 2026-09-07 - Major

**Release:** Upload, Bulk Operations & Integrity Completion

- Added Central Admin `Settings -> Upload & Integrity` controls for allowed extensions, application-level max file size, max batch count, simple/chunk threshold, chunk size (5-20 MB), retry count and duplicate-warning controls.
- Upload policy is enforced server-side for direct uploads, resumable uploads and replacement-version uploads; client checks are convenience only.
- Added upload preflight so disallowed file types/sizes are reported before transfer and visible same-name + same-size candidates can be warned without exposing Private metadata.
- Retained real upload progress with transferred bytes / total bytes + percentage for both overall queue and individual files.
- Hardened resumable uploads with per-user manifests, exact expected chunk count, chunk sequence validation, assembled-byte verification and protection against settings/file changes during resume.
- Added Bulk Edit for metadata, free tags, category/subcategory move, Super Admin department ownership transfer, Archive/Unarchive and Public/Protected/Private + download policy changes.
- Bulk operations enforce department ownership server-side; Department Operator cannot bulk archive and only Super Admin can transfer ownership across departments.
- Added hierarchy-safe bulk metadata changes, including automatic descendant clearing when a parent location is cleared and validation that selected State/City/Mandir relationships remain valid.
- Added append-only audit records for every affected asset and batch search re-indexing after bulk operations.
- Refined SHA-256 exact duplicate detection so Central Admin can enable/disable it; exact duplicate matching is department-scoped and never automatically deletes files.
- Added optional pre-upload possible-duplicate warning based on same filename + size, visibility-scoped to the current user.
- Added database indexes supporting bulk scope and possible-duplicate lookup.
- Added v07.00 feature-test source for upload-policy authorization/preflight, department-scoped bulk edit, archive authorization, move hierarchy validation and Super Admin ownership transfer.
- Bumped application version `06.00 -> 07.00`.

### Deployment note

Run normal migrations, then review `Settings -> Upload & Integrity` as Super Admin before production uploads. `0` means no application-level file-size or batch-count limit, but reverse-proxy/PHP/container infrastructure limits may still apply. Stage-test direct upload, resumable upload/resume, replacement-version upload, all Bulk Edit operations and duplicate warnings before production.


## 06.00 - 2026-09-07 - Major

**Release:** Search & Discovery Completion

- Completed structured Browse/Search filters for file type, date range, access policy, department, year, country/state/city/mandir, Event/Prasang, Guruji/Person, language, media type, uploader/owner, source and asset status.
- Added explicit Search Everywhere vs Current Department scope; Current Department is enforced server-side and respects existing Public/Protected/Private visibility rules.
- Added user-owned Saved Searches with create/apply/delete UI and backend ownership protection.
- Added Favorites/Bookmarks with per-user toggle, favorite quick view and count.
- Added Recently Viewed tracking with per-user quick view ordered by latest view time.
- Added active-filter chips, Clear All, advanced filter panel, responsive search/filter layout and improved empty-state guidance.
- Added Recently Updated sort alongside Best Match/Newest/Oldest/Name/Size options.
- Added uploader name and structured metadata aliases to Meilisearch documents; alias text also participates in transliteration indexing.
- Extended Meilisearch filterable attributes with uploader, date range and all structured metadata filters used by the UI.
- Improved database fallback search to cover description, category/subcategory, event, person, location, language and uploader names when the search engine is unavailable.
- Added search-focused database indexes for uploader/date, department/year, event/language and source/status combinations.
- Added v06.00 feature-test source for saved-search ownership, favorite visibility/security and recent-view upsert behavior.
- Preserved both Admin-selectable search modes: Option B Meilisearch and Option E Hybrid; Semantic/AI remains deferred/OFF.
- Bumped application version `05.00 -> 06.00`.

### Deployment note

Run migrations, then open `Settings -> Search` as Super Admin and run `Sync Settings to Meilisearch` once so uploader/alias/filter changes are applied and existing media is re-indexed. Validate Saved Search, Favorites, Recent, Current Department scope and advanced filters in staging before production.

## 05.00 - 2026-09-07 - Major

**Release:** File Lifecycle, Versioning & Recycle Bin

- Added immutable `media_file_versions` history with explicit `current_version` on every logical asset.
- New uploads automatically create version 1 history; existing assets are backfilled as v1 during migration.
- Added Changed By, Changed Date, original filename, checksum and Change Note per version.
- Added large-file version replacement using 10 MB chunked upload with real transferred-size / total-size + percentage progress.
- Restoring a historical version never overwrites history; it creates a NEW current version referencing the restored source version.
- Added authenticated historical-version download for authorized lifecycle managers.
- Normal delete now uses Laravel Soft Deletes and moves assets to `Settings -> Lifecycle / Recycle Bin` without erasing stored bytes.
- Department Admin can restore Recycle Bin items owned by their department; Super Admin can restore any item.
- Permanent delete is restricted to Super Admin, requires prior Recycle Bin state, deletes all stored version bytes and is audited.
- Added Archive / Unarchive actions while preserving the pre-archive asset status; archived assets remain searchable with explicit Archived status.
- Refined duplicate detection by persisting `duplicate_of_id` and showing a user-facing Potential Duplicate warning on media cards/details.
- Added audit events for version create/restore, archive/unarchive, Recycle Bin move/restore and permanent deletion.
- Added v05.00 lifecycle feature tests covering initial history, soft delete, restore, permanent-delete authorization and archive/unarchive behavior.
- Bumped application version `04.00 -> 05.00`.

### Deployment note

Run the normal Laravel migrations. Existing assets are not moved; each current file path is registered as immutable version 1 history. Recycle Bin auto-purge is intentionally disabled until Management approves the retention period. Perform staging UAT for version upload/restore and permanent deletion before production use.

## 04.00 - 2026-09-07 - Major

**Release:** Metadata & Master Data Foundation

- Added controlled `master_data_values` model/table for Country, State, City, Mandir, Event / Prasang, Guruji / Person, Language and Media Type.
- Added Country -> State -> City -> Mandir hierarchy validation.
- Added aliases, code, active/inactive and safe delete behavior for master values.
- Added Super Admin `Settings -> Metadata & Master Data` UI.
- Added UI-configurable required metadata rules and year range.
- Added Year, location hierarchy, event, person, language, media type, description, internal remarks, source type and asset status to media records.
- Added backend mandatory-metadata enforcement to both normal and resumable chunk upload flows.
- Added metadata fields to media preview/details.
- Added structured metadata to search documents and Meilisearch searchable/filterable settings.
- Added exact search field `year` to the managed exact-field list.
- Added notification-channel configuration foundation for Portal, Email, WhatsApp and Text SMS.
- Added Super Admin `Settings -> Notifications` UI with channel ON/OFF, provider settings, encrypted credential storage and per-event channel matrix.
- Provider delivery workers for Email/WhatsApp/SMS are deliberately deferred to the Notifications module; v04.00 only establishes safe configuration and routing foundations.
- Bumped application version `03.00 -> 04.00`.

### Deployment note

Run migrations, then configure official master lists under `Settings -> Metadata & Master Data`. Existing files keep nullable structured metadata and are not invalidated. New uploads follow the configured mandatory rules. Open `Settings -> Search` and run `Sync Settings to Meilisearch` once so new metadata attributes are applied. Configure notification providers under `Settings -> Notifications`; leave Email/WhatsApp/SMS OFF until credentials/provider delivery are validated in staging.

## 03.00 - 2026-09-07 - Major

**Release:** Core Access Policies & Protected Approval Workflow

- Added Public / Protected / Private access policy fields to media files; existing media defaults to Public during migration.
- Added separate per-file `download_allowed` control.
- Added upload UI access-policy selection with policy guidance.
- Added owner-side per-file policy editing from the file preview/details modal.
- Added centralized `MediaAccessService` so browse/search visibility, thumbnail, preview/streaming, single download and bulk ZIP use the same backend authorization rules.
- Private files are hidden from other departments, including database fallback search and visibility-scoped category/stat counts.
- Protected files show metadata but withhold real thumbnail/preview/download until access is approved.
- Added View Only and View + Download protected-access requests.
- Added owner Department Admin / Super Admin Approve, Reject and More Information actions.
- Added requester resubmission flow after More Information/Reject and download-upgrade requests after prior view-only approval.
- Added in-app portal notifications with unread counter and mark-read/mark-all-read actions.
- Added `media_access_requests`, `portal_notifications`, and append-only application `audit_logs` foundation tables.
- Added audit events for access requests/decisions, media policy changes, authorized previews/downloads and bulk ZIP downloads.
- Added access-policy filter in Browse and access badges/locked-state UI on media cards.
- Added Meilisearch `access_policy` document/filter support so search indexing can respect private visibility.
- Added v03.00 feature tests for protected/private access rules and owner-department approval boundaries.

### Deployment note

Run normal migrations. Then Super Admin should open `Settings -> Search` and run `Sync Settings to Meilisearch` once so the new `access_policy` filterable attribute is applied and existing media is re-indexed. Existing files are migrated as Public to preserve current availability; owners can change policy from the file detail modal.

## 02.01 - 2026-09-06 - Minor

**Release:** UI/UX, Font Management & Advanced Search Foundation

- Bumped application version from `02.00` to `02.01`; System Information continues to show current, previous, release type/date and release name.
- Added Super Admin `Settings -> Appearance -> Fonts` control center.
- Added UI-configurable Global, English, Hindi and Gujarati font assignments.
- Kept Hind Vadodara as the default Global/Gujarati font.
- Added self-hosted Fontsource package definitions for Hind Vadodara, Noto Sans Gujarati, Hind, Noto Sans Devanagari, Inter and Roboto.
- Added secure custom font upload/activate/deactivate/delete support for WOFF2/WOFF/TTF/OTF, including signature validation and live previews.
- Removed runtime Google Fonts and Unsplash dependencies from the application UI.
- Added lazy loading/code splitting for heavy dashboard panels and modals.
- Removed browser-side JSZip bulk packaging; bulk ZIP requests are now server-controlled.
- Routed local media preview/download/thumbnail traffic through authenticated delivery routes, preparing v03.00 access-policy enforcement.
- Moved the Docker media bind mount to a private media disk and explicitly deny legacy `/storage/uploads` web access, while keeping the same host uploads directory.
- Added HTTP Range support for media preview streaming.
- Reworked upload progress to show real transferred size and percent at batch and file level.
- Added resumable 10 MB chunk uploads, server status lookup, retry, cancel/cleanup and user-isolated chunk sessions.
- Increased resumable chunk ceiling to support very large AV files.
- Added background queue worker processing for SHA-256 checksum, duplicate candidate metadata, thumbnails, image dimensions, ffprobe media metadata and search indexing.
- Added database indexes and removed category/subcategory per-row count queries by using eager `withCount`.
- Added Super Admin `Settings -> Search` control center with guides/examples and feature toggles.
- Added Option B Meilisearch and Option E Hybrid (non-AI in this release).
- Added fuzzy/typo-tolerant search, search-as-you-type, filters, best-match behavior, synonyms, cross-language aliases and transliteration indexing.
- Added UI-managed exact-field list for disabling fuzzy matching on strict indexed attributes.
- Added search engine pipeline ON/OFF, global search UI ON/OFF and department-filter ON/OFF controls.
- Added Meilisearch service and persistent index volume to Docker Compose, plus database queue worker.
- Semantic/vector AI search remains deliberately OFF/deferred as approved.

### Deployment note

Run normal migrations. Docker/Coolify must be able to install the new Fontsource npm dependencies and pull the Meilisearch image. After deployment, Super Admin should open `Settings -> Search` and run `Sync Settings to Meilisearch` once to apply settings and re-index existing media.

## 02.00 - 2026-09-06 - Major

**Release:** Security, Roles & Department Foundation

- Established project versioning with baseline `01.00` and current release `02.00`.
- Added `config/version.php` and root `VERSION` source markers.
- Added Admin/Settings **System Information** card showing application version, release type, release date, release name and previous version.
- Added Department data model and database table.
- Added protected `Unassigned` system department for migrated/non-admin users.
- Renamed legacy application roles:
  - `admin` -> `department-admin`
  - `uploader` -> `department-operator`
- Added department assignment to users and Super Admin department management.
- Added department ownership to media files; existing files migrate to the protected `Unassigned` department.
- Department Admin bulk deletion is restricted to files owned by that admin's department.
- Added server-side role middleware to upload, delete, category, role and department mutation routes.
- Restricted role simulation endpoint to Super Admin.
- Prevented self-role changes from the admin console.
- Prevented non-Super Admin users from receiving the complete user directory in dashboard props.
- New registrations default to Viewer and are assigned to the system `Unassigned` department.

### Deployment note

Run the normal Laravel migrations after deploying this release. Existing `admin` and `uploader` users are automatically migrated to the BRD-aligned role names.
