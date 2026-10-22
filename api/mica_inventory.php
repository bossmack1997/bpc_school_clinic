<?php
require_once __DIR__ . '/../config/config.php';
require_inventory_access();
require_once __DIR__ . '/../includes/medicine_inventory_service.php';
header('Cache-Control: no-store, private');
set_exception_handler(function(Throwable $e){if(getDB()->inTransaction())getDB()->rollBack();error_log('MICA inventory: '.$e->getMessage());http_response_code(500);json_response(false,[],'Inventory could not be retrieved. Please retry.');});
$db = getDB();
$service = new MedicineInventoryService($db);
$action = $_GET['action'] ?? $_POST['action'] ?? 'snapshot';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(!csrf_verify($_POST['csrf_token']??'')){http_response_code(403);json_response(false,[],'Security token expired. Refresh and try again.');}
    if ($action === 'check') $service->monitor();
    if ($action === 'read') {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = 'mica_inventory'");
        $stmt->execute([current_user()['id']]);
    }
    if ($action === 'validate') {
        $token = $_POST['token'] ?? '';
        $draft = is_string($token) ? ($_SESSION['medicine_requisitions'][$token] ?? null) : null;
        try {
            $revision = $_POST['revision'] ?? '';
            if (!is_string($revision)) throw new InvalidArgumentException('Invalid preview revision.');
            MedicineInventoryService::verifiedDraft($draft ?? [], $service->snapshot(), (int)current_user()['id'], $revision);
            $valid = true;
        } catch (InvalidArgumentException $e) { $valid = false; }
        json_response((bool)$valid, [], $valid ? 'Verified current stock.' : 'Stock changed. Reopen the requisition draft and confirm again.');
    }
} elseif ($action !== 'snapshot') {
    http_response_code(405);
    json_response(false, [], 'Use a CSRF-protected POST request.');
}
$items = $service->snapshot();
$low = MedicineInventoryService::low($items);
$public = array_map(fn($m) => array_intersect_key($m, array_flip(['id','medicine_name','unit','usable_stock','reorder_level','stock_status','remarks','suggested_quantity','expiration_date','generic_name','brand','supplier','quantity_rule','usage_in_lookback'])), $items);
json_response(true, ['items' => array_values($public), 'low_count' => count(array_filter($low, fn($m) => $m['stock_status'] === 'LOW STOCK')), 'out_count' => count(array_filter($low, fn($m) => $m['stock_status'] === 'OUT OF STOCK'))]);
