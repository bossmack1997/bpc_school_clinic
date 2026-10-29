"""Offline tracked-source inventory. No application imports or deletion decisions."""
from pathlib import Path
import collections
import hashlib
import json
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
paths = subprocess.check_output(['git', 'ls-files'], cwd=ROOT, text=True).splitlines()
folders = {'api', 'auth', 'components', 'config', 'includes', 'pages', 'assets', 'database', 'setup'}
sources = {}
for name in paths:
    path = ROOT / name
    if (name.split('/')[0] in folders or '/' not in name) and path.suffix in {'.php', '.js', '.css', '.sql'} and path.is_file():
        # Database dumps may contain real records: do not inventory their contents.
        if path.suffix == '.sql' and ('backup' in path.name.lower()):
            continue
        if '.min.' in path.name:
            continue
        sources[name] = path.read_text(encoding='utf-8', errors='replace')

report = {'base_commit': subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),
          'method': 'static inventory; guards/references do not prove authorization or runtime reachability',
          'tracked_files': len(paths), 'sources': [], 'routes': [], 'writes': [], 'direct_log_writers': [], 'terminology_candidates': []}
terms = re.compile(r'Authentication|Activity Logs|User Identifier|Clinical Encounter|Credential Verification|Backup Repository|Restore Dataset|Transaction Audit|Investigation', re.I)
for name, text in sources.items():
    guards = sorted(set(re.findall(r'\b(require_(?:role|permission|admin_permission|primary_admin|doctor|login))\s*\(([^;\n]*)\)', text)))
    dependencies = []
    for match in re.finditer(r'\b(?:require|include)(?:_once)?\s+(?:\(?\s*)?__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]', text):
        target = ((ROOT/name).parent / match[1].lstrip('/')).resolve()
        try: target_name = target.relative_to(ROOT).as_posix()
        except ValueError: target_name = 'outside-project'
        dependencies.append({'target':target_name,'exists':target.is_file()})
    tables = sorted(set(re.findall(r'\b(?:FROM|JOIN|UPDATE|INTO|TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?([a-z][a-z0-9_]*)',text,re.I)))
    item = {'file':name,'lines':len(text.splitlines()),'sha256':hashlib.sha256(text.encode()).hexdigest(),
            'observed_guards':[a+'('+b+')' for a,b in guards], 'static_includes':dependencies,'table_candidates':tables,
            'validation':'static only; see audit report for dynamic evidence'}
    report['sources'].append(item)
    if (name.startswith(('pages/','api/','auth/','setup/')) or '/' not in name) and name.endswith('.php'):
        title = re.search(r'\$pageTitle\s*=\s*[\'"]([^\'"]+)',text)
        actions = sorted(set(re.findall(r'\$action\s*===?\s*[\'"]([^\'"]+)',text)+re.findall(r'case\s+[\'"]([^\'"]+)','' if not name.startswith('api/') else text)))
        group = name.split('/')[1] if name.startswith('pages/') and len(name.split('/'))>2 else name.split('/')[0]
        report['routes'].append({'file':name,'group':group,'purpose':title[1] if title else Path(name).stem.replace('_',' '),
                                 'actions':actions, 'observed_guards':item['observed_guards'], 'static_includes':dependencies})
    for number,line in enumerate(text.splitlines(),1):
        for operation, table in re.findall(r'\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z][a-z0-9_]*)',line,re.I):
            report['writes'].append({'file':name,'line':number,'operation':operation.upper(),'table':table})
        if re.search(r'INSERT\s+INTO\s+activity_logs',line,re.I): report['direct_log_writers'].append({'file':name,'line':number})
        matches = sorted(set(terms.findall(line)))
        if matches: report['terminology_candidates'].append({'file':name,'line':number,'terms':matches,'note':'may be code/comment; review before relabeling'})

report['counts'] = {'inventoried_sources':len(sources),'routes':len(report['routes']),
                    'source_extensions':dict(collections.Counter(Path(n).suffix for n in sources)),
                    'route_groups':dict(collections.Counter(r['group'] for r in report['routes'])),
                    'direct_activity_insert_sites':len(report['direct_log_writers']),
                    'write_candidates':len(report['writes'])}
out = ROOT/'docs/FINAL_SYSTEM_SOURCE_INVENTORY_20261029.json'
out.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
lines = ['# Route inventory — October 29, 2026','',
         'Every tracked page, API and authentication PHP route is listed below. This is a static inventory, not a claim that every route or button passed a browser test. Guards may be inherited from configuration or included entry points. API action detection is heuristic. No route is approved for deletion by this inventory.','',
         '| Route | Purpose/title | Observed local guard | Disposition |','| --- | --- | --- | --- |']
for route in report['routes']:
    guard = '; '.join(route['observed_guards']) or 'Delegated/inspect include chain'
    lines.append('| `'+route['file']+'` | '+route['purpose'].replace('|','/')+' | '+guard.replace('|','/').replace('\n',' ')+' | Retain pending feature/dependency review |')
(ROOT/'docs/FINAL_SYSTEM_ROUTE_INVENTORY_20261029.md').write_text('\n'.join(lines)+'\n',encoding='utf-8')
print(json.dumps(report['counts'],indent=2))
