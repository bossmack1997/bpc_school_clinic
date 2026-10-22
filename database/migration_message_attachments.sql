-- Adds secure file attachment metadata to clinic conversations.
-- Run once against the BPC clinic database before enabling message attachments.
ALTER TABLE messages
    MODIFY COLUMN message_body TEXT NULL,
    MODIFY COLUMN message_type ENUM('text', 'system_notice', 'ai_summary', 'file') NOT NULL DEFAULT 'text',
    ADD COLUMN attachment_path VARCHAR(255) NULL AFTER message_type,
    ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path,
    ADD COLUMN attachment_mime VARCHAR(127) NULL AFTER attachment_name,
    ADD COLUMN attachment_size INT UNSIGNED NULL AFTER attachment_mime;
