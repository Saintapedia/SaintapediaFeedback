-- Work notes (private) + public resolution fields
ALTER TABLE /*_*/spf_feedback
	ADD COLUMN fb_work_note TEXT NULL DEFAULT NULL AFTER fb_priority,
	ADD COLUMN fb_resolution_public TINYINT(1) NOT NULL DEFAULT 0 AFTER fb_work_note,
	ADD COLUMN fb_resolution_summary TEXT NULL DEFAULT NULL AFTER fb_resolution_public;

CREATE INDEX spf_public_res ON /*_*/spf_feedback (fb_page_id, fb_resolution_public, fb_status);

ALTER TABLE /*_*/spf_feedback_log
	ADD COLUMN log_note TEXT NULL DEFAULT NULL AFTER log_new_status;
