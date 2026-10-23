"""Offline source inventory and SELECT-only audit generator. Never connects to a DB.

DDL extraction is evidence indexing, not an SQL interpreter or a migration runner.
Conflicting historical definitions are retained for review, never silently replayed.
"""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
TARGET = 'u962995739_bpc_clinic'
OUT = ROOT / 'docs/migration-audit-20261024'
OUT.mkdir(parents=True, exist_ok=True)

def parts(text, delimiter=','):
    out, start, level, quote, i = [], 0, 0, None, 0
    while i < len(text):
        c = text[i]
        if quote:
            if c == '\\': i += 2; continue
            if c == quote:
                if i+1 < len(text) and text[i+1] == quote: i += 2; continue
                quote = None
        elif c in "'\"`": quote = c
        elif c == '(': level += 1
        elif c == ')': level -= 1
        elif c == delimiter and level == 0: out.append(text[start:i].strip()); start = i+1
        i += 1
    out.append(text[start:].strip())
    return out

def closing(text, start):
    level, quote, i = 1, None, start
    while i < len(text):
        c = text[i]
        if quote:
            if c == '\\': i += 2; continue
            if c == quote:
                if i+1 < len(text) and text[i+1] == quote: i += 2; continue
                quote = None
        elif c in "'\"`": quote = c
        elif c == '(': level += 1
        elif c == ')':
            level -= 1
            if not level: return i
        i += 1
    return None

def norm(s): return re.sub(r'\s+', ' ', s).strip()

def decode(data):
    return data.decode('utf-16') if data.startswith((b'\xff\xfe',b'\xfe\xff')) else data.decode('utf-8-sig',errors='replace')

