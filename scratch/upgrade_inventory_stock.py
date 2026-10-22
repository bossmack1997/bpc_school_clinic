from pathlib import Path
p=Path('includes/medicine_inventory_service.php');s=p.read_text();pos=s.index('    public static function quantities(');s=s[:pos]+'''    public function lockForDispensing(int $id,int $quantity): array {
        if(!$this->db->inTransaction())throw new LogicException('Dispensing requires an active transaction.');
        if($quantity<=0)throw new InvalidArgumentException('Enter a positive whole dispensing quantity.');
        $q=$this->db->prepare('SELECT * FROM medicines WHERE id=? AND is_archived=0 FOR UPDATE');$q->execute([$id]);$m=$q->fetch(PDO::FETCH_ASSOC);
        if(!$m)throw new InvalidArgumentException('Medicine not found.');
        $today=(string)$this->db->query('SELECT CURDATE()')->fetchColumn();$facts=self::classify($m,$today);
        if($facts['usable_stock']<$quantity)throw new InvalidArgumentException('Insufficient usable stock. Expired, recalled or unavailable medicine cannot be dispensed.');
        return $m;
    }
    public function adjust(int $id,string $type,int $quantity,string $reason,int $actor): int {
        if(!in_array($type,['stock_in','stock_out','adjustment'],true)||$quantity<0||($type!=='adjustment'&&$quantity===0))throw new InvalidArgumentException('Enter a valid stock action and non-negative whole quantity.');
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT quantity FROM medicines WHERE id=? AND is_archived=0 FOR UPDATE');$q->execute([$id]);$before=$q->fetchColumn();
            if($before===false)throw new InvalidArgumentException('Medicine not found.');
            $after=$type==='adjustment'?$quantity:(int)$before+($type==='stock_in'?$quantity:-$quantity);
            if($after<0||$after>2147483647)throw new InvalidArgumentException('The stock adjustment exceeds the available quantity or storage limit.');
            $this->db->prepare('UPDATE medicines SET quantity=? WHERE id=?')->execute([$after,$id]);
            $this->db->prepare('INSERT INTO inventory_logs (medicine_id,change_type,quantity_change,remarks,logged_by) VALUES (?,?,?,?,?)')->execute([$id,$type==='adjustment'?'adjustment':($type==='stock_in'?'in':'out'),$after-(int)$before,$reason,$actor]);
            $this->db->prepare('INSERT INTO activity_logs (user_id,action,module,details) VALUES (?,?,?,?)')->execute([$actor,'Medicine stock adjusted','inventory','Medicine #'.$id.'; '.$before.' -> '.$after]);
            if(function_exists('update_medicine_status'))update_medicine_status($this->db,$id);
            if($own)$this->db->commit();return $after;
        }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

'''+s[pos:];p.write_text(s)
p=Path('api/dispensing.php');s=p.read_text().replace("if($user['role']==='admin'&&!is_primary_admin($user))", "if($user['role']==='admin')",1)
s=s.replace("if ($action === 'dispense') {\n    csrf_verify_or_die();", "if ($action === 'dispense') {\n    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);json_response(false,[],'Use POST.');}\n    csrf_verify_or_die();\n    require_once __DIR__.'/../includes/medicine_inventory_service.php';")
a=s.index('    $stmt = $db->prepare("SELECT id, medicine_name',s.index("if ($action === 'dispense')"));b=s.index('        $newQty =',a)
s=s[:a]+'''    $db->beginTransaction();
    try {
        $medicine=(new MedicineInventoryService($db))->lockForDispensing($medicineId,$quantity);
'''+s[b:]
a=s.index('        // Low stock notification');b=s.index('        // Log in medicine_logs',a);s=s[:a]+s[b:]
a=s.index('        // Notify the student');b=s.index("        json_response(true",a);s=s[:a]+'''        $db->commit();
        try{notify_student_dispense($db,$studentId,$medicine['medicine_name'],$quantity);(new MedicineInventoryService($db))->monitor();}catch(Throwable $e){error_log('Post-dispensing alert: '.$e->getMessage());}
'''+s[b:]
s=s.replace("        $db->rollBack();\n        json_response(false, [], safe_api_error($e, 'The medicine could not be dispensed.", "        if($db->inTransaction())$db->rollBack();\n        if($e instanceof InvalidArgumentException){http_response_code(422);json_response(false,[],$e->getMessage());}\n        json_response(false, [], safe_api_error($e, 'The medicine could not be dispensed.")
p.write_text(s)
p=Path('api/inventory.php');s=p.read_text();s=s.replace("http_response_code(500);json_response(false,[],'The inventory request", "if($e instanceof InvalidArgumentException){http_response_code(422);json_response(false,[],$e->getMessage());}http_response_code(500);json_response(false,[],'The inventory request",1)
a=s.index("    csrf_verify_or_die();",s.index("if ($action === 'stock_update')"));b=s.index('// ================================================================\n// DELETE',a)
s=s[:a]+'''    csrf_verify_or_die();
    $quantity=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT);
    if($quantity===false)throw new InvalidArgumentException('Enter a whole stock quantity.');
    $new=(new MedicineInventoryService($db))->adjust((int)($_POST['id']??0),(string)($_POST['type']??''),$quantity,trim((string)($_POST['reason']??'')),(int)$user['id']);
    json_response(true,['new_quantity'=>$new],'Stock updated.');
}

'''+s[b:]
a=s.index('    $stmt = $db->prepare("UPDATE medicines SET quantity = GREATEST');b=s.index('\n}',a)
s=s[:a]+'''    $db->beginTransaction();$service=new MedicineInventoryService($db);$safe=array_values(array_unique($safe));sort($safe);
    foreach($safe as $id)$service->adjust($id,$delta>0?'stock_in':'stock_out',abs($delta),'Bulk stock adjustment',(int)$user['id']);
    $db->commit();json_response(true,['count'=>count($safe)],'Stock adjusted.');'''+s[b:]
# Preserve all records/history: both old "hard" and bulk-delete callers archive instead.
s=s.replace('$db->prepare("DELETE FROM medicines WHERE id = ?")', '$db->prepare("UPDATE medicines SET is_archived=1 WHERE id = ?")').replace('$db->prepare("DELETE FROM medicines WHERE id IN ($ph)")', '$db->prepare("UPDATE medicines SET is_archived=1 WHERE id IN ($ph)")')
p.write_text(s)
p=Path('api/walkin.php');s=p.read_text();needle="$mStmt = $db->prepare('SELECT medicine_name, quantity FROM medicines WHERE id = ? AND is_archived = 0 AND (expiration_date IS NULL OR expiration_date >= CURDATE()) FOR UPDATE');";s=s.replace(needle,"$mStmt = $db->prepare(\"SELECT medicine_name, quantity FROM medicines WHERE id = ? AND is_archived = 0 AND (expiration_date IS NULL OR expiration_date >= CURDATE()) AND status NOT IN ('quarantined','unusable','recalled') FOR UPDATE\");");p.write_text(s)
