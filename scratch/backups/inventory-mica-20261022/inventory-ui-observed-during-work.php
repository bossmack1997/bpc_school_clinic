<?php
/**
 * includes/inventory_ui.php
 * Shared professional Medicine Inventory UI: dashboard cards, search,
 * filters, modern Add/Edit stock modal, bulk actions, CSV import preview,
 * and automatic stock alerts shown as notifications.
 */
$roleDir = $roleDir ?? 'nurse';
$canManage = ($roleDir === 'nurse'); // nurses manage; admin view-only
?>
<div class="page-enter fade-in">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h6 class="section-title mb-0"><i class="bi bi-capsule-pill text-success me-1"></i> Medicine Inventory</h6>
    <div class="d-flex flex-wrap gap-2">
      <?php if ($canManage): ?>
        <button class="btn btn-sm btn-success" id="addMedBtn"><i class="bi bi-plus-lg me-1"></i> Add Medicine</button>
        <button class="btn btn-sm btn-outline-primary" id="importMedBtn"><i class="bi bi-upload me-1"></i> Import</button>
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/api/inventory.php?action=template"><i class="bi bi-download me-1"></i> Template</a>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-primary" id="invRefreshBtn"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
    </div>
  </div>

  <!-- Dashboard Cards -->
  <div class="row g-3 mb-3" id="invStatsRow">
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--green">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-box-seam"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statTotal">0</div>
        <div class="dbg-stat__label">Total Medicines</div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--teal">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-check-circle"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statAvail">0</div>
        <div class="dbg-stat__label">Available</div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--orange">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-exclamation-triangle"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statLow">0</div>
        <div class="dbg-stat__label">Low Stock</div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--red">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-x-circle"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statExpired">0</div>
        <div class="dbg-stat__label">Expired</div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--blue">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-clock"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statNear">0</div>
        <div class="dbg-stat__label">Near Expiration</div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--red">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-box-arrow-in-down"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statOut">0</div>
        <div class="dbg-stat__label">Out of Stock</div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--teal">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-capsule"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statDispToday">0</div>
        <div class="dbg-stat__label">Dispensed Today</div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
      <div class="dbg-stat dbg-stat--green">
        <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-tags"></i></div></div>
        <div class="dbg-stat__value counter-animate" data-count="0" id="statCats">0</div>
        <div class="dbg-stat__label">Categories</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card p-3 mb-3">
    <div class="row g-2 align-items-center">
      <div class="col-md-4">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
          <input type="text" id="invSearch" class="form-control" placeholder="Search name, supplier, batch, barcode...">
        </div>
      </div>
      <div class="col-md-2">
        <select id="invCategory" class="form-select form-select-sm"><option value="">All Categories</option></select>
      </div>
      <div class="col-md-2">
        <select id="invExpiry" class="form-select form-select-sm">
          <option value="">All Expiration</option>
          <option value="expired">Expired</option>
          <option value="expiring30">Expiring within 30 days</option>
        </select>
      </div>
      <div class="col-md-2">
        <select id="invStatus" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="available">Available</option>
          <option value="low_stock">Low Stock</option>
          <option value="out_of_stock">Out of Stock</option>
          <option value="near_expiration">Near Expiration</option>
          <option value="expired">Expired</option>
        </select>
      </div>
      <div class="col-md-2">
        <span class="text-muted small" id="invCount"></span>
      </div>
      <?php if ($canManage): ?>
      <div class="col-md-2 text-md-end">
        <button class="btn btn-sm btn-outline-danger" id="bulkArchiveBtn" disabled><i class="bi bi-archive me-1"></i> Archive</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Table -->
  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="invTable" style="width:100%">
        <thead>
          <tr>
            <?php if ($canManage): ?><th style="width:36px;"><input type="checkbox" id="invSelectAll" class="form-check-input"></th><?php endif; ?>
            <th>Medicine</th>
            <th>Generic / Brand</th>
            <th>Category</th>
            <th>Dosage / Form</th>
            <th>Qty / Unit</th>
            <th>Min Stock</th>
            <th>Expiration</th>
            <th>Supplier</th>
            <th>Batch</th>
            <th>Location</th>
            <th>Status</th>
            <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- Add / Edit Medicine Modal                                    -->
