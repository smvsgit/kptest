from pathlib import Path
import sys

root = Path(sys.argv[1] if len(sys.argv) > 1 else '.').resolve()
file = root / 'resources/js/Components/panels/IntegrationsPanel.tsx'
if not file.exists():
    raise SystemExit(f'ERROR: {file} not found. Run this from the repository root or pass the repo path.')

s = file.read_text(encoding='utf-8')
original = s

# v13.03: lucide-react version in the resolved Coolify build does not export `Youtube`.
# Preserve existing JSX name by aliasing the stable `Play` icon as `Youtube`.
if 'Play as Youtube' not in s:
    if 'Youtube } from "lucide-react"' in s:
        s = s.replace('Youtube } from "lucide-react"', 'Play as Youtube } from "lucide-react"')
    elif "Youtube } from 'lucide-react'" in s:
        s = s.replace("Youtube } from 'lucide-react'", "Play as Youtube } from 'lucide-react'")
    elif ', Youtube,' in s:
        s = s.replace(', Youtube,', ', Play as Youtube,')
    elif ', Youtube }' in s:
        s = s.replace(', Youtube }', ', Play as Youtube }')
    else:
        raise SystemExit('ERROR: Could not find the lucide-react Youtube import. No file was changed.')

if s == original:
    print('IntegrationsPanel already patched; no source change required.')
else:
    file.write_text(s, encoding='utf-8')
    print(f'Patched: {file}')

# Bump version markers when present.
version = root / 'VERSION'
if version.exists():
    version.write_text('13.03\n', encoding='utf-8')
    print('Updated VERSION -> 13.03')

config = root / 'config/version.php'
if config.exists():
    text = config.read_text(encoding='utf-8')
    import re
    text = re.sub(r"'current'\s*=>\s*'[^']+'", "'current' => '13.03'", text, count=1)
    text = re.sub(r"'previous'\s*=>\s*'[^']+'", "'previous' => '13.02'", text, count=1)
    text = re.sub(r"'release_type'\s*=>\s*'[^']+'", "'release_type' => 'minor'", text, count=1)
    text = re.sub(r"'release_date'\s*=>\s*'[^']+'", "'release_date' => '2026-09-08'", text, count=1)
    text = re.sub(r"'release_name'\s*=>\s*'[^']+'", "'release_name' => 'Coolify Vite Icon Build Fix'", text, count=1)
    config.write_text(text, encoding='utf-8')
    print('Updated config/version.php -> 13.03')

print('DONE. Commit/push these changes and redeploy in Coolify without cache.')
