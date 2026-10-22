<?php
/**
 * pages/admin/students.php
 * Forwarding alias to the unified User Management overseer console
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
if(!has_admin_permission('manage_users')&&!has_admin_permission('approve_users'))admin_access_denied();
$search = trim($_GET['search'] ?? '');
$target = 'users.php?role=student' . ($search !== '' ? '&search=' . urlencode($search) : '');
redirect($target);