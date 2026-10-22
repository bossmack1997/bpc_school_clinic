<?php
if (!defined('APP_URL')) { http_response_code(404); exit; }
?>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="section-title mb-0"><i class="bi bi-clock-history text-success me-1"></i> System Activity Logs</h6>
        <div class="input-group" style="width:280px;max-width:100%;">
          <input type="text" id="searchInput" class="form-control" placeholder="Search logs...">
          <button class="btn btn-outline-primary" type="button" id="searchLogsButton" aria-label="Search logs"><i class="bi bi-search"></i></button>
        </div>
      </div>
      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="logsTable" style="width:100%">
            <thead><tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Details</th><th>IP Address</th><th>Date/Time</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2">
          <small class="text-muted" id="logInfo"></small>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary" type="button" aria-label="Previous page" id="prevPage" disabled><i class="bi bi-chevron-left"></i></button>
            <button class="btn btn-sm btn-outline-secondary" type="button" aria-label="Next page" id="nextPage"><i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </div>
