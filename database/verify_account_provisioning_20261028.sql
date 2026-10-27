-- Read-only. Explicitly select and verify the intended application database first.
SELECT DATABASE() AS selected_database,VERSION() AS server_version;
SHOW CREATE TABLE account_activation_invitations;
SHOW CREATE TABLE account_provisioning_lock;
SELECT id FROM account_provisioning_lock WHERE id=1;
SELECT delivery_status,COUNT(*) AS invitations FROM account_activation_invitations GROUP BY delivery_status;
SELECT i.id FROM account_activation_invitations i LEFT JOIN users u ON u.id=i.user_id LEFT JOIN users a ON a.id=i.created_by WHERE u.id IS NULL OR a.id IS NULL;
SELECT user_id,COUNT(*) AS usable_links FROM account_activation_invitations WHERE used_at IS NULL AND expires_at>NOW() GROUP BY user_id HAVING COUNT(*)>1;
SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('clinic_manual_status','clinic_closed_dates','clinic_queue_capacity','clinic_open_time','clinic_close_time','clinic_operating_days');
