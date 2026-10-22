from pathlib import Path
import zipfile,json,hashlib
base=Path('scratch/backups/inventory-mica-20261022');base.mkdir(parents=True,exist_ok=True)
(base.parent/'.htaccess').write_text('Require all denied\n')
paths=[]
for folder in ['api','includes','components','pages','assets/js','assets/css']:
 paths += [p for p in Path(folder).rglob('*') if p.suffix in ['.php','.js','.css']]
manifest={}
with zipfile.ZipFile(base/'code-before.zip','x',compression=zipfile.ZIP_DEFLATED) as z:
 for p in paths:z.write(p,p.as_posix());manifest[p.as_posix()]=hashlib.sha256(p.read_bytes()).hexdigest()
(base/'manifest.json').write_text(json.dumps(manifest,indent=2))
print('Backed up '+str(len(paths))+' source files.')
