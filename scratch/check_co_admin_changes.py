from pathlib import Path
import json,hashlib,subprocess
backup=Path('scratch/backups/co-admin-permissions-20261022')
manifest=json.loads((backup/'manifest.json').read_text())
changed=[p for p,old in manifest.items() if Path(p).is_file() and hashlib.sha256(Path(p).read_bytes()).hexdigest()!=old]
new=['includes/admin_permission_service.php','pages/admin/sections.php']
(backup/'changed-files.json').write_text(json.dumps({'modified':changed,'added':new},indent=2))
for path in changed+new:
 if path.endswith('.php'):
  result=subprocess.run(['C:/xampp/php/php.exe','-l',path],capture_output=True,text=True)
  if result.returncode: print(result.stdout+result.stderr);raise SystemExit(1)
 if path.endswith('.js'):
  result=subprocess.run(['node','--check',path],capture_output=True,text=True)
  if result.returncode:print(result.stdout+result.stderr);raise SystemExit(1)
print('PASS syntax checks for all '+str(len(changed)+len(new))+' changed application files.')
print('\n'.join(changed+new))
