INSERT INTO system_settings (setting_key,setting_value) VALUES
('clinic_open_time','08:00'),
('clinic_close_time','17:00'),
('clinic_operating_days','1,2,3,4,5')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
