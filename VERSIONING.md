# Versioning Policy

Current application version: **17.04**  
Previous application version: **17.03**

- Major module/change release: increment the major digits, e.g. `14.00 -> 15.00`.
- Verified v17 staging/deployment defects only: increment the last two digits, e.g. `17.00 -> 17.01 -> 17.02`.
- Every delivery updates `VERSION`, `config/version.php`, `CHANGELOG.md`, validation report, ZIP/checksum and master BRD/development tracker.

The source-of-truth application version is `config/version.php` and is mirrored in root `VERSION`. Readiness validates that both values agree.

## Current release

- Current: `17.04`
- Previous: `17.03`
- Type: minor
- Release name: Image Preview Rework
- Development boundary: v17.00 closes 20 findings from the v16.00 UAT/Go-Live register, executes the 14 rows that had never been run, and raises the automated suite from 98 to 188 passing tests. It preserves the v16.00 theme gallery and the v15.01 healthcheck, user/role/network, storage and security architecture. Gujarati interface translation, provider configuration (email/WhatsApp/SMS), backup/DR evidence on a real host and Go-Live sign-off remain open gates.

## Release lifecycle after v17.00

1. Deploy the exact v17.00 full merge-ready artifact to staging.
2. Configure approved office/VPN CIDRs before enabling Internal/VPN-only network enforcement.
3. Execute the complete release-bound UAT matrix, including Users & Roles, page rights, User Groups, password-reset email, user lifecycle, Profile theme/password and Internal/VPN/External Internet cases.
4. Resolve Management policy values or formally record approved risk decisions.
5. If a **verified defect** is found, create a minor `17.01+` stabilization release and re-run affected/current-release UAT.
6. Do not mark Go-Live complete merely because feature coding/static validation is complete.

Production go-live requires the exact current artifact, verified backup/restore evidence, blocking readiness checks green and all P0 UAT signed off.


## Branching

Every version bump is developed on its own branch named after the version (`17.00`, `17.01`, `18.00`), pushed to `github.com/smvsgit/kptest`, and merged into `main`.

## v17.00 UAT Remediation

Closes 20 UAT findings including the JSON-redirect defect that let failed actions report success, BR-02 protected-content enforcement, per-department storage quotas, per-type master data forms and the category/sub-category workflow. See `CHANGELOG.md` for the full list and `TEST_REPORT_v17.00.html` for executed evidence.

## v16.00 Theme Gallery

Profile provides 16 saved Light/Dark account theme combinations across 8 color families. The saved preset follows the user account; the header Sun/Moon button remains a temporary viewing-mode override.
