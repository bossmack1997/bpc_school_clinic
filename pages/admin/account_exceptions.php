<?php
/**
 * pages/admin/account_exceptions.php
 * Legacy URL: preserved for bookmarks; security exception handling now lives in Security Center.
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
redirect('security.php');