<!-- ============================================================ -->
<div class="modal fade" id="medModal" tabindex="-1" aria-hidden="true" aria-labelledby="medModalTitle">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header modal-header--success">
      <h5 class="modal-title" id="medModalTitle"><?= bpc_icon('plus-circle') ?> Add Medicine</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="medForm" novalidate>
        <input type="hidden" name="id" id="medId">
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label small fw-semibold">Medicine Name *</label><input class="form-control" name="medicine_name" id="f_name" required></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Generic Name</label><input class="form-control" name="generic_name" id="f_generic"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Brand</label><input class="form-control" name="brand" id="f_brand"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Category</label><input class="form-control" name="category" id="f_category" placeholder="e.g. Analgesic"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Dosage</label><input class="form-control" name="dosage" id="f_dosage" placeholder="e.g. 500mg"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Form</label>
              <select class="form-select" name="form" id="f_form">
                <option value="">Select...</option>
                <option>Tablet</option><option>Capsule</option><option>Syrup</option><option>Injection</option>
                <option>Cream</option><option>Drops</option><option>Powder</option><option>Other</option>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Quantity *</label><input type="number" min="0" class="form-control" name="quantity" id="f_quantity" required></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Unit</label><input class="form-control" name="unit" id="f_unit" value="pcs"></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Minimum Stock</label><input type="number" min="0" class="form-control" name="reorder_level" id="f_reorder" value="20"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Supplier</label><input class="form-control" name="supplier" id="f_supplier"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Manufacturer</label><input class="form-control" name="manufacturer" id="f_manufacturer"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Batch Number</label><input class="form-control" name="batch_number" id="f_batch"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Expiration Date</label><input type="date" class="form-control" name="expiration_date" id="f_exp" min="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Storage Location</label><input class="form-control" name="storage_location" id="f_loc" placeholder="e.g. Shelf A1"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Barcode</label><input class="form-control" name="barcode" id="f_barcode"></div>
            <div class="col-12"><label class="form-label small fw-semibold">Description</label><textarea class="form-control" name="description" id="f_desc" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer border-0 justify-content-between">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i> Save Medicine</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Stock Update Modal -->
<div class="modal fade" id="stockModal" tabindex="-1" aria-hidden="true" aria-labelledby="stockModalTitle">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header modal-header--info">
      <h5 class="modal-title" id="stockModalTitle"><?= bpc_icon('box') ?> Stock Adjustment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="stockForm" novalidate>
        <input type="hidden" name="id" id="stockId">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label small fw-semibold">Action</label>
            <select class="form-select" name="type">
              <option value="stock_in">Stock In (Add)</option>
              <option value="stock_out">Stock Out (Remove)</option>
              <option value="adjustment">Set Exact Quantity</option>
            </select>
          </div>
          <div class="mb-3"><label class="form-label small fw-semibold">Quantity</label><input type="number" min="1" class="form-control" name="quantity" value="1" required></div>
          <div class="mb-3"><label class="form-label small fw-semibold">Reason</label><input class="form-control" name="reason" placeholder="Optional"></div>
        </div>
        <div class="modal-footer border-0 justify-content-between">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Apply</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true" aria-labelledby="importModalTitle">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header modal-header--info">
      <h5 class="modal-title" id="importModalTitle"><?= bpc_icon('upload') ?> Import Medicines</h5>
         <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
       </div>
      <div class="modal-body">
        <div class="alert alert-info small">Upload a CSV or Excel file. Preview is validated before import. Download the <a href="<?= APP_URL ?>/api/inventory.php?action=template">CSV template</a>.</div>
        <input type="file" id="importFile" class="form-control" accept=".csv,.xlsx,.xls">
        <div id="importSummary" class="mt-3"></div>
        <div id="importErrors" class="mt-2"></div>
        <div class="table-responsive mt-3"><table class="table table-sm table-bordered" id="importPreviewTable" style="display:none;"><thead></thead><tbody></tbody></table></div>
      </div>
      <div class="modal-footer border-0 justify-content-between">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmImportBtn" disabled><i class="bi bi-check-lg me-1"></i> Import Valid Rows</button>
      </div>
    </div>
  </div>
