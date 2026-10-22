<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/medicine_inventory_service.php';
$db=getDB();
$service=new MedicineInventoryService($db);
$items=$service->snapshot();
$low=MedicineInventoryService::low($items);
foreach($low as $m) {
 if ($m['usable_stock']>0 && ($m['reorder_level']===null || $m['usable_stock']>$m['reorder_level'])) throw new RuntimeException('Invalid low stock membership');
}
$db->query("SELECT setting_key, setting_value FROM system_settings LIMIT 0");
$db->query("SELECT user_id, type, title, message, link, is_read FROM notifications LIMIT 0");
echo "Read-only database integration PASS: ".count($items)." active medicine IDs; ".count($low)." low/out records; notification/settings columns available.\n";
