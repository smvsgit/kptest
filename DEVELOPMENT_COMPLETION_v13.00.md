# Karyalay Portal v13.00 - Development Completion Boundary

## Purpose

This file distinguishes **source-code feature completion** from **runtime acceptance**. The project owner requested that all remaining coding be completed first and that one integrated testing/UAT cycle be executed afterward.

## Development coding status

All remaining non-UAT feature items in the active P0/P1 development checklist have implementation in v13.00. This includes the final Maintenance, security/network/2FA, content organization, metadata/lifecycle, watermark, reporting/XLSX/scheduling, quota/retention, localization/help/branding and operational-policy controls.

**Development coding backlog: 0 feature items awaiting implementation.**

## Items intentionally not called "Done" solely by source coding

The BRD tracker can remain below 100% even though coding is complete because the following require evidence or decisions outside source implementation:

- Production-scale search/performance benchmark (`NFR-01`).
- Management-approved Audit retention (`AUD-04`), Backup retention (`BKP-05`) and RPO/RTO (`BKP-06`) values. The engines/settings exist; `0` remains Policy Pending.
- P0 staging/UAT cases AC-01 through AC-14.
- Final Go-Live approval.

## Testing policy for this project

No UAT case is pre-passed. Deploy the **exact v13.00 artifact** to staging after development completion, run all cases in `UAT_EXECUTION_GUIDE.md`, record evidence, then create `13.01+` only for verified defects.

## Runtime limitation of this build workspace

This workspace does not provide Composer `vendor/`, Node `node_modules/`, Docker/MariaDB or production credentials/mounts. Static source validation is possible here; Laravel/PHPUnit, migrations, Vite build, queues/scheduler, providers, NAS/Drive/YouTube and browser UAT require staging.
