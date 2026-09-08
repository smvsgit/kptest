# Karyalay Portal

SMVS centralized intranet media and document portal.

## Current release

- Version: `13.00`
- Release: Remaining Feature Completion
- Previous: `12.01`
- Version is visible in `Settings -> System -> System Information`.
- **Development coding status:** remaining non-UAT feature backlog implemented; staging UAT/Management policy gates remain intentionally open.

## Application stack

- Laravel 13 / PHP 8.4 production image
- Inertia v3
- React 19 + TypeScript
- Vite 8
- MariaDB 11.8
- Meilisearch 1.53.1
- Docker Compose / Coolify
- Database-backed Laravel queue worker

## Administration retained from v02.01

### Fonts

Open `Settings -> Appearance -> Fonts` as Super Admin.

Defaults:

- Global: Hind Vadodara
- Gujarati: Hind Vadodara
- Hindi: Noto Sans Devanagari
- English: Inter

Bundled through self-hosted Fontsource packages:

- Hind Vadodara
- Noto Sans Gujarati
- Hind
- Noto Sans Devanagari
- Inter
- Roboto

Super Admin can assign each language from the UI, upload custom WOFF2/WOFF/TTF/OTF files, activate/deactivate font families and restore the recommended defaults. Runtime Google Fonts are not used.

### Search

Open `Settings -> Search` as Super Admin.

Available engine modes:

- Option B: Meilisearch
- Option E: Hybrid, currently non-AI (exact/prefix priority plus Meilisearch fuzzy, synonyms, aliases and transliteration)

Central Admin controls the search features from the UI. The page includes examples and guides for fuzzy search, search-as-you-type, synonyms, aliases, transliteration, filters and exact fields where fuzzy matching must be disabled.

Semantic/vector AI search remains intentionally deferred and OFF in v11.00.

After first deployment/redeploy, open `Settings -> Search` and use `Sync Settings to Meilisearch` once after migrations so existing media documents receive the new `access_policy` search attribute.





## v13.00 Remaining Feature Completion

v13.00 completes the remaining non-testing feature implementation before the project moves to one consolidated staging/UAT cycle. Highlights:

- Maintenance Mode, forgot/reset password, TOTP 2FA, IP/CIDR/VPN policy and branded login/application settings.
- Folder hierarchy, Collections, Related Assets and controlled lifecycle transitions.
- Type-specific required metadata and governed-state validation.
- Thumbnail/fallback presentation and configurable protected watermark behavior.
- Native XLSX user/report import-export and weekly/monthly Scheduled Reports.
- Audit Retention and Recycle Bin Retention engines; both remain non-destructive while their approved retention values are `0 / Policy Pending`.
- Department storage quota / warning controls using physical stored-byte accounting.
- Gujarati + English localization foundation, Help/FAQ, responsive and accessibility source improvements.
- Remaining Admin policy controls exposed in UI rather than requiring code changes.
- Security hardening for network policy at login, password-reset account lifecycle, branding asset serving, spreadsheet limits and scheduled-report runtime recipient revalidation.

### Important release boundary

Do not interpret “development coding complete” as “production accepted.” UAT cases remain release-bound and must be executed against the exact v13.00 artifact. Production-scale performance, real NAS/Drive/YouTube, providers, backup/restore, browser flows and Management policy values are staging/go-live gates.

## v12.01 Pre-Go-Live Stabilization

- UAT results/sign-offs are bound to the current application release; prior-version sign-offs are treated as stale.
- Readiness snapshots and final go-live review evidence capture the release version.
- Backup readiness requires a currently Passed checksum verification, not only a `success` backup row.
- Passed restore-test evidence requires a verified backup and the go-live gate requires restore evidence from a backup created by the current application release.
- Backup owner/retention/RPO/RTO controls are cross-checked against Management decisions.
- Resolved/Risk Accepted Management dependencies require a written decision/approval note.
- UAT Sign Off is disabled while edits are unsaved.
- No UAT case is automatically Passed by this release; run staging UAT using `UAT_EXECUTION_GUIDE.md`.

## v12.00 UAT & Go-Live Readiness

Super Admin now has `Settings -> UAT / Go-Live` for P0 acceptance execution/evidence/sign-off, automated environment readiness checks, Management dependency status, deployment/rollback/business/smoke/support ownership and the final server-enforced Go-Live review. Use `php artisan readiness:check` in staging/production deployment pipelines. The scheduler records a readiness heartbeat every minute.

Real UAT results are intentionally **not pre-passed**. Deploy to staging, execute `UAT_EXECUTION_GUIDE.md`, then follow `GO_LIVE_RUNBOOK.md`.

## v11.00 Backup, Restore & Disaster Recovery

