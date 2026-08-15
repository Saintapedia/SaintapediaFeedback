-- Audit log note (private work note snapshot). Dedicated so recreating
-- spf_feedback_log from feedback_log.sql is not tied to fb_work_note.
ALTER TABLE /*_*/spf_feedback_log
	ADD COLUMN log_note TEXT NULL DEFAULT NULL AFTER log_new_status;
