from pathlib import Path
import re, sys

root = Path(sys.argv[1] if len(sys.argv) > 1 else '.').resolve()
target = root / 'resources/js/Components/panels/IntegrationsPanel.tsx'
if not target.exists():
    raise SystemExit(f'ERROR: {target} not found. Run from the Karyalay Portal repository root.')

s = target.read_text(encoding='utf-8')
original = s

# Exact v13.04 fix for the Coolify/Vite build error.
# lucide-react in the resolved npm tree does not export `Youtube`.
# Alias stable `Play` to `Youtube` so existing <Youtube /> JSX remains unchanged.
patterns = [
    ('import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Youtube } from "lucide-react";',
     'import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Play as Youtube } from "lucide-react";'),
    ("import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Youtube } from 'lucide-react';",
     "import { Activity, Database, HardDrive, Link2, RefreshCw, Save, Trash2, Play as Youtube } from 'lucide-react';"),
]
for old, new in patterns:
    if old in s:
        s = s.replace(old, new, 1)
        break
else:
    # Generic fallback for harmless formatting differences.
    if re.search(r'import\s*\{[^}]*\bYoutube\b[^}]*\}\s*from\s*[\'\"]lucide-react[\'\"]\s*;', s):
        s = re.sub(
            r'(import\s*\{[^}]*?)\bYoutube\b([^}]*\}\s*from\s*[\'\"]lucide-react[\'\"]\s*;)',
            r'\1Play as Youtube\2', s, count=1
        )
    elif 'Play as Youtube' in s:
        print('IntegrationsPanel import is already fixed.')
    else:
        raise SystemExit('ERROR: lucide-react Youtube import not found; source was not modified.')

if s != original:
    target.write_text(s, encoding='utf-8')
    print('FIXED:', target)

# Verify the bad import is gone.
check = target.read_text(encoding='utf-8')
if re.search(r'\bYoutube\s*\}\s*from\s*[\'\"]lucide-react', check) and 'Play as Youtube' not in check:
    raise SystemExit('ERROR: validation failed; direct Youtube import still exists.')
if 'Play as Youtube' not in check:
    raise SystemExit('ERROR: validation failed; Play as Youtube alias not present.')
print('VALIDATED: lucide-react import now uses Play as Youtube.')

version = root / 'VERSION'
if version.exists():
    version.write_text('13.04\n', encoding='utf-8')
    print('Updated VERSION -> 13.04')

config = root / 'config/version.php'
if config.exists():
    text = config.read_text(encoding='utf-8')
    text = re.sub(r"'current'\s*=>\s*'[^']+'", "'current' => '13.04'", text, count=1)
    text = re.sub(r"'previous'\s*=>\s*'[^']+'", "'previous' => '13.03'", text, count=1)
    text = re.sub(r"'release_type'\s*=>\s*'[^']+'", "'release_type' => 'minor'", text, count=1)
    text = re.sub(r"'release_date'\s*=>\s*'[^']+'", "'release_date' => '2026-09-08'", text, count=1)
    text = re.sub(r"'release_name'\s*=>\s*'[^']+'", "'release_name' => 'Coolify Vite Lucide Import Fix'", text, count=1)
    config.write_text(text, encoding='utf-8')
    print('Updated config/version.php -> 13.04')

print('\nDONE. Now run:')
print('  git add resources/js/Components/panels/IntegrationsPanel.tsx VERSION config/version.php')
print('  git commit -m "Fix lucide Youtube import for Coolify build - v13.04"')
print('  git push origin main')
print('Then force-redeploy in Coolify without cache.')