- Super Admin configures encrypted portal backup/DR controls under `Settings -> Backup & DR`.
- Application-native `.kpbackup` archives protect portal DB/metadata, System Settings/configuration and audit/security records.
- Backup files are encrypted with the portal APP_KEY and include an inner payload checksum plus file SHA-256 verification. The APP_KEY is deliberately not embedded; matching APP_KEY custody is an external DR responsibility.
- Backup destination can be protected local storage or an existing writable absolute filesystem/NAS directory outside the public web root.
- Daily/weekly/monthly schedules and retention windows are UI-configurable. All schedules are OFF by default; retention `0` means Policy Pending / no automatic pruning.
- Every successful backup is automatically read back, decrypted and integrity/table-count verified. Manual verification and encrypted download are also available to Super Admin.
- Restore is intentionally console-only and supports a fresh migrated target database: `php artisan backup:restore-file <path> --confirm=RESTORE-EMPTY-DATABASE`. Production additionally requires maintenance mode and `--force-production`.
- Restore test verification records capture Passed/Failed, target environment, duration and evidence notes. RPO/RTO readiness uses the latest successful backup and latest passed restore-test duration.
- RPO/RTO values default to `0` (Policy Pending); no business target is invented until Management/IT approves it.
- NAS / Google Drive / YouTube source-content bytes are explicitly outside the portal metadata backup and remain the responsibility of their source-system owner. Local/media file bytes also require the approved storage-level backup plan.
- Scheduled backup/RPO monitoring runs through the Laravel scheduler (`backup:scheduled`). RPO warning and backup-failure events reuse the existing Portal/Email/WhatsApp/Text SMS notification matrix.

## v10.00 Integrations & Storage Health

- `Integrations & Health` provides Super Admin connection configuration and Super/Department Admin health visibility.
- Supported source types: Portal Local storage, NAS/intranet paths, Google Drive references and YouTube references.
- Existing physical portal files are migrated into primary source records; one logical asset can now have multiple sources.
- Reference-only assets can catalog NAS/Drive/YouTube content without copying source bytes into portal storage. Mandatory metadata settings still apply.
- Source health states are Active / Inactive / Missing / Broken with Last Check, Last Success and Broken Detected time.
- Per-source Retry Check, Disable/Enable, Replace/Repair and source removal are available from File Details to authorized lifecycle managers.
- Local/NAS storage reports capacity/used/free; each connection has a warning percentage and configurable health/sync frequency.
- Google Drive credentials (API key/access token) are encrypted at rest and not returned to the browser. Public Drive references can also be checked without stored credentials.
- YouTube embeds use normalized video IDs and availability is checked through public oEmbed metadata.
- A 15-minute scheduler scans only connections/sources whose configured freshness interval is due.
- New Broken/Missing sources and storage/integration warnings use the existing notification event matrix and user preferences.
- After deployment run `Settings -> Search -> Sync Settings to Meilisearch` once so alternate source types/statuses are indexed.

## v09.00 Organization, User Lifecycle & Access Maturity

- Users have audited Active / Disabled / Inactive lifecycle states. Non-active accounts cannot login and their sessions/access are revoked.
- Department -> Sub-department -> Team hierarchy is managed from Access Control.
- Operational asset ownership is separate from uploader attribution; lifecycle/department transfers require a same-department successor when the user owns assets.
- Custom Permission Sets can reduce base-role privileges without elevating beyond the role.
- Access Control includes access-review visibility and CSV export/import. Existing-user department/status changes must use the controlled UI rather than CSV.
- Protected approvals can be temporary; expired access is automatically revoked by the scheduler. Default expiry is 0 until Management approves a formal policy.
- Protected downloads can use short-lived signed links and are revalidated at delivery time.
- Bulk protected access requests support Event/Prasang, Category and Sub-category scopes.
- Department Admin can delegate approval authority for a configured start/end period to an active same-department user.
- Super Admin can configure failed-login threshold, lockout minutes, inactivity session timeout, password minimum, signed-link lifetime and bulk-access maximum under `Settings -> Security & Access`.
- Profile exposes active sessions with logout controls; Super Admin can terminate all sessions for another user.

## v08.00 Notifications, Audit & Reports

- Access request/decision/escalation notifications use the Central Admin event matrix and user preferences.
- Portal delivery is immediate; Email/WhatsApp/Text SMS are queued and tracked in `notification_deliveries`.
- SMTP Email, WhatsApp HTTP/Meta-style and generic HTTP SMS adapters are implemented; provider credentials are encrypted in stored settings.
- Header Bell -> `View all` opens Notification Center with read/unread/category filters and user preferences.
- `Settings -> Notifications` includes live provider test queueing and pending-request escalation rules.
- Docker Compose includes a `scheduler` service; queue worker + scheduler are both required for full v08 operation.
- `Dashboards & Reports` provides role metrics, Access/Download and Activity/Security reports, CSV exports, Print/Save PDF and Super Admin delivery/audit-integrity views.
- Audit records are append-only, hash-chained and include IP/session/source context. MariaDB/MySQL production receives UPDATE/DELETE prevention triggers.
- Audit retention remains Management Decision Pending; automated purge is not enabled.

## v07.00 Upload, Bulk Operations & Integrity

