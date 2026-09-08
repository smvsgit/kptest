# Karyalay Portal v13.03 - Coolify Vite Icon Build Hotfix

## Error fixed

The v13.02 dependency-install fix worked: Coolify successfully completed `npm install` and reached `npm run build`.

The new failing build error is:

```text
[MISSING_EXPORT] "Youtube" is not exported by node_modules/lucide-react/dist/esm/lucide-react.mjs
resources/js/Components/panels/IntegrationsPanel.tsx:2
```

## Fix

Replace this import:

```tsx
import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Youtube } from "lucide-react";
```

with:

```tsx
import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Play as Youtube } from "lucide-react";
```

This preserves all existing `<Youtube />` JSX usages while using `Play`, which is a stable lucide-react export.

## Recommended automatic application

From the repository root, copy `APPLY_PATCH.py` there and run:

```bash
python3 APPLY_PATCH.py .
```

Then commit/push:

```bash
git add -A
git commit -m "Fix Coolify Vite lucide icon build - v13.03"
git push origin main
```

In Coolify use **Redeploy / Force rebuild without cache**.

## Expected build progression

You should now see both of these steps pass:

```text
RUN npm install --no-audit --no-fund --ignore-scripts --legacy-peer-deps
RUN npm run build
```

The `lightningcss minify Unknown at rule: @theme` line shown before the failure is a warning in this log; the fatal error is the missing `Youtube` export.

## Persistent media storage

Do not change the existing media bind mount:

```text
/srv/media/projects/karyalayportal/uploads:/var/www/html/storage/app/media/uploads
```

Keep the same mount for `app`, `worker`, and `scheduler`.
