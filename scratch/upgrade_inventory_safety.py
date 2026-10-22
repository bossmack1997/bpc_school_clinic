from pathlib import Path
p=Path('api/inventory.php');s=p.read_text();s=s.replace("    $ok=(int)$db->query('SELECT 1')->fetchColumn()===1;", "    $ok=(int)$db->query('SELECT 1')->fetchColumn()===1;\n    $db->query('SELECT id,quantity,reorder_level,expiration_date FROM medicines LIMIT 0');")
# Serialize full-record edits and reject a changed quantity baseline from the existing form.
a=s.index('    $stmt = $db->prepare("\n        UPDATE medicines SET',s.index("if ($action === 'update')"));s=s[:a]+'''    $db->beginTransaction();
    $locked=$db->prepare('SELECT quantity FROM medicines WHERE id=? AND is_archived=0 FOR UPDATE');$locked->execute([$id]);$previous=$locked->fetchColumn();
    if($previous===false)throw new InvalidArgumentException('Medicine not found.');
    if(isset($_POST['original_quantity'])&&$_POST['original_quantity']!==''&&(int)$_POST['original_quantity']!==(int)$previous){$db->rollBack();http_response_code(409);json_response(false,[],'Stock changed since this record was opened. Reopen it before saving.');}
'''+s[a:]
needle="    update_medicine_status($db, $id);\n    json_response(true, [], 'Medicine updated.');"
s=s.replace(needle,"    update_medicine_status($db, $id);\n    if($quantity!==(int)$previous)$db->prepare(\"INSERT INTO inventory_logs (medicine_id,change_type,quantity_change,remarks,logged_by) VALUES (?,'adjustment',?,'Quantity changed in medicine editor',?)\")->execute([$id,$quantity-(int)$previous,$user['id']]);\n    $db->commit();\n    json_response(true, [], 'Medicine updated.');",1)
p.write_text(s)
p=Path('includes/ai/MICAActionService.php');s=p.read_text();needle="        // Check for existing pending request to prevent duplicates";pos=s.index(needle,s.index('public function createReorderRequest'));s=s[:pos]+'''        require_once __DIR__.'/../medicine_inventory_service.php';
        $facts=(new MedicineInventoryService($this->db))->snapshot();$fact=$facts[$medicineId]??null;
        if(!$fact||!isset(MedicineInventoryService::requisitionItems($facts)[$medicineId])||empty($fact['suggested_quantity']))return ['success'=>false,'message'=>'This medicine needs a configured threshold and a current restock shortage.'];
        $suggestedQty=(int)$fact['suggested_quantity'];
'''+s[pos:]
# Do not populate approved quantity on an unreviewed request.
s=s.replace("            max(1, $suggestedQty),\n            max(1, $suggestedQty),", "            max(1, $suggestedQty),\n            null,",1)
p.write_text(s)
# Old fixture now models policy and usage reads rather than returning medicines for every query.
p=Path('scratch/test_mica_inventory.php');s=p.read_text().replace("public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args):array { return $this->db->medicines; }", "public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args):array { if(str_contains($this->sql,'system_settings')||str_contains($this->sql,'GROUP BY medicine_id'))return [];return $this->db->medicines; }")
p.write_text(s)
