# Karyalay Portal v14.00 - Development Completion Boundary

## Purpose

v14.00 is the major post-deployment governance/stabilization release built on the v13.06 full-source baseline. It completes the pending aggressive connection-audit repairs, adds the governed User Guide Manual requested for deployment, and removes ambiguity between the Docker `app-storage` volume and the authoritative `/srv/media` uploaded-media bind.

## Development coding status

All active P0/P1 application coding remains implemented. v14.00 adds or completes:

- User Guide Manual sidebar module with 18 logical pages and deployable HTML/Word source.
- Server-enforced User Guide page entitlement with Super Admin role/department/user controls.
- 2FA enforcement/enrollment/challenge connection fixes.
- Branding connection fixes across auth/application flows.
- Governed lifecycle transition and status-history connection fixes.
- Scheduled Reports UI/API and scheduler-policy connection fixes.
- Persistent Local Storage capacity/readiness checks on the actual nested uploads bind.
- Exact host/container/probe path visibility in Integrations & Health.
- Required `/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads` bind preserved for app, worker, and scheduler.
- Final BRD v3.5 and User Guide Manual v14.00 bundled in `docs/`.

**Development coding backlog: 0 active P0/P1 feature items awaiting implementation.**

## Items intentionally not called Done solely by source coding

- Production-volume search/performance benchmark (`NFR-01`).
- Management-approved Audit retention (`AUD-04`), Backup retention (`BKP-05`), RPO/RTO (`BKP-06`), production network/VPN/2FA/quota/master-data values and other decision-owned policy values.
- P0 staging/UAT cases AC-01 through AC-14, including v14.00 User Guide/storage persistence regression evidence.
- Real fresh-DB backup/restore evidence for the exact release.
- Final Go-Live approval.

## Runtime validation boundary

After deploying the exact v14.00 full artifact to Coolify, verify: application health `/up`; migrations; queue/scheduler; all URL-backed sidebar panels; User Guide 0/partial/full entitlement behavior; the Local Storage health card reports host `/srv/media/projects/karyalayportal/uploads`, container/probe `/var/www/html/storage/app/media/uploads`; a representative upload is previewable/downloadable; and the same uploaded asset remains available after redeploy. Then execute the complete release-bound UAT and backup/restore/readiness workflow before changing UAT/Go-Live status.
