<?php
/** Deterministic inventory facts shared by MICA, scheduled checks and requisitions. */
final class MedicineInventoryService
{
    public function __construct(private PDO $db) {}

    public static function classify(array $m, string $today): array
    {
        $expired = !empty($m['expiration_date']) && $m['expiration_date'] < $today;
        $unusable = $expired || !empty($m['is_quarantined'])
            || (isset($m['is_available']) && !$m['is_available'])
            || in_array(strtolower((string)($m['status'] ?? '')), ['quarantined','unusable','recalled'], true);
        $m['usable_stock'] = $unusable ? 0 : max(0, (int)$m['quantity']);
        $threshold = isset($m['reorder_level']) && is_numeric($m['reorder_level']) && $m['reorder_level'] >= 0
            ? (int)$m['reorder_level'] : null;
        $m['reorder_level'] = $threshold;
        $m['stock_status'] = $m['usable_stock'] === 0 ? 'OUT OF STOCK'
            : ($threshold === null ? 'THRESHOLD REQUIRED' : ($m['usable_stock'] <= $threshold ? 'LOW STOCK' : 'AVAILABLE'));
        $m['remarks'] = $expired ? 'Expired stock excluded' : ($unusable ? 'Unusable stock excluded' : 'Inventory replenishment');
        $target = isset($m['target_stock_level']) && is_numeric($m['target_stock_level']) ? (int)$m['target_stock_level'] : null;
        $m['suggested_quantity'] = $target === null ? null : max(0, $target - $m['usable_stock']);
        return $m;
    }

    public function policy(): array {
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

    public static function low(array $items): array
    {
        return array_filter($items, fn($m) => in_array($m['stock_status'], ['LOW STOCK','OUT OF STOCK'], true));
    }

    public static function requisitionItems(array $items): array
    {
        return array_filter(self::low($items), fn($m) => $m['reorder_level'] !== null && $m['usable_stock'] <= $m['reorder_level']);
    }

    /** Validate the exact preview revision against fresh inventory before any export. */
    public static function verifiedDraft(array $draft, array $items, int $owner, string $revision): array
    {
        if (($draft['owner'] ?? null) !== $owner || empty($draft['confirmed'])) {
            throw new InvalidArgumentException('Review and confirm the requisition preview before downloading.');
        }
        if ($revision === '' || !hash_equals($draft['revision'] ?? '', $revision)) {
            throw new InvalidArgumentException('This preview has changed. Reopen it and confirm the current draft.');
        }
        $low = self::requisitionItems($items);
        $quantities = self::quantities($draft['quantities'] ?? [], $low);
        $selected = array_intersect_key($low, $quantities);
        if (!hash_equals($draft['confirmed_hash'] ?? '', self::fingerprint($selected))) {
            throw new InvalidArgumentException('Inventory changed. Review the updated stock and confirm the draft again.');
        }
        foreach ($selected as $id => &$item) $item['requested_quantity'] = $quantities[$id];
        unset($item);
        return array_values($selected);
    }

    public static function fingerprint(array $items): string
    {
        $facts = [];
        foreach ($items as $id => $m) $facts[$id] = [$m['medicine_name'], $m['quantity'], $m['usable_stock'], $m['unit'], $m['reorder_level'], $m['expiration_date'] ?? null, $m['stock_status'], $m['batch_number'] ?? null, $m['suggested_quantity'], $m['generic_name']??null,$m['brand']??null,$m['supplier']??null,$m['quantity_rule']??null];
        ksort($facts);
        return hash('sha256', json_encode($facts));
    }

    public function monitor(): array
    {
        $lock = $this->db->query("SELECT GET_LOCK('bpc_mica_stock_monitor', 2)")->fetchColumn();
        if ((int)$lock !== 1) return ['notified' => 0];
        try {
            $this->db->beginTransaction();
            $items = $this->snapshot();
            $get = $this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $save = $this->db->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $notify = $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, link) SELECT id, 'mica_inventory', ?, ?, ? FROM users WHERE status='active' AND (role='nurse' OR (role='admin' AND admin_type='co_admin' AND EXISTS(SELECT 1 FROM admin_permissions ap WHERE ap.user_id=users.id AND ap.permission_key='inventory_oversight')))");
            $count = 0;
            foreach ($items as $id => $m) {
                $key = 'mica_stock_state_' . $id;
                $get->execute([$key]);
                $previous = json_decode((string)$get->fetchColumn(), true);
                $expiry=!empty($m['expiration_date'])?($m['expiration_date']<date('Y-m-d')?'expired':($m['expiration_date']<=date('Y-m-d',strtotime('+30 days'))?'near_expiry':'normal')):'normal';
                $state = ['quantity' => $m['usable_stock'], 'status' => $m['stock_status'], 'threshold' => $m['reorder_level'], 'expiry'=>$expiry];
                $low = in_array($state['status'], ['LOW STOCK','OUT OF STOCK'], true);
                if (($expiry!=='normal'&&$expiry!==($previous['expiry']??'normal')) || ($low && (!$previous || !in_array($previous['status'], ['LOW STOCK','OUT OF STOCK'], true)
                    || $state['quantity'] < $previous['quantity'] || $state['status'] !== $previous['status'] || $state['threshold'] !== ($previous['threshold'] ?? null)))) {
                    $message = mb_substr($m['medicine_name'] . ': ' . $m['usable_stock'] . ' ' . $m['unit'] . ' usable. ' . $m['stock_status'] . ($expiry!=='normal'?' Expiry alert: '.$m['expiration_date'].'.':'') . '. Review in MICA or generate a requisition.', 0, 255);
                    $notify->execute(['Medicine Inventory Alert', $message, APP_URL . '/pages/medicine_requisition.php']);
                    $count++;
                }
                $save->execute([$key, json_encode($state)]);
            }
            $this->db->commit();
            return ['count' => count(self::low($items)), 'notified' => $count, 'reorders_created' => 0];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('bpc_mica_stock_monitor')");
        }
    }

    public function lockForDispensing(int $id,int $quantity): array {
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

    public static function quantities(array $posted, array $low): array
    {
        $selected = [];
        foreach ($posted as $id => $value) {
            if (!ctype_digit((string)$id) || !isset($low[(int)$id])) throw new InvalidArgumentException('A selected medicine is no longer low stock. Refresh the draft.');
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$value)) throw new InvalidArgumentException('Enter a whole requested quantity from 1 to 9999999 for every selected item.');
            $selected[(int)$id] = (int)$value;
        }
        if (!$selected) throw new InvalidArgumentException('Select at least one low-stock medicine.');
        return $selected;
    }
}
