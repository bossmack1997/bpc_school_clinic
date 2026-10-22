<?php
require_once __DIR__ . '/../config/config.php';
require_inventory_access();
require_once __DIR__ . '/../includes/medicine_inventory_service.php';
$db = getDB(); $user = current_user();
$service = new MedicineInventoryService($db);
$low = MedicineInventoryService::requisitionItems($service->snapshot());
$hash = MedicineInventoryService::fingerprint($low);
$token = $_POST['token'] ?? $_GET['draft'] ?? '';
if (!is_string($token)) $token = '';
$drafts = &$_SESSION['medicine_requisitions'];
if (!is_array($drafts)) $drafts = [];
// Reopening the same current inventory reuses its session draft and reference.
if (!$token) {
    foreach (array_reverse($drafts, true) as $existingToken => $existing) {
        if (($existing['owner'] ?? null) === (int)$user['id'] && ($existing['hash'] ?? '') === $hash
            && ($existing['created'] ?? 0) > time() - 14400) { $token = $existingToken; break; }
    }
}
if (!$token || !isset($drafts[$token]) || ($drafts[$token]['owner'] ?? null) !== (int)$user['id']) {
    $token = bin2hex(random_bytes(16));
    if (count($drafts) >= 10) array_shift($drafts);
    $drafts[$token] = ['owner' => (int)$user['id'], 'created' => time(), 'reference' => 'BPC-MR-' . date('Ymd') . '-' . strtoupper(substr($token, 0, 10)), 'hash' => $hash, 'quantities' => [], 'confirmed' => false, 'prepared' => date('Y-m-d H:i:s')];
}
$draft = &$drafts[$token]; $error = ''; $final = false; $posted = []; $selectedIds = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $posted = $_POST['quantity'] ?? [];
    $selectedIds = $_POST['selected'] ?? [];
    try {
        if (!is_array($posted) || !is_array($selectedIds)) throw new InvalidArgumentException('Invalid draft.');
        $selected = array_intersect_key($posted, array_flip(array_filter($selectedIds, 'is_scalar')));
        $quantities = MedicineInventoryService::quantities($selected, $low);
        if (!hash_equals($draft['hash'], $hash)) {
            $draft['hash'] = $hash; $draft['confirmed'] = false; $draft['quantities'] = $quantities;
            throw new InvalidArgumentException('Stock levels changed since this draft was opened. Review the updated values and confirm again.');
        }
        $same = $draft['confirmed'] && $draft['quantities'] === $quantities;
        $draft['quantities'] = $quantities; $draft['confirmed'] = true;
        $draft['confirmed_hash'] = MedicineInventoryService::fingerprint(array_intersect_key($low, $quantities));
        $draft['revision'] = hash('sha256', $draft['confirmed_hash'] . json_encode($quantities));
        if (!$same) log_activity($user['id'], 'Confirmed medicine requisition draft', 'inventory', $draft['reference'] . ': ' . json_encode($quantities));
        $final = true;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); if (!is_array($selectedIds)) $selectedIds = []; if (!is_array($posted)) $posted = []; }
}
$config = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('school_name','clinic_name','school_address','clinic_contact')")->fetchAll(PDO::FETCH_KEY_PAIR);
$preparedBy = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$rows = $final ? array_intersect_key($low, $draft['quantities']) : $low;
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><title>Medicine Requisition - <?= e($draft['reference']) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f5;color:#111;font:14px Arial,sans-serif}.sheet{max-width:1000px;margin:24px auto;padding:32px;background:white}header{text-align:center;border-bottom:2px solid #111;padding-bottom:16px}h1{font-size:20px}h2{font-size:17px}p{line-height:1.5}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:20px 0}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;margin:16px 0}th,td{border:1px solid #777;padding:9px;text-align:left;vertical-align:top;overflow-wrap:anywhere}th{background:#eee}input[type=number]{width:100px;padding:8px}.actions{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}button,.button{padding:10px 14px;background:#0b6b3a;color:white;border:0;border-radius:6px;cursor:pointer;text-decoration:none}button:disabled{opacity:.5;cursor:not-allowed}.error{padding:12px;border:1px solid #b00;color:#900}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:36px;break-inside:avoid}.note{font-size:12px;color:#444}thead{display:table-header-group}tr{break-inside:avoid}
@media(max-width:600px){.sheet{margin:0;padding:16px}.meta{grid-template-columns:1fr}.signatures{grid-template-columns:1fr}}
@page{size:A4 portrait;margin:15mm}
@media print{body{background:white;font-size:10pt}.sheet{margin:0;padding:0;max-width:none}.no-print{display:none!important}.table-wrap{overflow:visible}table{table-layout:fixed;font-size:9pt}th,td{padding:5px}.meta{grid-template-columns:1fr 1fr}.signatures{grid-template-columns:1fr 1fr}a{color:#111}}
</style></head><body><main class="sheet">
<header><strong><?= e($config['school_name'] ?? 'BULACAN POLYTECHNIC COLLEGE') ?></strong><h2><?= e($config['clinic_name'] ?? 'BPC SCHOOL CLINIC') ?></h2>
<div><?= e($config['school_address'] ?? '') ?> <?= e($config['clinic_contact'] ?? '') ?></div><h1>MEDICINE REQUISITION FORM</h1></header>
<div class="meta"><div>Reference: <strong><?= e($draft['reference']) ?></strong></div><div>Date Prepared: <?= e($draft['prepared']) ?></div><div>Requesting Department: School Clinic</div><div>Prepared By: <?= e($preparedBy) ?></div><div>Purpose: Medicine Inventory Replenishment</div><div>Status: <?= $final ? 'Draft - quantities confirmed; pending review and approval' : 'DRAFT - quantities require confirmation' ?></div></div>
<?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (!$final): ?><p class="no-print">Only currently low-stock or out-of-stock inventory records appear. Expired/unusable quantities are excluded. PDF, Word, and print are enabled after you confirm the preview. No target stock level is configured unless shown as a proposed quantity. Enter quantities and uncheck items to remove them. Distinct batches retain their inventory IDs. This does not place an order.</p><?php endif; ?>
<form method="post" action="medicine_requisition.php?draft=<?= e($token) ?>">
<?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
<div class="table-wrap"><table><thead><tr><?php if (!$final): ?><th class="no-print">Include</th><?php endif; ?><th style="width:6%">Item</th><th>Medicine Name</th><th style="width:9%">Unit</th><th style="width:10%">Current Stock</th><th style="width:10%">Minimum Stock</th><th style="width:13%">Quantity Requested</th><th>Remarks</th></tr></thead><tbody>
<?php $i=0; foreach ($rows as $id => $m): ?><tr>
<?php if (!$final): ?><td class="no-print"><input type="checkbox" name="selected[]" value="<?= (int)$id ?>" <?= $selectedIds === null || in_array((string)$id, array_map('strval', array_filter($selectedIds, 'is_scalar')), true) ? 'checked' : '' ?> aria-label="Include <?= e($m['medicine_name']) ?>"></td><?php endif; ?>
<td><?= ++$i ?></td><td><?= e($m['medicine_name']) ?><br><small>Generic: <?= e($m['generic_name']??'Not specified') ?> / Brand: <?= e($m['brand']??'Not specified') ?><br>ID <?= (int)$id ?><?= !empty($m['batch_number']) ? ' / Batch ' . e($m['batch_number']) : '' ?></small></td><td><?= e($m['unit']) ?></td><td><?= (int)$m['usable_stock'] ?></td><td><?= $m['reorder_level'] === null ? 'Not configured' : (int)$m['reorder_level'] ?></td><td>
<?php if ($final): ?><?= (int)$draft['quantities'][$id] ?><?php else: ?><input type="number" name="quantity[<?= (int)$id ?>]" min="1" max="9999999" step="1" value="<?= e((string)(isset($posted[$id]) && is_scalar($posted[$id]) ? $posted[$id] : ($draft['quantities'][$id] ?? $m['suggested_quantity'] ?? ''))) ?>" aria-label="Quantity requested for <?= e($m['medicine_name']) ?>"><small>Suggested: <?= isset($m['suggested_quantity'])?(int)$m['suggested_quantity']:'Review required' ?>. Review before confirming.</small><?php endif; ?>
</td><td><?= e($m['stock_status'] . '. ' . $m['remarks']) ?><br><small>Supplier: <?= e($m['supplier']?:'Not specified') ?></small></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8">No medicines currently require replenishment.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="actions no-print"><?php if (!$final && $rows): ?><button type="submit">Confirm list and preview</button><?php endif; ?><button type="button" data-download="pdf" <?= $final && $rows ? '' : 'disabled' ?>>Download PDF</button><button type="button" data-download="docx" <?= $final && $rows ? '' : 'disabled' ?>>Download Word (.docx)</button><button type="button" id="printRequisition" <?= $final && $rows ? '' : 'disabled' ?>>Print Requisition</button><?php if ($final): ?><a class="button" href="?draft=<?= e($token) ?>">Edit draft</a><?php endif; ?><a class="button" href="<?= APP_URL ?>/pages/<?= e($user['role']) ?>/inventory.php">Manage Medicine Stocks</a></div></form>
<p class="note">Usable stock verified at <?= e(date('Y-m-d H:i:s')) ?>. Quantities are staff requests, not approved purchase orders. Approval and signatures must be completed by authorized clinic personnel.</p>
<div class="signatures"><div>Prepared By: <?= e($preparedBy) ?><p>Signature: ____________________</p></div><div>Reviewed By (Clinic Nurse): ____________________<p>Signature: ____________________</p></div><div>Approved By (Authorized Administrator): ____________________<p>Signature: ____________________</p></div><div>Date: ____________________</div></div>
<p id="printStatus" class="no-print" role="status"></p>
</main>
<script>
document.querySelectorAll('[data-download]').forEach(function (downloadButton) {
  downloadButton.addEventListener('click', async function () {
    downloadButton.disabled = true;
    var status = document.getElementById('printStatus');
    status.textContent = 'Generating document...';
    try {
      var data = new FormData();
      data.append('format', downloadButton.dataset.download);
      data.append('token', <?= json_encode($token) ?>);
      data.append('revision', <?= json_encode($draft['revision'] ?? '') ?>);
      data.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
      var response = await fetch(<?= json_encode(APP_URL . '/api/medicine_requisition_download.php') ?>, {method:'POST', body:data, credentials:'same-origin'});
      var expected = downloadButton.dataset.download === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
      if (!response.ok || !(response.headers.get('Content-Type') || '').includes(expected)) {
        var error = await response.json();
        throw new Error(error.message || 'Unable to generate this document.');
      }
      var blob = await response.blob();
      var url = URL.createObjectURL(blob);
      var link = document.createElement('a');
      link.href = url;
      var disposition = response.headers.get('Content-Disposition') || '';
      var match = disposition.match(/filename="([^"]+)"/);
      link.download = match ? match[1] : 'BPC_Medicine_Requisition.' + downloadButton.dataset.download;
      document.body.appendChild(link); link.click(); link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
      status.textContent = 'Document downloaded. Requisition remains a draft pending approval.';
    } catch (error) { status.textContent = error.message; }
    finally { downloadButton.disabled = false; }
  });
});
var button = document.getElementById('printRequisition');
if (button) button.addEventListener('click', async function () {
  button.disabled = true;
  try {
    var data = new FormData(); data.append('action','validate'); data.append('token', <?= json_encode($token) ?>); data.append('revision', <?= json_encode($draft['revision'] ?? '') ?>); data.append('csrf_token',document.querySelector('meta[name="csrf-token"]').content);
    var response = await fetch(<?= json_encode(APP_URL . '/api/mica_inventory.php') ?>, {method:'POST',body:data,credentials:'same-origin'});
    var body = await response.json();
    if (!response.ok || !body.success) throw new Error(body.message || 'Stock verification failed.');
    window.print();
  } catch (error) { document.getElementById('printStatus').textContent=error.message; }
  finally { button.disabled=false; }
});
</script><?php include __DIR__ . '/../components/global_sos.php'; ?>
</body></html>
