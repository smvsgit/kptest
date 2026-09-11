# Versioning Policy

Current application version: **16.00**  
Previous application version: **15.01**

- Major module/change release: increment the major digits, e.g. `14.00 -> 15.00`.
- Verified v16 staging/deployment defects only: increment the last two digits, e.g. `16.00 -> 16.01 -> 16.02`.
- Every delivery updates `VERSION`, `config/version.php`, `CHANGELOG.md`, validation report, ZIP/checksum and master BRD/development tracker.

The source-of-truth application version is `config/version.php` and is mirrored in root `VERSION`. Readiness validates that both values agree.

## Current release

- Current: `16.00`
- Previous: `15.01`
- Type: major
- Release name: Expanded Account Theme Gallery
- Development boundary: v16.00 is a major UI personalization release that expands saved account themes while preserving v15.01 healthcheck, user/role/network, storage and security architecture. Integrated runtime UAT, real provider/network checks, Management policy values, backup/restore evidence and Go-Live remain open gates.

## Release lifecycle after v16.00

1. Deploy the exact v16.00 full merge-ready artifact to staging.
2. Configure approved office/VPN CIDRs before enabling Internal/VPN-only network enforcement.
3. Execute the complete release-bound UAT matrix, including Users & Roles, page rights, User Groups, password-reset email, user lifecycle, Profile theme/password and Internal/VPN/External Internet cases.
4. Resolve Management policy values or formally record approved risk decisions.
5. If a **verified defect** is found, create a minor `16.01+` stabilization release and re-run affected/current-release UAT.
6. Do not mark Go-Live complete merely because feature coding/static validation is complete.

Production go-live requires the exact current artifact, verified backup/restore evidence, blocking readiness checks green and all P0 UAT signed off.


## v16.00 Theme Gallery

Profile now provides 16 saved Light/Dark account theme combinations across 8 color families. The saved preset follows the user account; the header Sun/Moon button remains a temporary viewing-mode override.
