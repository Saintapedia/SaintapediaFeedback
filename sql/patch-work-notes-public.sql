-- Work notes (private) + public resolution fields.
--
-- Columns only; the spf_public_res index lives in patch-index-public-res.sql.
-- Previously a CREATE INDEX sat between these two ALTERs, so an index-name
-- clash aborted the patch before the spf_feedback_log column was added.
ALTER TABLE /*_*/spf_feedback
	ADD COLUMN fb_work_note TEXT NULL DEFAULT NULL AFTER fb_priority,
	ADD COLUMN fb_resolution_public TINYINT(1) NOT NULL DEFAULT 0 AFTER fb_work_note,
	ADD COLUMN fb_resolution_summary TEXT NULL DEFAULT NULL AFTER fb_resolution_public;

ALTER TABLE /*_*/spf_feedback_log
	ADD COLUMN log_note TEXT NULL DEFAULT NULL AFTER log_new_status;
