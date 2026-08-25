<?php
/**
 * api/advisory.php
 * ------------------------------------------------------------------
 * Deprecated Admin Advisory Management Endpoint.
 * Teacher advisory classes are now self-managed by Teachers directly
 * from the Adviser Portal.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');
json_response(false, [], 'Advisory class management is now self-managed by Teachers from the Teacher Portal.');

