-- Add audit + priority fields to existing spf_feedback installs
ALTER TABLE /*_*/spf_feedback
	ADD COLUMN fb_status_user_id INT UNSIGNED NULL DEFAULT NULL AFTER fb_status,
	ADD COLUMN fb_status_timestamp VARBINARY(14) NULL DEFAULT NULL AFTER fb_status_user_id,
	ADD COLUMN fb_priority TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER fb_llm_processed_timestamp;

CREATE INDEX spf_priority ON /*_*/spf_feedback (fb_priority, fb_status, fb_timestamp);

-- Note: work notes / public resolution fields: see patch-work-notes-public.sql
