# Karyalay Portal v13.01 - Go-Live Runbook


## v13.01 deployment-fix precondition

Before treating a staging result as release evidence, confirm the portal footer/System Information reports **13.01**, the deployed source checksum matches the approved v13.01 artifact, and no earlier-release UAT approval is being reused. Feature coding is complete, but Management retention/RPO/RTO/network/2FA/quota policy values must be approved or formally risk-accepted through the readiness workflow before final Go-Live approval.

## 1. Before the deployment window

- Freeze the approved application version and record it under **Settings -> UAT / Go-Live**.
- Resolve or formally risk-accept every Management dependency. **Resolved / Risk Accepted decisions require a written decision/owner/approval-reference note.**
- Confirm deployment owner, rollback owner, business sign-off owner, smoke-test owner and support/escalation contact.
- Confirm backup destination/owner, matching APP_KEY DR custody, retention, RPO/RTO, max upload size and Recycle Bin retention policies.
- Complete all P0 UAT cases and sign off each Passed case **for the exact current release shown by the portal**.
- Create and checksum-verify a fresh backup from the exact current release; confirm a real restore test using a verified current-release backup has passed in staging.
- Run `php artisan readiness:check`. A non-zero exit means blocking failures remain.

## 2. Deployment

1. Put the application into the approved maintenance/traffic-control state if required by the deployment plan.
2. Deploy the exact v13.01 artifact and matching environment configuration.
3. Run migrations: `php artisan migrate --force`.
4. Ensure queue workers are running.
5. Ensure the scheduler calls `php artisan schedule:run` every minute. The v13 scheduler heartbeat must become fresh within five minutes.
6. Run Meilisearch settings sync/re-index if the deployment changed or rebuilt the search service.
7. Run integration health checks against real production mounts/credentials.
8. Run `php artisan readiness:check` again.

## 3. Production smoke test

The recorded smoke-test owner verifies: login, Browse/Search, one representative preview, Protected request/decision, authorized download, notification channel expected for the environment, integration/source open, audit event creation and backup/readiness status. Never use destructive permanent delete against real business data as a smoke test.

## 4. Final release gate

In **Settings -> UAT / Go-Live**, run **Automated Checks** and only then use **APPROVE GO-LIVE**. The server refuses approval if a blocking check, unsigned UAT case, Pending Management dependency, missing owner or change-freeze confirmation remains.

## 5. Rollback trigger

Use the Management-approved rollback window. Examples of rollback triggers: authentication/authorization failure, data-consistency issue, unusable media delivery, widespread integration failure, failed migration with unsafe state, or critical security regression. The rollback owner makes the decision and records the incident/reference.

## 6. Rollback outline

- Stop new writes/traffic as defined by the incident plan.
- Preserve logs and incident evidence.
- Restore the prior application artifact/configuration.
- Restore database only under the approved DB rollback/restore plan; do not casually restore over a populated database.
- Re-run smoke checks and verify audit/integration/backup status.
- Record the final decision and follow-up defects.

## 7. Post go-live

Monitor failed jobs, notification failures, integration health, broken sources, backup/RPO readiness, audit integrity and user-reported issues. Keep the v12 UAT/readiness export with the release evidence package.
