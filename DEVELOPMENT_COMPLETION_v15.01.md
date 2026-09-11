# Karyalay Portal v15.01 - Coolify Healthcheck Detection Hardening

Release date: 2026-09-10  
Type: Minor deployment stabilization  
Previous: v15.00

## Root cause / observed symptom

Coolify displayed **Running (no healthcheck) / Healthcheck Not configured** even though the v15.00 Compose `app` service already contained an HTTP healthcheck for Laravel `/up`.

Because the project may be interpreted by Coolify either as a Docker Compose application/service stack or through Dockerfile healthcheck detection, relying on only one declaration was not robust enough for the platform UI/detection path.

## Fix

- Kept the Compose `app.healthcheck` and hardened it to `http://127.0.0.1/up` with an explicit curl max-time.
- Added a container-native Dockerfile `HEALTHCHECK` using the same Laravel `/up` endpoint. This allows Coolify/Docker to detect the healthcheck even when Dockerfile metadata is the active detection path.
- Kept `curl` installed in the final PHP/Apache image, so the health command is available inside the running container.
- Marked `worker`, `scheduler`, and `meilisearch` with Coolify `exclude_from_hc: true` so non-public companion services without the web readiness endpoint do not make aggregate application health ambiguous. MariaDB retains its own native healthcheck.
- Preserved the required persistent media bind on `app`, `worker`, and `scheduler` unchanged.

## Runtime acceptance still required

After Coolify redeploys v15.01, confirm the resource/container changes from **Running (no healthcheck)** to a health-aware state and that `curl -fsS http://127.0.0.1/up` returns success inside the app container. This runtime evidence is not available in the build environment and does not mark UAT/Go-Live complete.
