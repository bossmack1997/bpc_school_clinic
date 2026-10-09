<?php
/**
 * pages/admin/students.php
 * Forwarding alias to the unified User Management overseer console
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$search = trim($_GET['search'] ?? '');
$target = 'users.php?role=student' . ($search !== '' ? '&search=' . urlencode($search) : '');
redirect($target);