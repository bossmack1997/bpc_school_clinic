import hashlib,json,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[1]
manifest=json.loads((root/'scratch/backups/inventory-mica-20261022/manifest.json').read_text())
changed=[p for p,h in manifest.items() if (root/p).exists() and hashlib.sha256((root/p).read_bytes()).hexdigest()!=h]
changed+=['assets/css/inventory-refinements.css']
for p in changed:
    if p.endswith('.php'): subprocess.run(['C:/xampp/php/php.exe','-l',str(root/p)],check=True,capture_output=True)
    if p.endswith('.js'): subprocess.run(['node','--check',str(root/p)],check=True,capture_output=True)
(root/'scratch/inventory-upgrade-qa/changed-files.json').write_text(json.dumps(changed,indent=2))
print('PASS syntax for changed PHP/JS sources; changed files:',len(changed))
