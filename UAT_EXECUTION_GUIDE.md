# Karyalay Portal v13.00 - UAT Execution Guide

This guide is the execution companion for **Settings -> UAT / Go-Live**.
> **v13.00 release-binding rule:** every saved execution records the application release. Sign-off is accepted only when the execution and approval are both for the currently running release. If another stabilization build is deployed, re-run/save the relevant P0 cases before signing off that release.

A case is not complete merely because code exists. Execute it in the staging environment, record evidence, save the result, then sign off the Passed case.

## Required staging roles

Use separate test accounts for Super Admin, Department Admin, Department Operator and Viewer. Use at least two departments so Private visibility and cross-department controls are genuinely tested.

## P0 UAT matrix

1. **AC-01 - Secure login + role/department visibility**: valid/invalid login, Disabled/Inactive users, role permissions, department scoping, session controls.
2. **AC-02 - Public / Protected / Private**: Public access, Protected metadata-lock behavior, Private cross-department invisibility, direct-route authorization.
3. **AC-03 - Protected request workflow**: request reason/level, owner-admin decision, More Info/re-submit, View Only vs View+Download, notifications and audit.
4. **AC-04/05 - Integrations**: real NAS mount, Google Drive credentials, YouTube source, Missing/Broken detection, repair/retry and owner/admin alerts.
5. **AC-06/07 - Metadata/upload/search**: mandatory metadata, direct + resumable large upload, bulk edit, filters, Option B and Option E search, aliases/transliteration.
6. **AC-08 - Preview/streaming**: image/PDF/audio/video preview, HTTP Range seeking, protected streaming and downloads.
7. **AC-09 - Versioning/Recycle Bin**: replacement version, restore-as-new-version, archive, soft delete, restore, Super Admin permanent delete.
8. **AC-10/11 - Audit**: expected events, Who/What/When/IP/session/source, hash-chain verification and DB-trigger tamper controls.
9. **AC-12 - Backup/restore**: create+verify backup, restore to a fresh staging database with matching APP_KEY, record duration/results and smoke-test restored portal.
10. **AC-13 - Storage/integration health**: capacity, stale/broken sources, scheduled health checks and operational alerts.
11. **AC-14 - Non-technical usability**: representative users complete search/view/request/upload tasks without developer assistance and provide sign-off.


## v13.00 additional regression focus

During the same consolidated UAT cycle, explicitly include these feature-completion regressions inside the relevant AC cases:

- Maintenance Mode normal-user block + Super Admin bypass.
- Forgot/reset password single-use token, expiry and Disabled/Inactive-account rejection.
- TOTP 2FA enrolment/challenge/recovery and configured mandatory-role policy.
- Network/IP/CIDR/VPN login enforcement, trusted VPN proxy behavior and emergency Super Admin recovery path.
- Folder/Collection/Related Asset management, hierarchy constraints and department scoping.
- Type-specific metadata requirements before Approved/Published lifecycle transitions.
- Watermark policy, thumbnail/fallback behavior and access-controlled preview/download.
- Native XLSX user/report import-export and malformed/oversized workbook rejection.
- Scheduled report scope revalidation after recipient role/status/department changes.
- Audit Retention / Recycle Bin Retention no-op at `0`, plus approved non-zero policy execution in isolated staging data.
- Department quota/warning behavior using active + Recycle Bin + historical-version bytes.
- Gujarati/English preference, branding, responsive critical flows, keyboard focus and Help/FAQ navigation.

## Evidence standard

For every case record at least one of: test-run/ticket ID, screenshot/file reference, signed checklist reference, or sufficiently detailed execution notes. A Passed case cannot be signed off without evidence/notes. Changing a signed result resets its approval.

## Defect handling

- **Failed**: observed behavior violates acceptance criteria; create a defect and record its reference.
- **Blocked**: test cannot execute because environment/data/credential/dependency is unavailable.
- **WIP**: execution has started but evidence or retest is incomplete.
- **Passed**: acceptance criteria met in staging; then use **Sign Off**.

Do not use the final Go-Live approval until every P0 UAT case is Passed + signed off and automated blocking checks are green.
