# Karyalay Portal v13.06 - Development Completion Boundary

## Purpose

v13.06 is a client-side navigation stabilization release on top of the completed feature set. It fixes the sidebar blank-screen/navigation-state defect without changing business data architecture.

## Development coding status

All previously completed non-UAT feature items remain implemented. The navigation fix preserves the v13.05 Coolify runtime permission stabilization and the persistent media location.

**Development coding backlog: 0 feature items awaiting implementation.**

## Items intentionally not called "Done" solely by source coding

- Production-scale search/performance benchmark (`NFR-01`).
- Management-approved Audit retention (`AUD-04`), Backup retention (`BKP-05`) and RPO/RTO (`BKP-06`) values where approval is still required.
- P0 staging/UAT cases AC-01 through AC-14.
- Final Go-Live approval.

## Runtime validation boundary

Deploy the exact v13.06 full artifact to Coolify and verify sidebar navigation for Batch Upload, Access Requests, Dashboards & Reports, Integrations & Health and Settings. Confirm the URL changes to the corresponding `panel` query value, browser refresh keeps that module selected, Back/Forward works, and the application shell never disappears into a blank page. Runtime evidence is still required before UAT/Go-Live status can change.
