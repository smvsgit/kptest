# Versioning Policy

Current application version: **14.00**  
Previous application version: **13.06**

- Major module/change or planned feature-completion release: increment the major digits, e.g. `12.01 -> 13.00`.
- After v13.00, verified UAT/staging defects only: increment the last two digits, e.g. `13.00 -> 13.01 -> 13.02`.
- Every delivery updates `VERSION`, `config/version.php`, `CHANGELOG.md`, validation report, ZIP/checksum and master BRD/development tracker.

The source-of-truth application version is `config/version.php` and is mirrored in root `VERSION`. Readiness validates that both values agree.

## Current release

- Current: `14.00`
- Previous: `13.06`
- Type: major
- Release name: Governed User Guide & Persistent Storage Verification
- Development boundary: all active P0/P1 feature coding remains implemented; v14.00 adds governed manual delivery, storage-bind observability, and integrated stabilization fixes. Runtime UAT/Management/Go-Live gates remain open.

## Release lifecycle after v14.00

1. Deploy the exact v14.00 artifact to staging.
2. Execute the complete release-bound UAT matrix and record evidence/sign-off.
3. Resolve Management policy values or formally record approved risk decisions.
4. If a **verified defect** is found, create a minor `14.01+` stabilization release, then re-run affected/current-release UAT.
5. Do not create a new feature version merely to make the tracker 100%; Go-Live completion is an acceptance/operations decision.

Production go-live requires the exact current artifact, verified backup/restore evidence, blocking readiness checks green and all P0 UAT signed off.
