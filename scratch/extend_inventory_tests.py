from pathlib import Path
p=Path('scratch/test_inventory_upgrade.php');s=p.read_text().replace("'admin_permissions'] as $table)","'admin_permissions','medicine_reorders','ai_actions'] as $table)")
s=s.replace("session_destroy();echo", """// The legacy MICA request path must use calculated suggestions and remain unapproved.
require __DIR__.'/../includes/ai/MICAActionService.php';
$db->exec(\"UPDATE medicines SET quantity=5 WHERE id=1\");
$mica=new MICAActionService($db,$u(902));
$result=$mica->createReorderRequest(1,999999,'Human-reviewed draft fixture');
invCheck($result['success'],'Delegated MICA draft request is authorized');
$reorder=$db->query('SELECT * FROM medicine_reorders LIMIT 1')->fetch();
invCheck((int)$reorder['suggested_quantity']!==(int)999999&&$reorder['status']==='for_approval'&&$reorder['approved_quantity']===null,'MICA draft ignores invented quantity and requires human review');
invCheck(!$mica->approveReorderRequest((int)$reorder['id'],20)['success'],'Co-admin cannot approve a draft');
$primaryMica=new MICAActionService($db,$u(903));invCheck(!$primaryMica->createReorderRequest(1,1,'Not authorized')['success'],'Super Admin cannot bypass technical-only inventory scope');
session_destroy();echo""")
p.write_text(s)
