<?php
/**
 * pages/admin/qr_scanner.php
 * Forwarding alias to the QR Management console (scanning is a nurse workstation action)
 */
require_once __DIR__ . '/../../config/config.php';
require_primary_admin();
redirect('qr_management.php');
