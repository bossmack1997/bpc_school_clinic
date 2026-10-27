-- Non-destructive defaults. Existing policy values are retained.
INSERT INTO system_settings(setting_key,setting_value) VALUES
 ('clinic_manual_status','scheduled'),
 ('clinic_closed_dates',''),
 ('clinic_queue_capacity','100')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
