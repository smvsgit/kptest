# Karyalay Portal v13.02 - Coolify npm Lock Sync Hotfix

## Root cause
Coolify's `node:22-alpine` build is using npm 10.9.8. The current lock file is rejected by `npm ci` as out of sync because optional WASM dependency metadata references `@emnapi/core@1.10.0` and `@emnapi/runtime@1.10.0` without matching lock entries.

The previous `.npmrc`-only hotfix is not sufficient on this npm version. The deployment log still executes `npm ci` and fails before Vite starts.

## Files in this hotfix
Copy these files into the **root of your existing v13.01 Git repository**, preserving all other application files:

- `Dockerfile` -> replace repository-root `Dockerfile`
- `.npmrc` -> repository-root `.npmrc`
- `VERSION` -> repository-root `VERSION`
- `config/version.php` -> replace `config/version.php`

Do **not** replace your application with an older package. This ZIP is a deployment patch only.

## Important Dockerfile change
Old:

```dockerfile
RUN npm ci --no-audit --no-fund --ignore-scripts
```

New:

```dockerfile
RUN npm install --no-audit --no-fund --ignore-scripts --legacy-peer-deps
```

`npm install` is used here because npm itself reports that `package.json` and `package-lock.json` are out of sync and instructs updating the lock with `npm install`. It reconciles the lock metadata inside the build container and installs the resolved graph. `--legacy-peer-deps` is explicit so Coolify cannot ignore the intended peer-dependency behavior.

## Persistent media storage
This hotfix does not change `docker-compose.yml`. Keep your existing bind mount for app, worker and scheduler:

```text
/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads
```

## Deploy
1. Apply the four files above to the existing v13.01 repository.
2. Commit and push to `main`.
3. In Coolify, use **Redeploy / Force Deploy**. If a no-cache option is available, use it once.
4. In the new build log, confirm the assets stage now shows:

```text
RUN npm install --no-audit --no-fund --ignore-scripts --legacy-peer-deps
```

If the log still shows `RUN npm ci ...`, Coolify is building an older Git commit or the Dockerfile patch was not pushed to `main`.
