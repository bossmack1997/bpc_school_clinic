from pathlib import Path
p=Path('includes/medicine_inventory_service.php');s=p.read_text();a=s.index('    public function snapshot(): array');b=s.index('    public static function low(',a)
s=s[:a]+'''    public function policy(): array {
        $policy=['lookback_days'=>30,'coverage_days'=>30,'safety_days'=>7,'threshold_multiplier'=>2];
        $q=$this->db->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key LIKE 'inventory_rule_%'");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$key=substr($row['setting_key'],15);if(isset($policy[$key])&&ctype_digit((string)$row['setting_value'])&&$row['setting_value']>0&&$row['setting_value']<=365)$policy[$key]=(int)$row['setting_value'];}
        return $policy;
    }
    public static function propose(array $m,int $used,array $policy): array {
        $usageTarget=(int)ceil(max(0,$used)/$policy['lookback_days']*($policy['coverage_days']+$policy['safety_days']));
        $minimum=$m['reorder_level'];
        $target=isset($m['target_stock_level'])?(int)$m['target_stock_level']:($minimum===null?null:max($minimum*$policy['threshold_multiplier'],$usageTarget));
        $m['usage_in_lookback']=$used;$m['target_stock_level']=$target;
        $m['suggested_quantity']=$target===null?null:max(0,$target-$m['usable_stock']);
        $m['quantity_rule']='Maximum of minimum stock x '.$policy['threshold_multiplier'].' and '.$policy['lookback_days'].'-day dispensing rate x '.($policy['coverage_days']+$policy['safety_days']).' days, less usable stock';
        return $m;
    }
    public static function displayStatus(array $m,string $today): string {
        if(!empty($m['expiration_date'])&&$m['expiration_date']<$today)return 'Expired';
        if($m['stock_status']==='OUT OF STOCK')return $m['quantity']>0?'Unavailable':'Out of Stock';
        if($m['stock_status']==='LOW STOCK')return 'Low Stock';
        if(!empty($m['expiration_date'])&&$m['expiration_date']<=date('Y-m-d',strtotime($today.' +30 days')))return 'Near Expiration';
        return $m['stock_status']==='THRESHOLD REQUIRED'?'Threshold Required':'Available';
    }
    public function snapshot(): array {
        // Every existing ID is a distinct batch; no name-based pooling or merging.
        $rows=$this->db->query("SELECT * FROM medicines WHERE is_archived=0 ORDER BY medicine_name,id")->fetchAll(PDO::FETCH_ASSOC);
        $today=(string)$this->db->query('SELECT CURDATE()')->fetchColumn();$policy=$this->policy();
        $q=$this->db->prepare("SELECT medicine_id,COALESCE(SUM(quantity),0) AS used FROM medicine_logs WHERE action='stock_out' AND performed_at>=DATE_SUB(CURDATE(),INTERVAL ? DAY) GROUP BY medicine_id");
        $q->execute([$policy['lookback_days']]);$usage=$q->fetchAll(PDO::FETCH_KEY_PAIR);$items=[];
        foreach($rows as $m){$m=self::propose(self::classify($m,$today),(int)($usage[$m['id']]??0),$policy);$m['display_status']=self::displayStatus($m,$today);$items[(int)$m['id']]=$m;}
        return $items;
    }
    public static function filter(array $items,array $input,string $today): array {
        $term=mb_strtolower(trim((string)($input['search']??'')));$status=$input['status']??'';$expiry=$input['expiry']??'';
        return array_filter($items,function($m)use($term,$status,$expiry,$input,$today){
            if(($input['category']??'')!==''&&$m['category']!==$input['category'])return false;
            if($term!==''&&!str_contains(mb_strtolower(implode(' ',array_map('strval',array_intersect_key($m,array_flip(['medicine_name','generic_name','brand','supplier','category','batch_number','barcode']))))),$term))return false;
            $near=!empty($m['expiration_date'])&&$m['expiration_date']>=$today&&$m['expiration_date']<=date('Y-m-d',strtotime($today.' +30 days'))&&(int)$m['quantity']>0;
            $map=['available'=>'Available','low_stock'=>'Low Stock','out_of_stock'=>'Out of Stock','expired'=>'Expired'];
            if(isset($map[$status])&&$m['display_status']!==$map[$status])return false;
            if(in_array($status,['near_expiration','expiring_soon'],true)&&!$near)return false;
            if($expiry==='expiring30'&&!$near)return false;
            if($expiry==='expired'&&$m['display_status']!=='Expired')return false;
            return true;
        });
    }

'''+s[b:]
s=s.replace("WHERE role IN ('admin','nurse') AND status = 'active'", "WHERE status='active' AND (role='nurse' OR (role='admin' AND admin_type='co_admin' AND EXISTS(SELECT 1 FROM admin_permissions ap WHERE ap.user_id=users.id AND ap.permission_key='inventory_oversight')))")
s=s.replace("$state = ['quantity' => $m['usable_stock'], 'status' => $m['stock_status'], 'threshold' => $m['reorder_level']];", "$expiry=!empty($m['expiration_date'])?($m['expiration_date']<date('Y-m-d')?'expired':($m['expiration_date']<=date('Y-m-d',strtotime('+30 days'))?'near_expiry':'normal')):'normal';\n                $state = ['quantity' => $m['usable_stock'], 'status' => $m['stock_status'], 'threshold' => $m['reorder_level'], 'expiry'=>$expiry];")
s=s.replace("if ($low && (!$previous", "if (($expiry!=='normal'&&$expiry!==($previous['expiry']??'normal')) || ($low && (!$previous")
s=s.replace("($previous['threshold'] ?? null))) {", "($previous['threshold'] ?? null)))) {")
s=s.replace(". '. Review in MICA", ". ($expiry!=='normal'?' Expiry alert: '.$m['expiration_date'].'.':'') . '. Review in MICA")
s=s.replace("$m['suggested_quantity']];", "$m['suggested_quantity'], $m['generic_name']??null,$m['brand']??null,$m['supplier']??null,$m['quantity_rule']??null];")
p.write_text(s)
p=Path('api/inventory.php');s=p.read_text();a=s.index("    $cat =",s.index("if ($action === 'list')"));b=s.index('// ================================================================\n// STATS',a)
s=s[:a]+'''    $service=new MedicineInventoryService($db);$items=$service->snapshot();$today=(string)$db->query('SELECT CURDATE()')->fetchColumn();
    $matched=array_values(MedicineInventoryService::filter($items,$_GET,$today));
    $total=count($matched);$offset=max(0,(int)($_GET['offset']??0));$limit=max(1,min(200,(int)($_GET['limit']??50)));
    $rows=array_slice($matched,$offset,$limit);foreach($rows as &$m)$m['status']=$m['display_status'];unset($m);
    json_response(true,['data'=>$rows,'total'=>$total]);
}

'''+s[b:]
a=s.index("    $total =",s.index("if ($action === 'stats')"));b=s.index('// "Dispensed Today"',a)
s=s[:a]+'''    $items=(new MedicineInventoryService($db))->snapshot();$today=(string)$db->query('SELECT CURDATE()')->fetchColumn();
    $total=count($items);$categories=count(array_unique(array_filter(array_column($items,'category'))));
    $count=fn($status)=>count(MedicineInventoryService::filter($items,['status'=>$status],$today));
    $avail=$count('available');$low=$count('low_stock');$expired=$count('expired');$nearExp=$count('near_expiration');$outOfStock=$count('out_of_stock');

'''+s[b:]
pos=s.index('// ================================================================\n// Helper:');s=s[:pos]+'''if($action==='policy')json_response(true,['policy'=>(new MedicineInventoryService($db))->policy()]);
if($action==='save_policy'){
    csrf_verify_or_die();$policy=[];
    foreach(['lookback_days','coverage_days','safety_days','threshold_multiplier'] as $key){$value=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>365]]);if($value===false){http_response_code(422);json_response(false,[],'Enter inventory rule values from 1 to 365.');}$policy[$key]=$value;}
    $db->beginTransaction();$q=$db->prepare('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach($policy as $key=>$value)$q->execute(['inventory_rule_'.$key,(string)$value]);log_activity($user['id'],'Updated restock draft rules','inventory');$db->commit();json_response(true,['policy'=>$policy],'Restock draft rules saved.');
}

'''+s[pos:];p.write_text(s)
