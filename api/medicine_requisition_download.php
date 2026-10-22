<?php
require_once __DIR__ . '/../config/config.php';
require_inventory_access();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST'); json_response(false, [], 'Use the confirmed requisition preview to download.');
}
if(!csrf_verify($_POST['csrf_token']??'')){http_response_code(403);json_response(false,[],'Your security token expired. Refresh and try again.');}
require_once __DIR__ . '/../includes/medicine_inventory_service.php';
require_once __DIR__ . '/../includes/requisition_documents.php';
$format = $_POST['format'] ?? '';
$token = $_POST['token'] ?? '';
$revision = $_POST['revision'] ?? '';
if (!in_array($format, ['pdf','docx'], true) || !is_string($token) || !is_string($revision)) {
    http_response_code(400); json_response(false, [], 'Invalid document request.');
}
$user = current_user(); $db = getDB();
try {
    $draft = $_SESSION['medicine_requisitions'][$token] ?? [];
    $rows = MedicineInventoryService::verifiedDraft($draft, (new MedicineInventoryService($db))->snapshot(), (int)$user['id'], $revision);
    $institution = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('school_name','clinic_name','school_address','clinic_contact')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $model = RequisitionDocuments::model($draft, $rows, $user, $institution);
    $bytes = $format === 'pdf' ? RequisitionDocuments::pdf($model) : RequisitionDocuments::word($model);
    log_activity($user['id'], 'Downloaded medicine requisition ' . strtoupper($format), 'inventory', $draft['reference']);
} catch (InvalidArgumentException $e) {
    http_response_code(409); json_response(false, [], $e->getMessage());
} catch(Throwable $e){error_log('Requisition export: '.$e->getMessage());http_response_code(500);json_response(false,[],'The restock document could not be generated. Retry from the confirmed preview.');}
$filename = 'BPC_Medicine_Requisition_' . date('Y-m-d') . '.' . $format;
if (ob_get_length()) ob_clean();
header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
echo $bytes;
exit;
