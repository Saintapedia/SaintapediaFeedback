-- Work notes (private) + public resolution fields on spf_feedback only.
--
-- Columns only; the spf_public_res index lives in patch-index-public-res.sql.
-- Previously a CREATE INDEX sat between these two ALTERs, so an index-name
-- clash aborted the patch before the spf_feedback_log column was added.
--
-- spf_feedback_log.log_note is owned exclusively by patch-log-note.sql
-- (registered separately via addExtensionField on spf_feedback_log/log_note).
-- This patch used to also ALTER spf_feedback_log to add log_note, but that
-- column is already present when feedback_log.sql creates the table fresh,
-- so on an upgrade where spf_feedback_log doesn't exist yet the updater
-- would create it (with log_note included) and then this patch would try
-- to add log_note a second time and abort with a duplicate-column error.
ALTER TABLE /*_*/spf_feedback
	ADD COLUMN fb_work_note TEXT NULL DEFAULT NULL AFTER fb_priority,
	ADD COLUMN fb_resolution_public TINYINT(1) NOT NULL DEFAULT 0 AFTER fb_work_note,
	ADD COLUMN fb_resolution_summary TEXT NULL DEFAULT NULL AFTER fb_resolution_public;
