"""Offline inventory and syntax checks; never imports application code."""
import json
import os
import pathlib
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
SKIP = {'.git', 'vendor', 'scratch', 'node_modules', '.vscode', '.kilo', '.qodo'}
files = []
for folder, directories, names in os.walk(ROOT):
    directories[:] = [name for name in directories if name not in SKIP]
    files.extend(pathlib.Path(folder) / name for name in names)
files.sort()
sources = {p: p.read_text(encoding='utf-8', errors='replace') for p in files if p.suffix in {'.php', '.js', '.cjs', '.css', '.html', '.json'}}
runtime = {p: text for p, text in sources.items() if p.relative_to(ROOT).parts[0] not in {'tools', 'tests', 'database', 'setup'}}
report = {'files': [str(p.relative_to(ROOT)).replace('\\', '/') for p in files], 'unreferenced_asset_candidates': [], 'syntax': [], 'limitations': ['No filename-only candidate is approved for deletion; dynamic references require manual review.']}
for path in sources:
    relative = path.relative_to(ROOT)
    if relative.parts[0] == 'assets' and path.suffix in {'.css', '.js'}:
        refs = [str(p.relative_to(ROOT)) for p, text in runtime.items() if p != path and path.name in text]
        if not refs:
            report['unreferenced_asset_candidates'].append(str(relative).replace('\\', '/'))
    command = None
    if path.suffix == '.php':
        command = ['C:/xampp/php/php.exe', '-l', str(path)]
    elif path.suffix in {'.js', '.cjs'}:
        command = ['node', '--check', str(path)]
    if command:
        result = subprocess.run(command, capture_output=True, text=True)
        report['syntax'].append({'file': str(relative).replace('\\', '/'), 'passed': result.returncode == 0, 'error': result.stderr.strip() if result.returncode else ''})
destination = ROOT / 'scratch/cleanup-audit/sources.json'
destination.parent.mkdir(parents=True, exist_ok=True)
destination.write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps({'files': len(files), 'syntax_checks': len(report['syntax']), 'failures': [r for r in report['syntax'] if not r['passed']], 'asset_candidates': report['unreferenced_asset_candidates']}))