</div>

<script>
window.__initInventoryUI = function () {
  (function () {
  'use strict';
  function getAppBase() {
    if (typeof APP_URL !== 'undefined' && APP_URL && APP_URL !== 'http://localhost' && APP_URL !== 'http://127.0.0.1') {
      return APP_URL;
    }
    if (window.APP_URL && window.APP_URL !== 'http://localhost' && window.APP_URL !== 'http://127.0.0.1') {
      return window.APP_URL;
    }
    const path = window.location.pathname;
    const match = path.match(/^(\/[^\/]+\/)/);
    return window.location.origin + (match ? match[1] : '');
  }
  const APP = getAppBase();
  const csrf = $('meta[name="csrf-token"]').attr('content');
  const canManage = <?= $canManage ? 'true' : 'false' ?>;
  let dt = null;
  let pendingImport = [];   // validated rows ready to commit
  let allMedRows = [];      // for bulk selection

  // ============================================================
  // Status badge
  // ============================================================
  function normalizeStatusValue(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'Unknown';
    const map = {
      available: 'Available',
      low_stock: 'Low Stock',
      lowstock: 'Low Stock',
      out_of_stock: 'Out of Stock',
      outofstock: 'Out of Stock',
      expired: 'Expired',
      near_expiration: 'Near Expiration',
      nearexpiration: 'Near Expiration',
      expiring_soon: 'Near Expiration',
      expiringsoon: 'Near Expiration',
      'Expiring Soon': 'Near Expiration'
    };
    return map[raw.toLowerCase()] || raw;
  }

  function statusBadge(s) {
    const normalized = normalizeStatusValue(s);
    const map = {
      'Available': '<span class="badge bg-success">Available</span>',
      'Low Stock': '<span class="badge bg-warning text-dark">Low Stock</span>',
      'Out of Stock': '<span class="badge bg-danger">Out of Stock</span>',
      'Expired': '<span class="badge bg-dark">Expired</span>',
      'Near Expiration': '<span class="badge bg-info">Near Expiration</span>',
    };
    return map[normalized] || '<span class="badge bg-secondary">' + escapeHtml(normalized || 'Unknown') + '</span>';
  }

  // ============================================================
  // Load stats cards
  // ============================================================
  function loadStats() {
    $.get(APP + '/api/inventory.php', { action: 'stats' }, function (res) {
      const payload = res || {};
      if (!payload.success) return;
      const s = payload;
      $('#statTotal').text(Number(s.total_medicines || 0)).attr('data-count', Number(s.total_medicines || 0));
      $('#statAvail').text(Number(s.available || 0)).attr('data-count', Number(s.available || 0));
      $('#statLow').text(Number(s.low_stock || 0)).attr('data-count', Number(s.low_stock || 0));
      $('#statExpired').text(Number(s.expired || 0)).attr('data-count', Number(s.expired || 0));
      $('#statNear').text(Number(s.near_expiration || 0)).attr('data-count', Number(s.near_expiration || 0));
      $('#statOut').text(Number(s.out_of_stock || 0)).attr('data-count', Number(s.out_of_stock || 0));
      $('#statDispToday').text(Number(s.dispensed_today || 0)).attr('data-count', Number(s.dispensed_today || 0));
      $('#statCats').text(Number(s.total_categories || 0)).attr('data-count', Number(s.total_categories || 0));
    }, 'json');
  }

  // ============================================================
  // Load categories for filter
  // ============================================================
  function applyInitialFilters() {
    const params = new URLSearchParams(window.location.search);
    const status = params.get('status') || '';
    const expiry = params.get('expiry') || '';
    const category = params.get('category') || '';
    const normalizedStatus = status === 'expiring_soon' ? 'near_expiration' : status;

    if (normalizedStatus && $('#invStatus option[value="' + normalizedStatus + '"]').length) {
      $('#invStatus').val(normalizedStatus);
    }
    if (expiry && $('#invExpiry option[value="' + expiry + '"]').length) {
      $('#invExpiry').val(expiry);
    }
    if (category && $('#invCategory option[value="' + category + '"]').length) {
      $('#invCategory').val(category);
    }
  }

  function loadCategories() {
    $.get(APP + '/api/inventory.php', { action: 'categories' }, function (res) {
      if (!res.success) return;
      const $sel = $('#invCategory');
      res.data.forEach(function (c) { $sel.append('<option value="' + escapeHtml(c) + '">' + escapeHtml(c) + '</option>'); });
      applyInitialFilters();
      loadInventory();
    }, 'json');
  }

  // ============================================================
  // Load inventory table
  // ============================================================
  function loadInventory() {
    const params = {
      action: 'list',
      search: $('#invSearch').val(),
      category: $('#invCategory').val(),
      expiry: $('#invExpiry').val(),
      status: $('#invStatus').val(),
      limit: 200,
    };
    $.get(APP + '/api/inventory.php', params, function (res) {
      const payload = res || {};
      if (!payload.success) {
        $('#invCount').text('0 medicine(s)');
        if (dt) { dt.destroy(); dt = null; }
        $('#invTable').DataTable({
          data: [],
          columns: canManage ? [{ orderable:false, searchable:false }, null, null, null, null, null, null, null, null, null, null, null, { orderable:false, searchable:false }] : [null, null, null, null, null, null, null, null, null, null, null, null],
          pageLength: 10,
          order: [],
          language: { emptyTable: 'No medicine records found.' }
        });
        return;
      }

      const incomingRows = Array.isArray(payload.data)
        ? payload.data
        : (Array.isArray(payload.rows) ? payload.rows : (Array.isArray(payload.items) ? payload.items : []));
      const total = Number(payload.total ?? incomingRows.length ?? 0);
      $('#invCount').text(total + ' medicine(s)');
      allMedRows = incomingRows;

      const rows = incomingRows.map(function (m) {
        const medicine = m || {};
        const medicineName = String(medicine.medicine_name || 'Unnamed medicine');
        const generic = String(medicine.generic_name || '—');
        const brand = String(medicine.brand || '—');
        const qty = String(medicine.quantity ?? 0) + ' ' + (medicine.unit || '');
        const actions = canManage
          ? `<div class="d-flex gap-1 flex-wrap">
               <button class="btn btn-sm btn-outline-primary edit-med" data-id="${medicine.id}" title="Edit"><i class="bi bi-pencil"></i></button>
               <button class="btn btn-sm btn-outline-info stock-med" data-id="${medicine.id}" title="Stock"><i class="bi bi-box-arrow-in-up"></i></button>
               <button class="btn btn-sm btn-outline-danger del-med" data-id="${medicine.id}" title="Delete"><i class="bi bi-trash"></i></button>
             </div>`
          : '';
        const checkCell = canManage ? `<input type="checkbox" class="form-check-input inv-row-check" value="${medicine.id}">` : '';
        const row = [
          checkCell,
          `<span class="fw-semibold small">${escapeHtml(medicineName)}</span>`,
          `<span class="small">${escapeHtml(generic)}<br><span class="text-muted" style="font-size:.75rem">${escapeHtml(brand)}</span></span>`,
          `<span class="small">${escapeHtml(medicine.category || '—')}</span>`,
          `<span class="small">${escapeHtml([medicine.dosage, medicine.form].filter(Boolean).join(' · ') || '—')}</span>`,
          `<span class="small fw-semibold">${escapeHtml(qty)}</span>`,
          `<span class="small">${escapeHtml(String(medicine.reorder_level ?? 0))}</span>`,
          `<span class="small ${medicine.expiration_date && medicine.expiration_date < new Date().toISOString().slice(0,10) ? 'text-danger fw-bold' : ''}">${escapeHtml(medicine.expiration_date || '—')}</span>`,
          `<span class="small">${escapeHtml(medicine.supplier || '—')}</span>`,
          `<span class="small">${escapeHtml(medicine.batch_number || '—')}</span>`,
          `<span class="small">${escapeHtml(medicine.storage_location || '—')}</span>`,
          statusBadge(medicine.status || medicine.inventory_status),
        ];
        if (canManage) row.push(actions);
        return row;
      });

      if (dt) { dt.destroy(); }

      const cols = canManage
        ? [{ orderable:false, searchable:false }, null, null, null, null, null, null, null, null, null, null, null, { orderable:false, searchable:false }]
        : [null, null, null, null, null, null, null, null, null, null, null, null];

      dt = $('#invTable').DataTable({
        data: rows,
        columns: cols,
        pageLength: 10,
        order: [],
        language: { emptyTable: 'No medicine records found.' }
      });
    }, 'json');
  }

  $('#invSearch').on('keyup', function () { clearTimeout(window.__invT); window.__invT = setTimeout(loadInventory, 300); });
  $('#invCategory, #invExpiry, #invStatus').on('change', loadInventory);
  $('#invRefreshBtn').on('click', function () { loadInventory(); loadStats(); toastSuccess('Refreshed.'); });

  // ============================================================
  // Add / Edit
  // ============================================================
  $('#addMedBtn').on('click', function () {
    $('#medForm')[0].reset();
    $('#medId').val('');
    $('#medModalTitle').html('<i class="bi bi-plus-circle me-2"></i> Add Medicine');
    BPCModal.show('medModal');
  });

  $(document).on('click', '.edit-med', function () {
    const id = $(this).data('id');
    const m = allMedRows.find(function (x) { return x.id === id; });
    if (!m) return;
    $('#medId').val(m.id);
    $('#f_name').val(m.medicine_name); $('#f_generic').val(m.generic_name || '');
    $('#f_brand').val(m.brand || ''); $('#f_category').val(m.category || '');
    $('#f_dosage').val(m.dosage || ''); $('#f_form').val(m.form || '');
    $('#f_quantity').val(m.quantity); $('#f_unit').val(m.unit || 'pcs');
    $('#f_reorder').val(m.reorder_level); $('#f_supplier').val(m.supplier || '');
    $('#f_manufacturer').val(m.manufacturer || ''); $('#f_batch').val(m.batch_number || '');
    $('#f_exp').val(m.expiration_date || ''); $('#f_loc').val(m.storage_location || '');
    $('#f_barcode').val(m.barcode || ''); $('#f_desc').val(m.description || '');
    $('#medModalTitle').html('<i class="bi bi-pencil me-2"></i> Edit Medicine');
    BPCModal.show('medModal');
  });

  $('#medForm').on('submit', function (e) {
    e.preventDefault();
    const id = $('#medId').val();
    const action = id ? 'update' : 'add';

    // --- Frontend validation ---
    const qty = parseInt($('#f_quantity').val(), 10);
    const exp = $('#f_exp').val();
    if (isNaN(qty) || qty < 0) {
      toastError('Quantity must be a non-negative number.');
      return;
    }
    if (exp && exp < new Date().toISOString().split('T')[0]) {
      toastError('Expiration date cannot be in the past.');
      return;
    }

    $.post(APP + '/api/inventory.php', $(this).serialize() + '&action=' + action + '&csrf_token=' + csrf, function (res) {
      if (res.success) { toastSuccess(res.message); BPCModal.hide('medModal'); loadInventory(); loadStats(); }
      else toastError(res.message);
    }, 'json');
  });

  // ============================================================
  // Stock update
  // ============================================================
  $(document).on('click', '.stock-med', function () {
    $('#stockId').val($(this).data('id'));
    BPCModal.show('stockModal');
  });
  $('#stockForm').on('submit', function (e) {
    e.preventDefault();
    $.post(APP + '/api/inventory.php', $(this).serialize() + '&action=stock_update&csrf_token=' + csrf, function (res) {
      if (res.success) { toastSuccess(res.message); BPCModal.hide('stockModal'); loadInventory(); loadStats(); }
      else toastError(res.message);
    }, 'json');
  });

  // ============================================================
  // Delete
  // ============================================================
  $(document).on('click', '.del-med', function () {
    const id = $(this).data('id');
    confirmDelete(function () {
      $.post(APP + '/api/inventory.php', { action: 'delete', id, csrf_token: csrf }, function (res) {
        if (res.success) { toastSuccess(res.message); loadInventory(); loadStats(); }
        else toastError(res.message);
      }, 'json');
    });
  });

  // ============================================================
  // Bulk archive
  // ============================================================
  $('#invSelectAll').on('change', function () {
    $('.inv-row-check').prop('checked', this.checked);
    updateBulkBtn();
  });
  $(document).on('change', '.inv-row-check', function () { updateBulkBtn(); });
  function updateBulkBtn() {
    const n = $('.inv-row-check:checked').length;
    $('#bulkArchiveBtn').prop('disabled', n === 0);
  }
  $('#bulkArchiveBtn').on('click', function () {
    const ids = $('.inv-row-check:checked').map(function () { return $(this).val(); }).get();
    if (!ids.length) return;
    confirmAction('Archive ' + ids.length + ' medicine(s)?', function () {
      $.post(APP + '/api/inventory.php', { action: 'bulk_archive', ids, csrf_token: csrf }, function (res) {
        if (res.success) { toastSuccess(res.message); loadInventory(); loadStats(); }
        else toastError(res.message);
      }, 'json');
    });
  });

  // ============================================================
  // Import (CSV via SheetJS on client, then commit via JSON)
  // ============================================================
  $('#importMedBtn').on('click', function () {
    BPCModal.show('importModal');
  });

  // ============================================================
  // Import Modal focus management (accessibility)
  // ============================================================
  (function () {
    const importModalEl = document.getElementById('importModal');
    if (!importModalEl) return;

    let importModalTrigger = null;

    importModalEl.addEventListener('show.bs.modal', function (event) {
      importModalTrigger = event.relatedTarget || document.activeElement;
    });

    importModalEl.addEventListener('hide.bs.modal', function () {
      if (document.activeElement && importModalEl.contains(document.activeElement)) {
        document.activeElement.blur();
      }
    });

    importModalEl.addEventListener('hidden.bs.modal', function () {
      if (document.activeElement && importModalEl.contains(document.activeElement)) {
        document.activeElement.blur();
      }
      if (importModalTrigger && document.contains(importModalTrigger)) {
        importModalTrigger.focus();
      }
      importModalTrigger = null;
    });
  })();

  // Proper CSV parser that handles quoted fields (RFC 4180 compatible)
  function parseCSV(text) {
    const rows = [];
    let row = [], field = '', inQuotes = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (inQuotes) {
        if (c === '"') {
          if (text[i + 1] === '"') { field += '"'; i++; }
          else { inQuotes = false; }
        } else {
          field += c;
        }
      } else {
        if (c === '"') { inQuotes = true; }
        else if (c === ',') { row.push(field.trim()); field = ''; }
        else if (c === '\n') { row.push(field.trim()); field = ''; if (row.some(function (v) { return v !== ''; })) rows.push(row); row = []; }
        else if (c === '\r') { /* skip carriage return */ }
        else { field += c; }
      }
    }
    // Last field / row
    if (field !== '' || row.length > 0) { row.push(field.trim()); if (row.some(function (v) { return v !== ''; })) rows.push(row); }
    return rows;
  }

  $('#importFile').on('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
      let data = [];
      if (file.name.toLowerCase().endsWith('.csv')) {
        const text = e.target.result;
        // Proper CSV parser supporting quoted fields
        data = parseCSV(text);
      } else if (window.XLSX) {
        const wb = XLSX.read(e.target.result, { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        data = XLSX.utils.sheet_to_json(ws, { header: 1 });
      } else {
        toastError('XLSX parsing library not loaded.');
        return;
      }

      if (data.length < 2) { toastError('File appears empty.'); return; }

      // Send raw rows to the server for validation (server is the source of truth)
      doServerPreview(data);
    };
    if (file.name.toLowerCase().endsWith('.csv')) {
      reader.readAsText(file);
    } else {
      reader.readAsArrayBuffer(file);
    }
  });

  function doServerPreview(rows) {
    $('#importSummary').html('<div class="alert alert-info small"><span class="spinner-border spinner-border-sm me-1"></span> Validating on server...</div>');
    $('#importErrors').empty();
    $('#importPreviewTable').hide();
    $('#confirmImportBtn').prop('disabled', true);

    // Send the raw rows (array of arrays) to the server for import_preview validation
    $.post(APP + '/api/inventory.php', {
      action: 'import_preview',
      rows: rows,
      csrf_token: csrf
    }, function (res) {
      if (!res.success) {
        $('#importSummary').html('<div class="alert alert-danger small">' + escapeHtml(res.message || 'Validation failed.') + '</div>');
        return;
      }

      pendingImport = res.preview || [];
      const summary = res.summary || { total: 0, valid: 0, invalid: 0 };
      const errs = res.errors || [];

      $('#importSummary').html(
        '<div class="alert ' + (summary.valid > 0 ? 'alert-info' : 'alert-warning') + ' small">' +
        'Total: <b>' + summary.total + '</b> · Valid: <b class="text-success">' + summary.valid + '</b> · Invalid: <b class="text-danger">' + summary.invalid + '</b></div>'
      );
      $('#importErrors').html(errs.length
        ? '<div class="alert alert-warning small">' + errs.map(function (e) { return 'Line ' + e.line + ' (' + (e.medicine || '?') + '): ' + e.errors.join(', '); }).join('<br>') + '</div>'
        : '');

      $('#confirmImportBtn').prop('disabled', pendingImport.length === 0);

      // Show preview table
      const $tb = $('#importPreviewTable');
      if (pendingImport.length) {
        $tb.show();
        const headers = ['Name','Generic','Brand','Category','Dosage','Form','Qty','Unit','Min','Supplier','Batch','Exp','Location','Desc'];
        $tb.find('thead').html('<tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr>');
        $tb.find('tbody').html(pendingImport.slice(0, 20).map(function (v) {
          return '<tr><td>' + escapeHtml(v.medicine_name) + '</td><td>' + escapeHtml(v.generic_name || '') + '</td><td>' + escapeHtml(v.brand || '') + '</td><td>' + escapeHtml(v.category || '') + '</td><td>' + escapeHtml(v.dosage || '') + '</td><td>' + escapeHtml(v.form || '') + '</td><td>' + v.quantity + '</td><td>' + escapeHtml(v.unit || '') + '</td><td>' + v.reorder_level + '</td><td>' + escapeHtml(v.supplier || '') + '</td><td>' + escapeHtml(v.batch_number || '') + '</td><td>' + escapeHtml(v.expiration_date || '') + '</td><td>' + escapeHtml(v.storage_location || '') + '</td><td>' + escapeHtml(v.description || '') + '</td></tr>';
        }).join(''));
      } else {
        $tb.hide();
      }
    }, 'json').fail(function () {
      $('#importSummary').html('<div class="alert alert-danger small">Network error during validation. Please try again.</div>');
      $('#confirmImportBtn').prop('disabled', true);
    });
  }

  $('#confirmImportBtn').on('click', function () {
    var btn = $(this);
    if (!pendingImport.length) { toastError('No valid rows to import.'); return; }
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Importing...');
    $.post(APP + '/api/inventory.php', { action: 'import_commit', items: pendingImport, csrf_token: csrf }, function (res) {
      if (res.success) {
        toastSuccess(res.message);
        var modalEl = document.getElementById('importModal');
        if (modalEl) { var inst = bootstrap.Modal.getInstance(modalEl); if (inst) inst.hide(); }
        loadInventory(); loadStats();
      } else {
        toastError(res.message || 'Import failed. Please try again.');
        btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Import Valid Rows');
      }
    }, 'json').fail(function () {
      toastError('Network error. Please check your connection and try again.');
      btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Import Valid Rows');
    });
  });

  // ============================================================
  // Initial load
  // ============================================================
  loadStats();
  loadCategories();
  })();
};
</script>
