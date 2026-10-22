from pathlib import Path
import zipfile,json,hashlib
backup=Path('scratch/backups/co-admin-permissions-20261022');backup.mkdir(parents=True,exist_ok=True)
(backup.parent/'.htaccess').write_text('Require all denied\n')
files=[]
for directory in ['api','includes','components','pages/admin']:
 files+=list(Path(directory).rglob('*.php'))
files+=list(Path('assets/js').glob('*.js'))
manifest={}
with zipfile.ZipFile(backup/'code-before.zip','x',compression=zipfile.ZIP_DEFLATED) as archive:
 for path in files:
  archive.write(path,path.as_posix());manifest[path.as_posix()]=hashlib.sha256(path.read_bytes()).hexdigest()
(backup/'manifest.json').write_text(json.dumps(manifest,indent=2))
print('Recoverable code backup created; '+str(len(manifest))+' files.')
