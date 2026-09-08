# Versioning Policy

Current application version: **13.00**  
Previous application version: **12.01**

- Major module/change or planned feature-completion release: increment the major digits, e.g. `12.01 -> 13.00`.
- After v13.00, verified UAT/staging defects only: increment the last two digits, e.g. `13.00 -> 13.01 -> 13.02`.
- Every delivery updates `VERSION`, `config/version.php`, `CHANGELOG.md`, validation report, ZIP/checksum and master BRD/development tracker.

The source-of-truth application version is `config/version.php` and is mirrored in root `VERSION`. Readiness validates that both values agree.

## Current release

- Current: `13.00`
- Previous: `12.01`
- Type: major
- Release name: Remaining Feature Completion
- Development boundary: all remaining non-UAT feature coding in the active P0/P1 checklist is implemented.

## Release lifecycle after v13.00

1. Deploy the exact v13.01 artifact to staging.
2. Execute the complete release-bound UAT matrix and record evidence/sign-off.
3. Resolve Management policy values or formally record approved risk decisions.
4. If a **verified defect** is found, fix only that defect in `13.01`, then re-run affected/current-release UAT.
5. Do not create a new feature version merely to make the tracker 100%; Go-Live completion is an acceptance/operations decision.

Production go-live requires the exact current artifact, verified backup/restore evidence, blocking readiness checks green and all P0 UAT signed off.
