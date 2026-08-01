-- SaintapediaFeedback database schema

CREATE TABLE IF NOT EXISTS /*_*/spf_feedback (
	-- Primary key
	fb_id INT UNSIGNED NOT NULL AUTO_INCREMENT,

	-- The page this feedback is about
	fb_page_id INT UNSIGNED NOT NULL,
	fb_page_namespace INT NOT NULL DEFAULT 0,
	fb_page_title VARBINARY(255) NOT NULL,

	-- Who submitted (null = anonymous)
	fb_user_id INT UNSIGNED NULL DEFAULT NULL,
	-- Hashed IP for rate limiting and deduplication; never stored raw
	fb_ip_hash VARBINARY(64) NOT NULL DEFAULT '',

	-- Structured categories chosen by submitter (JSON array of strings)
	fb_categories BLOB NOT NULL,

	-- Free-text comment (short in public mode, long in enterprise)
	fb_comment TEXT NULL DEFAULT NULL,

	-- Optional contact email (enterprise mode only, stored hashed for privacy unless opted in)
	fb_contact_email VARBINARY(255) NULL DEFAULT NULL,

	-- 'public' or 'enterprise'
	fb_mode VARBINARY(16) NOT NULL DEFAULT 'public',

	-- Workflow status: 'new', 'reviewed', 'actioned', 'dismissed'
	fb_status VARBINARY(16) NOT NULL DEFAULT 'new',

	-- Last status change (denormalized for dashboard display)
	fb_status_user_id INT UNSIGNED NULL DEFAULT NULL,
	fb_status_timestamp VARBINARY(14) NULL DEFAULT NULL,

	-- Timestamps
	fb_timestamp VARBINARY(14) NOT NULL,

	-- LLM processing fields
	fb_llm_processed TINYINT(1) NOT NULL DEFAULT 0,
	fb_llm_processed_timestamp VARBINARY(14) NULL DEFAULT NULL,

	-- SME / trusted-source flag (future Login.gov etc.; 0 = normal reader)
	fb_priority TINYINT UNSIGNED NOT NULL DEFAULT 0,

	PRIMARY KEY (fb_id),
	INDEX spf_page (fb_page_id, fb_timestamp),
	INDEX spf_status (fb_status, fb_timestamp),
	INDEX spf_user (fb_user_id),
	INDEX spf_llm (fb_llm_processed, fb_timestamp),
	INDEX spf_priority (fb_priority, fb_status, fb_timestamp)
) /*$wgDBTableOptions*/;

-- Append-only audit log for status changes
CREATE TABLE IF NOT EXISTS /*_*/spf_feedback_log (
	log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	log_fb_id INT UNSIGNED NOT NULL,
	log_user_id INT UNSIGNED NULL DEFAULT NULL,
	log_old_status VARBINARY(16) NULL DEFAULT NULL,
	log_new_status VARBINARY(16) NOT NULL,
	log_timestamp VARBINARY(14) NOT NULL,
	PRIMARY KEY (log_id),
	INDEX spf_log_fb (log_fb_id, log_timestamp)
) /*$wgDBTableOptions*/;
