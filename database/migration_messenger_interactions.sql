-- Additive migration. Existing messages and participants are unchanged.
CREATE TABLE IF NOT EXISTS message_interactions (
 message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 reply_to_id BIGINT UNSIGNED NULL,
 deleted_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS message_reactions (
 message_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 emoji VARCHAR(16) NOT NULL,
 PRIMARY KEY (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Rollback (removes interaction metadata only):
-- DROP TABLE message_reactions;
-- DROP TABLE message_interactions;
