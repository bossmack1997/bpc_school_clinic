from pathlib import Path
p=Path('components/mica.php');s=p.read_text().replace("  role: '<?= $micaRole ?>',", "  role: '<?= $micaRole ?>',\n  inventoryAllowed: <?= can_view_inventory($micaUser)?'true':'false' ?>,",1);p.write_text(s)
p=Path('assets/js/mica-inventory.js');s=p.read_text().replace("function allowed() { return ['admin','nurse'].includes(role()); }", "function allowed() { return role()==='nurse'||(window.MICA_USER||{}).inventoryAllowed===true; }")
s=s.replace("if (!response.ok || !body.success) throw new Error(body.message || 'Inventory could not be loaded.');", "if (!response.ok || !body.success){var failure=new Error(body.message || 'Inventory could not be loaded.');failure.status=response.status;throw failure;}")
s=s.replace("    } catch(error) {\n      var existing", "    } catch(error) {\n      if(error.status===401||error.status===403){var oldPanel=container.querySelector('.mica-inventory');if(oldPanel)oldPanel.remove();}\n      var existing",1)
s=s.replace("'Manage Medicine Stocks'", "role()==='nurse'?'Manage Medicine Stocks':'Inventory Oversight'");p.write_text(s)
p=Path('api/mica_inventory.php');s=p.read_text();pos=s.index('$db =');s=s[:pos]+'''header('Cache-Control: no-store, private');
set_exception_handler(function(Throwable $e){if(getDB()->inTransaction())getDB()->rollBack();error_log('MICA inventory: '.$e->getMessage());http_response_code(500);json_response(false,[],'Inventory could not be retrieved. Please retry.');});
'''+s[pos:]
s=s.replace("    csrf_verify_or_die();", "    if(!csrf_verify($_POST['csrf_token']??'')){http_response_code(403);json_response(false,[],'Security token expired. Refresh and try again.');}")
s=s.replace("'expiration_date']", "'expiration_date','generic_name','brand','supplier','quantity_rule','usage_in_lookback']")
p.write_text(s)
p=Path('includes/ai/MICAWorkflowService.php');s=p.read_text().replace("if (!in_array($role, ['admin','nurse'], true))", "if (!can_view_inventory($this->user,$this->db))",1)
needle="        $stmt = $this->db->query(\"\n            SELECT id, medicine_name, quantity, unit, expiration_date,";s=s.replace(needle,"        if(!can_view_inventory($this->user,$this->db))return ['handled'=>true,'reply'=>'Inventory access is not assigned to this account.','type'=>'access_denied'];\n"+needle,1);p.write_text(s)
p=Path('includes/ai/DatabaseContext.php');s=p.read_text();a=s.index('        if ($role === \'student\')',s.index('public function getMedicines'));b=s.index('\n    // ================================================================',a)
s=s[:a]+'''        if(!can_view_inventory($this->user,$this->db))return [];
        require_once __DIR__.'/../medicine_inventory_service.php';
        return array_values((new MedicineInventoryService($this->db))->snapshot());
    }
'''+s[b:]
s=s.replace('$lowMedStmt = $this->db->query("SELECT COUNT(*) FROM medicine_inventory WHERE quantity <= min_quantity OR is_available = 0");', '')
s=s.replace('$lowMedStmt = $this->db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level");', '$lowMedStmt = can_view_inventory($this->user,$this->db)?$this->db->query("SELECT COUNT(*) FROM medicines WHERE is_archived=0 AND quantity<=reorder_level"):null;')
s=s.replace('$lowMeds = (int)$lowMedStmt->fetchColumn();','$lowMeds = $lowMedStmt?(int)$lowMedStmt->fetchColumn():0;');p.write_text(s)
p=Path('includes/ai/MICAContextService.php');s=p.read_text().replace('            // Low Stock & Reorders\n', '            // Low Stock & Reorders\n            if(can_view_inventory($this->user,$this->db)){\n',1).replace('$lines[] = "Low Stock Items: $lowCount | Reorder Requests for Approval: $pendingReorders";', '$lines[] = "Low Stock Items: $lowCount | Reorder Requests for Approval: $pendingReorders";\n            }',1);p.write_text(s)
# Existing direct reorder endpoints may create reviewed drafts, never bypass nursing approval or stock rules.
p=Path('api/ai_assistant.php');s=p.read_text();a=s.index("if($user['role']==='admin'&&in_array($action,['reorder_list'");b=s.index('\n',s.index("admin_access_denied(",a));s=s[:a]+'''if(in_array($action,['reorder_list','create_reorder'],true))require_inventory_access();
if(in_array($action,['approve_reorder','reject_reorder'],true))require_inventory_access(true);'''+s[b:];p.write_text(s)
p=Path('includes/ai/MICAActionService.php');s=p.read_text();old="if(($this->user['role']??'')==='admin'&&!is_primary_admin($this->user))return ['success'=>false,'message'=>'Inventory administration is restricted to the Primary Administrator or clinic staff.'];"
s=s.replace(old,"if(!can_view_inventory($this->user,$this->db))return ['success'=>false,'message'=>'Inventory access is not assigned.'];",1)
s=s.replace(old,"if(($this->user['role']??'')!=='nurse')return ['success'=>false,'message'=>'Nurse review is required.'];")
# A supplied quantity in this legacy draft path remains human input, not an automatic approved order.
p.write_text(s)
p=Path('includes/functions.php');s=p.read_text();pos=s.index('    $db = getDB();',s.index('function push_notification('))+len('    $db = getDB();');s=s[:pos]+'''
    if(in_array($type,['inventory','medicine','mica_inventory'],true)){
        $recipient=$db->prepare('SELECT id,role,admin_type FROM users WHERE id=?');$recipient->execute([(int)$userId]);$recipient=$recipient->fetch(PDO::FETCH_ASSOC);
        if($recipient&&$recipient['role']==='admin'&&!can_view_inventory($recipient,$db))return 0;
    }
'''+s[pos:];p.write_text(s)
