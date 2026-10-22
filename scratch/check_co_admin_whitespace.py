from pathlib import Path
import json,subprocess
files=json.loads(Path('scratch/backups/co-admin-permissions-20261022/changed-files.json').read_text())
result=subprocess.run(['git','diff','--check','--']+files['modified'],capture_output=True,text=True)
print(result.stdout+result.stderr if result.returncode else 'PASS targeted whitespace check for all modified application files.')
raise SystemExit(result.returncode)
