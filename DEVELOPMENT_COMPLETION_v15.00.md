# Karyalay Portal v15.00 - Development Completion Boundary

Release date: 2026-09-10  
Type: Major  
Previous: v14.00

## Delivered coding scope

v15.00 implements the requested Identity, Role and External Internet Access management layer while preserving all completed v14.00 User Guide and persistent-storage work.

- Administrator-friendly form UI replaces raw JSON policy editing in the v14 completion area.
- User creation, role assignment, password-reset email and Super Admin enable/disable controls.
- Department Admin user administration remains strictly department-scoped.
- Built-in role page visibility editing and safe custom-role creation with least-privilege action controls.
- User Groups with optional department scope and groupwise role assignment.
- Per-user External Internet Access: default blocked, optional start/expiry, approval reason and approver, Super Admin management and optional Department Admin delegation.
- Request-time network enforcement distinguishes Internal, VPN and External Internet; external approval expiry is enforced without waiting for a scheduler.
- Login audit records the access source and blocked external attempts.
- Profile UI adds access summary and per-user Dark/Light/System plus Slack/Google-inspired color preferences.
- Password reset workflow is reusable by public Forgot Password and authorized admin reset-email actions.
- Built-in/disabled role-profile hardening prevents accidental privilege expansion.
- Governed page rights now reduce both navigation/direct endpoints and the shared dashboard payload, preventing hidden Access/Integrations/Guide/Settings page data from being preloaded for a role that cannot open that page.
- Department Admin group management is hardened so administrator accounts cannot be changed through group membership or group-role application.
- v15.00 User Guide HTML is bound to the existing governed 22-page rights model.

## Deliberately not marked complete

- Exact Coolify/MariaDB migration runtime evidence.
- SMTP/password-reset provider delivery evidence.
- Real office/VPN/public-IP enforcement evidence.
- Production-scale browser/performance UAT.
- Management approval of real Internal/VPN CIDRs, delegation policy, retention/RPO/RTO/quota values.
- Final Go-Live.

These remain staging/UAT/Management evidence gates, not missing feature code.

## Mandatory post-deploy verification

Confirm `/up`, migrations, queue/scheduler, Users & Roles UI, role/page rights, group assignment, password-reset email, disable/enable, Profile appearance persistence, Network/VPN policy and per-user External Internet Access. Configure real office/VPN CIDRs before enabling Internal/VPN-only enforcement. Re-run the complete release-bound UAT for exact v15.00 before Go-Live approval.