def extract(raw):
    # Strip only full-line SQL comments and dump version directives. Never emit DML values.
    s = re.sub(r'^\s*(?:--|#|/\*!).*$', '', raw, flags=re.M)
    tables, columns, indexes, fks, transformations = set(), [], [], [], []
    for m in re.finditer(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(', s, re.I):
        table = m[1]; tables.add(table); end = closing(s, m.end())
        if end is None: continue
        for item in parts(s[m.end():end]):
            item = norm(item)
            c = re.match(r'`?(\w+)`?\s+(.+)', item, re.S)
            if c and c[1].upper() not in ['PRIMARY','FOREIGN','CONSTRAINT','UNIQUE','KEY','INDEX','CHECK']:
                columns.append(dict(table=table, column=c[1], definition=c[2], action='CREATE'))
            elif re.search(r'FOREIGN\s+KEY', item, re.I): fks.append(dict(table=table, definition=item))
            elif re.match(r'(PRIMARY|UNIQUE|KEY|INDEX)', item, re.I): indexes.append(dict(table=table, definition=item))
    for m in re.finditer(r'ALTER\s+TABLE\s+`?(\w+)`?\s+([^;]+)', s, re.I):
        table = m[1]; tables.add(table)
        for item in parts(m[2]):
            c = re.match(r'(ADD|MODIFY)\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s+(.+)', norm(item), re.I|re.S)
            if c and c[2].upper() not in ['INDEX','KEY','CONSTRAINT','PRIMARY','UNIQUE','FOREIGN']:
                definition = re.split(r"'\s*,\s*@", c[3])[0]
                # Dynamic SQL literals close with a quote. Keep unresolved expressions visibly unresolved.
                definition = re.sub(r"'\s*\)?\s*$", '', definition) if definition.endswith("'") else definition
                columns.append(dict(table=table,column=c[2],definition=definition,action=c[1].upper()))
            elif re.search(r'FOREIGN\s+KEY|DROP\s+FOREIGN',item,re.I): fks.append(dict(table=table,definition=norm(item)[:500]))
            elif re.search(r'INDEX|KEY',item,re.I): indexes.append(dict(table=table,definition=norm(item)[:500]))
    for m in re.finditer(r'CREATE\s+(UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s+ON\s+`?(\w+)`?\s*\(([^)]+)\)', s, re.I):
        tables.add(m[3]); indexes.append(dict(table=m[3],definition=norm(m[0])))
    for m in re.finditer(r'\b(INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?(\w+)`?',s,re.I):
        if m[2].upper() not in ['CURRENT_TIMESTAMP','RESTRICT','CASCADE','SET','NO','setting_value'.upper()]:transformations.append(dict(operation=m[1].upper(),table=m[2]))
    dependencies = sorted(set(re.findall(r'REFERENCES\s+`?(\w+)`?',s,re.I)))
    flags=[]
    for label,pattern in [('fixed_local_database',r'\bUSE\s+`?bpc_clinic_system'),('role_or_status_redefinition',r'MODIFY(?:\s+COLUMN)?\s+(?:role|status)\s+ENUM'),('account_or_profile_DML',r'\b(?:UPDATE\s+(?:users|nurses|students)|INSERT\s+INTO\s+nurses)\b'),('password_mutation',r'UPDATE\s+users\s+SET\s+password_hash'),('foreign_key_drop',r'DROP\s+FOREIGN\s+KEY'),('foreign_key_disable',r'FOREIGN_KEY_CHECKS\s*=\s*0'),('dynamic_DDL_requires_manual_review',r'PREPARE\s+\w+\s+FROM'),('unguarded_ADD_or_constraint',r'ADD\s+(?:COLUMN\s+)?(?!IF\b)(?:\w+|CONSTRAINT)')]:
        if re.search(pattern,s,re.I): flags.append(label)
    return dict(tables=sorted(tables),columns=columns,indexes=indexes,foreign_keys=fks,transformations=transformations,referenced_tables=dependencies,flags=flags)

SPECIAL={
'migration_20260730.sql':('Historic registration fields plus password overwrite','users, nurses','Partly superseded by later profile/registration schemas','CRITICAL','Do not replay: password mutation and fixed local USE; inspect only absent fields.'),
'migration_20260904_four_role_clinic.sql':('Legacy Doctor/adviser conversion to four DB roles','doctors, nurses, teachers, users','Historical role conversion conflicts with current Doctor admin profile model','CRITICAL','Never replay against current roles; inspect legacy data and current profiles separately.'),
'migration_20260911_workflow_consolidation.sql':('Four-role lifecycle, consultation medicines, reporting indexes','four-role conversion; consultations, medicines, certificates, SOS','Partially consolidates earlier role/workflow changes','HIGH','Review role/status enum data and existing indexes; no bulk replay.'),
'migration_20260916_system_simplification.sql':('Conversation model and admin permission/customization tables','users, consultations; legacy appointments','Supersedes legacy message shape, not later attachments/interactions','HIGH','Inspect actual messages shape and named consultation FK before any change.'),
'migration_clinic_enhancement.sql':('Legacy adviser/guidance roles, sections, wellbeing/referrals, SOS extension','users, students, SOS','Role portion superseded by four-role consolidation; clinical tables still relevant','CRITICAL','Do not narrow modern role enum to legacy adviser/guidance values.'),
'migration_teacher_selfreg.sql':('Legacy staff requests and referral-status mapping','clinic enhancement, advisory_assignments, support_referrals','Status enum partly superseded by later workflow consolidation','HIGH','Review legacy request tables and data mappings; do not narrow account lifecycle enum.'),
'migration_qr_system.sql':('Extended QR logs/settings/dynamic QR and historical backfill','QR inventory notifications; walk-in','Replaced by migration_qr_system_fixed.sql for broken dynamic_qrs comments','HIGH','Do not run original: stray closing parentheses after COMMENT literals; compare corrected version.'),
'migration_qr_system_fixed.sql':('Corrected extended QR schema and scan backfill','QR inventory notifications; walk-in','Alternative to migration_qr_system.sql, not a second independent upgrade','HIGH','Review existing schema and historical-scan backfill deduplication before any use.'),
'migration_basic_otp_activation_hostinger.sql':('Portable basic activation timestamp and self-service scope','users; current registration/auth code','Hostinger alternative to SQL/PHP basic OTP variants','HIGH','Choose one compatible path after definition checks; never backfill approvals.'),
'migration_basic_otp_activation.sql':('MariaDB basic activation schema','users','Alternative to Hostinger and PHP OTP paths','MEDIUM','Compare the same two columns; do not run every variant.'),
'migration_basic_otp_activation.php':('CLI wrapper for registration_schema helper','includes/registration_schema.php; users','Alternative basic OTP entry point','HIGH','Do not execute during audit; use one reviewed path only.'),
'migration_admin_invitations.php':('Empty historical placeholder','None','No-op; does not install Doctor invitations','LOW','Do not interpret its filename or execution as evidence of an installed schema.'),
'migration_doctor_invitations_hostinger.sql':('Guarded expiring Doctor invitations and official designation','users, admin_profiles','Hostinger alternative to migration_doctor_invitations.sql','HIGH','Use dedicated preflight/post-verification after review; id types and token uniqueness matter.'),
'migration_doctor_invitations.sql':('Doctor invitation table and designation upgrade','users, administrative_profiles','Alternative to guarded Hostinger invitation script','HIGH','Do not combine entry points or assume IF NOT EXISTS repairs definitions.'),
'migration_nurse_clinical_authorization_hostinger.sql':('Guarded human Doctor-led Nurse authorization and audit','users/nurses, credential_version, Doctor profiles, workflow columns','Preferred guarded Hostinger path over incomplete PHP runner','HIGH','User confirms pending structural migration; retain approval gate and existing pending semantics.'),
'migration_nurse_clinical_authorization.php':('Local legacy Nurse schema runner','users, nurses.shift_schedule','Incomplete alternative: lacks guarded FK/type repair and credential_version addition','HIGH','Do not use as production replacement for the guarded Hostinger migration.'),
'migration_admin_type.sql':('Primary/co-admin classification and existing-admin updates','users','Classification is distinct from later MIS/Doctor admin_profiles','HIGH','Do not replay account reclassification; inspect profile evidence and permissions separately.'),
'migration_administrative_profiles.sql':('MIS/Doctor/legacy profiles, profile history, departures, backup metadata and physician authorship','users, consultations; later invitations adds official_designation','Current functional profiles; not equivalent to legacy role conversion','HIGH','Check profile and physician encounter structures; no automated grant or profile assignment.'),
'migration_teacher_patient.sql':('Teacher patient profiles/QR and clinical associations','users, consultations, medical_records, qr_checkins','Extended by queue_teacher, registration details and Teacher document repair','HIGH','Review existing teachers definition and named FK rerun conflicts.'),
'migration_doctor_teacher_documents_20261024.sql':('Nullable medical_records.student_id for Teacher-only documents','teacher_patient; existing student_id column','Repairs a gap left by teacher_patient','HIGH','Check nullability/type first; preserve all rows and keys.'),
'migration_certificate_number.sql':('Unique certificate number and historical backfill','certificates','Not superseded','HIGH','Review generated numbering collisions and existing uniqueness before backfill.'),
'migration_sos_enhance.sql':('Notification-to-SOS reference and heuristic time-window backfill','notifications, SOS','Not superseded','HIGH','Time-window matching can be ambiguous; do not auto-run historical backfill.'),
'migration_qr_inventory_notifications.sql':('Permanent Student QR, inventory fields, notification metadata and QR backfill','users, students, consultations, medicines, notifications, qr_checkins','Base for later QR migrations, not replaceable by single-token enhancement','HIGH','Legacy payload/token backfill is data-changing; inspect canonical current token policy.'),
'migration_registration_role_medical_details.sql':('Teacher health/emergency fields and Nurse appointment/emergency details','teachers, nurses','Not superseded; overlaps existing richer snapshots','HIGH','Unguarded ADDs can collide with columns already installed; credentials must be supplied by applicant.'),
'migration_queue_teacher.sql':('Teacher-aware queue associations','walk-in queue, teachers','Not superseded','HIGH','Named FK addition is not fully rerunnable; inspect equivalent FK first.'),
'bpc_clinic_system.sql':('Original fresh-install schema with seeds','Empty new database only','Historical baseline, not current consolidated upgrade','CRITICAL','Never import over production; fixed local database and seeded accounts/settings.'),
'backup_before_admin_refactor_20261022.sql':('Historical full schema/data snapshot','Restore procedure only','Backup evidence, not an upgrade migration','CRITICAL','Never import as migration; contains private records, DROP/INSERT and session directives.'),
}

def classify(path, effects, size):
    name=Path(path).name
    if name in SPECIAL: purpose,deps,superseded,risk,action=SPECIAL[name]
    elif name.startswith(('preflight_','verify_')):
        purpose='Read-only dedicated schema/deployment verification';deps=', '.join(effects['tables']) or 'Corresponding module metadata';superseded='Companion check, not a migration';risk='LOW';action='Read and review results; its success is not permission to apply DDL.'
    else:
        purpose=name.replace('migration_','').replace('migrate_','').replace('.sql','').replace('.php','').replace('_',' ')
        deps=', '.join(sorted(set(effects['referenced_tables']+effects['tables']))) or 'Delegated SQL/helper; see wrapper details'
        superseded='Not established; inspect overlaps and definition conflicts'
        risk='HIGH' if effects['transformations'] or effects['flags'] else 'MEDIUM'
        action='Compare objects/definitions first; do not replay solely from filename or table presence.'
    status='MISSING' if name=='migration_nurse_clinical_authorization_hostinger.sql' else 'UNKNOWN'
    evidence='User reports clinical structures still require migration; prerequisite metadata/orphan checks passed.' if status=='MISSING' else 'No complete production results supplied. File presence is not execution evidence.'
    return dict(purpose=purpose,dependencies=deps,superseded=superseded,risk=risk,recommended_action=action,production_status=status,status_evidence=evidence)

tracked=subprocess.check_output(['git','ls-files'],cwd=ROOT,text=True).splitlines()
paths=set(p for p in tracked if p.endswith('.sql') or (p.endswith('.php') and ('migrat' in Path(p).name)))
paths.add('includes/registration_schema.php')
records=[]
for path in sorted(paths):
    data=(ROOT/path).read_bytes();text=decode(data);effect=extract(text)
    wrapper_sql=re.findall(r"['\"]([^'\"]*(?:migration_[^'\"]+\.sql|registration_schema\.php))['\"]",text)
    effect['delegates_to']=sorted(set(wrapper_sql))
    records.append(dict(path=path,sha256=hashlib.sha256(data).hexdigest(),bytes=len(data),**effect,**classify(path,effect,len(data))))

# Archive copies are inventory evidence only: read only migration/SQL members,
# never extract or publish data, account seeds, secrets or configuration files.
archives=[]
for archive in (p for p in tracked if p.endswith('.zip')):
    with zipfile.ZipFile(ROOT/archive) as z:
        for info in z.infolist():
            if info.filename.endswith('.sql') or (info.filename.endswith('.php') and 'migrat' in Path(info.filename).name):
                data=z.read(info);e=extract(decode(data))
                archives.append(dict(archive=archive,member=info.filename,bytes=len(data),sha256=hashlib.sha256(data).hexdigest(),tables=e['tables'],flags=e['flags'],production_status='UNKNOWN',risk='CRITICAL',recommended_action='Historical packaged copy only; not an additional migration to execute.'))

# Publish sanitized schema evidence, never DML bodies from dumps or archives.
manifest=dict(target_schema=TARGET,production_access=False,inventory=records,archive_copies=archives,limitations=['Static extraction indexes DDL; dynamic expressions and historical alternatives require review.','APPLIED is not inferred from filenames or structural footprint.','Archived snapshots and base install scripts are not deployable upgrades.'])
(OUT/'inventory.json').write_text(json.dumps(manifest,indent=2,ensure_ascii=True)+'\n',encoding='utf-8')

lines=['# Complete source migration inventory','', 'Production status is evidence-based: UNKNOWN unless explicitly supplied by the user. MISSING for the guarded Nurse clinical migration records the user-reported outstanding structures, not a live observation. APPLIED requires reviewed production definitions/data evidence; no execution ledger was found.','', '| File | Purpose | Dependencies | Production status | Supersession / conflict | Risk | Recommended action |','| --- | --- | --- | --- | --- | --- | --- |']
for r in records:
    lines.append('| '+' | '.join(str(r[k]).replace('|','/') for k in ['path','purpose','dependencies','production_status','superseded','risk','recommended_action'])+' |')
lines+=['','## Per-file structural effects','', 'Column definitions are alternatives as written by source files, not an instruction to concatenate them. Dynamic SQL may require manual resolution. DML is summarized by operation/table only; private dump data is omitted.','']
for r in records:
    lines+=['### '+r['path'],'', 'SHA-256: `'+r['sha256']+'`. Size: '+str(r['bytes'])+' bytes.', '', 'Tables: '+(', '.join(r['tables']) or 'No direct table DDL; wrapper/verification/empty file.'), '']
    if r['columns']:lines+=['Columns: '+ '; '.join('`'+c['table']+'.'+c['column']+'` '+c['action']+' '+c['definition'].replace('|','/') for c in r['columns']), '']
    for key,label in [('indexes','Indexes'),('foreign_keys','Foreign keys')]:
        if r[key]:lines+=[label+': '+'; '.join(x['table']+': '+x['definition'].replace('|','/') for x in r[key]),'']
    if r['transformations']:lines+=['Data operations: '+', '.join(sorted(set(x['operation']+' '+x['table'] for x in r['transformations']))), '']
    if r['delegates_to']:lines+=['Delegates to: '+', '.join(r['delegates_to']),'']
lines+=['## Packaged historical copies','', '| Archive | Member | SHA-256 | Status / action |','| --- | --- | --- | --- |']
for r in archives:lines.append('| '+r['archive']+' | '+r['member']+' | '+r['sha256']+' | UNKNOWN; historical packaged copy, never an additional upgrade |')
(OUT/'INVENTORY.md').write_text('\n'.join(lines)+'\n',encoding='utf-8')

def q(v):return "'"+str(v).replace("'","''")+"'"
def derived(rows,names):return '\nUNION ALL\n'.join('SELECT '+','.join(q(v)+' AS '+name if i==0 else q(v) for v,name in zip(row,names)) for i,row in enumerate(rows))

# Union of source objects is useful for missing/partial footprint detection but
# deliberately does not select a winner among historical definition conflicts.
columns={};tables=set()
for r in records:
    if r['path'].endswith('.php') or Path(r['path']).name.startswith(('preflight','verify')):continue
    for t in r['tables']:
        if re.fullmatch('[a-z][a-z0-9_]*',t):tables.add(t)
    for c in r['columns']:
        if re.fullmatch('[a-z][a-z0-9_]*',c['column']):columns.setdefault((c['table'],c['column']),[]).append((r['path'],c['definition']))

sql=['-- READ ONLY. Explicit production schema. SELECT/information_schema only.',
 '-- No USE/SET/PREPARE/EXECUTE, no DDL/DML, no row contents or secret values.',
 '-- Historical alternatives are evidence, not a merged executable final schema.',
 f"SELECT DATABASE() AS selected_database,{q(TARGET)} AS intended_database,VERSION() AS server_version,@@lower_case_table_names AS lower_case_table_names,@@FOREIGN_KEY_CHECKS AS foreign_key_checks,CASE WHEN EXISTS(SELECT 1 FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY {q(TARGET)}) AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA=BINARY {q(TARGET)} AND BINARY TABLE_NAME IN ('users','nurses') AND TABLE_TYPE='BASE TABLE')=2 THEN 'TARGET_VERIFIED' ELSE 'BLOCK_TARGET_NOT_VERIFIED' END AS target_result;"]
sql.append('SELECT e.table_name,t.TABLE_TYPE,t.ENGINE,CASE WHEN t.TABLE_NAME IS NULL THEN \'MISSING_OR_NOT_VISIBLE\' WHEN t.TABLE_TYPE<>\'BASE TABLE\' THEN \'BLOCK_NOT_BASE_TABLE\' ELSE \'PRESENT_REVIEW_ROLE_AND_ENGINE\' END AS result FROM ('+derived([(t,) for t in sorted(tables)],['table_name'])+f") e LEFT JOIN information_schema.TABLES t ON BINARY t.TABLE_SCHEMA=BINARY {q(TARGET)} AND BINARY t.TABLE_NAME=BINARY e.table_name ORDER BY e.table_name;")
items=sorted(columns)
for offset in range(0,len(items),150):
    rows=[(t,c,str(len(set(d for _,d in columns[(t,c)])))) for t,c in items[offset:offset+150]]
    sql.append('SELECT e.table_name,e.column_name,e.source_definition_variants,c.COLUMN_TYPE,c.IS_NULLABLE,c.COLUMN_DEFAULT,c.EXTRA,c.CHARACTER_SET_NAME,c.COLLATION_NAME,CASE WHEN c.COLUMN_NAME IS NULL THEN \'MISSING_OR_NOT_VISIBLE\' WHEN e.source_definition_variants>1 THEN \'PRESENT_CONFLICTING_SOURCE_DEFINITIONS_REVIEW\' ELSE \'PRESENT_COMPARE_WITH_INVENTORY_DEFINITION\' END AS result FROM ('+derived(rows,['table_name','column_name','source_definition_variants'])+f") e LEFT JOIN information_schema.COLUMNS c ON BINARY c.TABLE_SCHEMA=BINARY {q(TARGET)} AND BINARY c.TABLE_NAME=BINARY e.table_name AND c.COLUMN_NAME=e.column_name ORDER BY e.table_name,e.column_name;")
for table,order in [('STATISTICS','TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX'),('KEY_COLUMN_USAGE','TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION'),('REFERENTIAL_CONSTRAINTS','TABLE_NAME,CONSTRAINT_NAME'),('TABLE_CONSTRAINTS','TABLE_NAME,CONSTRAINT_NAME')]:
    schema='CONSTRAINT_SCHEMA' if table=='REFERENTIAL_CONSTRAINTS' else 'TABLE_SCHEMA'
    sql.append(f'SELECT * FROM information_schema.{table} WHERE BINARY {schema}=BINARY {q(TARGET)} ORDER BY {order};')
sql+= [f"SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA=BINARY {q(TARGET)} ORDER BY TRIGGER_NAME;",
 f"SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS column_signature,MIN(NON_UNIQUE) AS non_unique FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA=BINARY {q(TARGET)} GROUP BY TABLE_NAME,INDEX_NAME ORDER BY TABLE_NAME,column_signature;",
 f"SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,COUNT(*) AS parts,GROUP_CONCAT(CONCAT(k.COLUMN_NAME,'->',k.REFERENCED_TABLE_SCHEMA,'.',k.REFERENCED_TABLE_NAME,'.',k.REFERENCED_COLUMN_NAME) ORDER BY k.ORDINAL_POSITION) AS relationship FROM information_schema.KEY_COLUMN_USAGE k WHERE BINARY k.TABLE_SCHEMA=BINARY {q(TARGET)} AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.TABLE_NAME,k.CONSTRAINT_NAME ORDER BY k.TABLE_NAME,relationship;"]
# Content-free generated integrity checks, never executed by this preflight.
sql.append(f"SELECT CONCAT('SELECT COUNT(*) AS orphan_count FROM `',REPLACE(k.TABLE_SCHEMA,'`','``'),'`.`',REPLACE(k.TABLE_NAME,'`','``'),'` c LEFT JOIN `',REPLACE(k.REFERENCED_TABLE_SCHEMA,'`','``'),'`.`',REPLACE(k.REFERENCED_TABLE_NAME,'`','``'),'` p ON c.`',REPLACE(k.COLUMN_NAME,'`','``'),'`=p.`',REPLACE(k.REFERENCED_COLUMN_NAME,'`','``'),'` WHERE c.`',REPLACE(k.COLUMN_NAME,'`','``'),'` IS NOT NULL AND p.`',REPLACE(k.REFERENCED_COLUMN_NAME,'`','``'),'` IS NULL') AS review_only_orphan_check_sql,k.TABLE_NAME,k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE BINARY k.TABLE_SCHEMA=BINARY {q(TARGET)} AND BINARY k.REFERENCED_TABLE_SCHEMA=BINARY {q(TARGET)} AND k.REFERENCED_TABLE_NAME IS NOT NULL AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE x WHERE x.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND x.TABLE_NAME=k.TABLE_NAME AND x.CONSTRAINT_NAME=k.CONSTRAINT_NAME)=1;")
sql.append(f"SELECT 'Production execution history remains UNKNOWN. Review definitions, constraints and data checks before classifying APPLIED. Do not run migrations from this output.' AS audit_boundary;")
(ROOT/'database/preflight_complete_migration_audit_hostinger.sql').write_text('\n\n'.join(sql)+'\n',encoding='utf-8')
print(json.dumps(dict(files=len(records),archive_copies=len(archives),tables=len(tables),columns=len(columns),output=str(OUT))))
