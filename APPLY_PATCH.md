# Karyalay Portal v13.04 - Coolify Vite Lucide Import Fix

The deployment log confirms the npm-install issue is fixed. The current fatal failure is now the Vite build:

```text
[MISSING_EXPORT] "Youtube" is not exported by lucide-react
resources/js/Components/panels/IntegrationsPanel.tsx:2
```

## Important

The prior v13.03 patch package was merged to GitHub, but the deployment log still shows the **old source line**, so the actual `IntegrationsPanel.tsx` source file was never patched.

Do **not** simply upload/merge this hotfix folder as files. Apply the patch to the repository source.

## Automatic fix

Extract this hotfix. From your repository root run:

```bash
python3 /path/to/Karyalay_Portal_v13.04_Coolify_Vite_Import_Fix/APPLY_PATCH.py .
```

or copy `APPLY_PATCH.py` to the repository root and run:

```bash
python3 APPLY_PATCH.py .
```

The script validates that the bad import is gone before reporting success.

Then:

```bash
git add resources/js/Components/panels/IntegrationsPanel.tsx VERSION config/version.php
git commit -m "Fix lucide Youtube import for Coolify build - v13.04"
git push origin main
```

In Coolify use Force Redeploy / rebuild without cache.

## Exact source change

Old:

```tsx
import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Youtube } from "lucide-react";
```

New:

```tsx
import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Play as Youtube } from "lucide-react";
```

The `@theme` LightningCSS message in the same log is a warning; it is not the fatal build error.