- Super Admin configures allowed extensions, file-size/batch limits, chunk threshold/size/retry settings and duplicate controls under `Settings -> Upload & Integrity`.
- Direct, chunked and replacement-version uploads share the same server-side upload policy.
- Upload preflight reports blocked files before transfer and may show visibility-safe possible duplicate warnings.
- Upload UI continues to show actual progress such as `3.26 MB / 53.50 MB - 6%`.
- Browse multi-select now exposes Bulk Edit for metadata, tags, move, archive/unarchive and access policy changes.
- Department-scoped authorization is enforced by Laravel; Super Admin alone can bulk transfer owner department.
- SHA-256 exact duplicate detection is configurable and remains non-destructive.
- `0` for max file size or batch count means no application-level limit; infrastructure limits can still apply.

## v06.00 Search & Discovery

Browse Files now includes Search Everywhere vs Current Department scope, advanced metadata filters, Saved Searches, Favorites and Recently Viewed. Filters include file type/date/access policy/department/year/location/Event/Person/language/media type/uploader/source/status. Both Option B Meilisearch and Option E Hybrid remain selectable under `Settings -> Search`; Semantic/AI remains OFF. After deployment run `Settings -> Search -> Sync Settings to Meilisearch` once.

## v05.00 file lifecycle and versioning

- Every logical asset has immutable version history and an explicit current version.
- New versions require a Change Note and capture Changed By / Changed Date.
- Large replacement versions use 10 MB chunks and show actual progress such as `3.26 MB / 53.50 MB - 6%`.
- Restoring an older version creates a new current version instead of overwriting history.
- Normal Delete moves the asset to `Settings -> Lifecycle / Recycle Bin`; file bytes and historical versions remain recoverable.
- Department Admin can restore own-department items. Permanent delete is Super Admin-only and audited.
- Archive / Unarchive is available to authorized admins; archived assets remain searchable.
- SHA-256 duplicate detection now shows a user-facing Potential Duplicate warning.
- Automatic Recycle Bin purge is OFF until Management approves the retention period.

## v04.00 metadata and master data

- Added Super Admin `Settings -> Metadata & Master Data` control center.
- Controlled masters: Country, State, City, Mandir, Event / Prasang, Guruji / Person, Language and Media Type.
- Country -> State -> City -> Mandir supports parent hierarchy and active/inactive values.
- Master values support codes and aliases to improve data consistency and search.
- Central Admin decides which metadata fields are mandatory for uploads from the UI.
- Upload supports Year, Country, State, City, Mandir, Event, Guruji / Person, Language, Media Type, Description, Internal Remarks, Source and Asset Status.
- Both simple and resumable chunk uploads enforce the configured mandatory metadata rules on the backend.
- Media preview/details now displays the new metadata.
- Search documents now include the new structured metadata and Meilisearch configuration exposes them as searchable/filterable attributes for later full Search & Discovery completion.

## Notification channel configuration foundation

A new Super Admin `Settings -> Notifications` page provides UI-managed configuration for:

- Portal / in-app notifications
- Email (SMTP foundation)
- WhatsApp (Meta Cloud API or generic HTTP provider foundation)
- Text SMS (generic provider foundation)
- Per-event delivery matrix, so Central Admin decides which channels are ON/OFF for each event.

Secrets such as SMTP passwords, WhatsApp tokens and SMS API keys are encrypted before storage and are not returned to the browser after save. v04.00 establishes the configuration/data foundation; actual provider delivery workers remain scheduled for the Notifications module.

## v03.00 access workflow

- Uploaders choose `Public`, `Protected`, or `Private` for every new file and can separately allow/disable downloads.
- Public means registered internal users; it does not mean public Internet exposure.
- Protected files expose basic metadata but lock thumbnail/preview/download until approved.
- Private files are hidden from users outside the owner department; Super Admin remains system-wide.
- Users can request `View Only` or `View + Download`.
- Owner Department Admin or Super Admin can Approve, Reject, or request More Information.
- View-only approval does not grant download. A later download-upgrade request is supported when downloads are allowed.
- In-app notifications alert approvers and requesters.
- Request/decision, preview, download, bulk download and access-policy changes are written to the new audit log foundation.
- The Access Requests panel is available to users for request tracking; Department Admin/Super Admin receive review controls.

## Media processing

New local uploads are processed by the queue worker for:

- SHA-256 checksum
- duplicate candidate metadata
- image/video thumbnails where supported
- image dimensions
- ffprobe media metadata for video/audio
- search indexing

Large uploads use resumable 10 MB chunks and display real transferred bytes plus percentage, for example `3.26 MB / 53.50 MB - 6%`.

## Secure media delivery and access enforcement

Local preview/download URLs go through authenticated Laravel routes rather than direct `/storage/...` URLs. v03.00 now enforces Public / Protected / Private visibility, protected approval, view-vs-download entitlement and owner-department rules across browse/search, thumbnail, preview/streaming, single download and bulk ZIP.

Bulk ZIP is created server-side instead of in browser memory.

## Deployment

See `DEPLOY.md` and `FRESH_INSTALL.md`.
