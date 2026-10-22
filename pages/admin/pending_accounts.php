<?php
/**
 * pages/admin/pending_accounts.php
 * Forwarding alias to the consolidated People management page (Pending tab)
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
if(!has_admin_permission('manage_users')&&!has_admin_permission('approve_users'))admin_access_denied();
redirect('users.php?tab=pending');