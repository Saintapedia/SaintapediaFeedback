-- Append-only audit log for status changes
CREATE TABLE IF NOT EXISTS /*_*/spf_feedback_log (
	log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	log_fb_id INT UNSIGNED NOT NULL,
	log_user_id INT UNSIGNED NULL DEFAULT NULL,
	log_old_status VARBINARY(16) NULL DEFAULT NULL,
	log_new_status VARBINARY(16) NOT NULL,
	log_note TEXT NULL DEFAULT NULL,
	log_timestamp VARBINARY(14) NOT NULL,
	PRIMARY KEY (log_id),
	INDEX spf_log_fb (log_fb_id, log_timestamp)
) /*$wgDBTableOptions*/;
