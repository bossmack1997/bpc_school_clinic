<?php
/**
 * pages/admin/medical_records.php
 * Forwarding alias to the institutional Reports console
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
redirect('reports.php');
