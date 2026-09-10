# Versioning Policy

Current application version: **15.00**  
Previous application version: **14.00**

- Major module/change release: increment the major digits, e.g. `14.00 -> 15.00`.
- Verified v15 staging/deployment defects only: increment the last two digits, e.g. `15.00 -> 15.01 -> 15.02`.
- Every delivery updates `VERSION`, `config/version.php`, `CHANGELOG.md`, validation report, ZIP/checksum and master BRD/development tracker.

The source-of-truth application version is `config/version.php` and is mirrored in root `VERSION`. Readiness validates that both values agree.

## Current release

- Current: `15.00`
- Previous: `14.00`
- Type: major
- Release name: Identity, Role & External Internet Access Management
- Development boundary: requested v15 identity/role/network/UI coding is implemented while integrated runtime UAT, production-scale performance, real provider/integration checks, Management policy values, backup/restore evidence and Go-Live remain open gates.

## Release lifecycle after v15.00

1. Deploy the exact v15.00 full merge-ready artifact to staging.
2. Configure approved office/VPN CIDRs before enabling Internal/VPN-only network enforcement.
3. Execute the complete release-bound UAT matrix, including Users & Roles, page rights, User Groups, password-reset email, user lifecycle, Profile theme/password and Internal/VPN/External Internet cases.
4. Resolve Management policy values or formally record approved risk decisions.
5. If a **verified defect** is found, create a minor `15.01+` stabilization release and re-run affected/current-release UAT.
6. Do not mark Go-Live complete merely because feature coding/static validation is complete.

Production go-live requires the exact current artifact, verified backup/restore evidence, blocking readiness checks green and all P0 UAT signed off.
