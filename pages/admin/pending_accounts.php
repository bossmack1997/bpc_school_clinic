<?php
/**
 * pages/admin/pending_accounts.php
 * Forwarding alias to the consolidated People management page (Pending tab)
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
redirect('users.php?tab=pending');