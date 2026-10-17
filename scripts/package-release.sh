#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
VERSION=$(cat VERSION)
mkdir -p dist
FULL="dist/homelab-griller-v${VERSION}-full.zip"
UPDATE="dist/homelab-griller-v${VERSION}-update.zip"
rm -f "$FULL" "$UPDATE"
python3 - "$VERSION" <<'PY'
import os,sys,subprocess,zipfile,pathlib
root=pathlib.Path('.');version=sys.argv[1]
exclude={'.git','.env','data','dist','node_modules','__pycache__','.idea'}
files=sorted(p for p in root.rglob('*') if p.is_file() and not any(part in exclude for part in p.parts) and not p.name.endswith('.pyc'))
full=root/'dist'/f'homelab-griller-v{version}-full.zip'
update=root/'dist'/f'homelab-griller-v{version}-update.zip'
with zipfile.ZipFile(full,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=8) as z:
    for p in files:z.write(p,p.as_posix())
# Update ZIP contains files changed since the previous version tag.
try:
    tags=subprocess.check_output(['git','tag','--list','v*'],text=True).splitlines()
    current='v'+version
    previous=[t for t in tags if t!=current]
    if not previous:raise ValueError('first release')
    previous=subprocess.check_output(['git','describe','--tags','--abbrev=0',current+'^'],text=True).strip()
    changed=set(subprocess.check_output(['git','diff','--name-only','--diff-filter=ACMR',previous,current],text=True).splitlines())
    update_files=[p for p in files if p.as_posix() in changed]
except Exception:
    update_files=files
with zipfile.ZipFile(update,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=8) as z:
    for p in update_files:z.write(p,p.as_posix())
print('Full:',full,'files:',len(files),'Update:',update,'files:',len(update_files))
PY
