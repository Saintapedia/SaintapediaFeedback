-- Add audit + priority fields to existing spf_feedback installs.
--
-- Columns only. The spf_priority index lives in patch-index-priority.sql and is
-- registered with addExtensionIndex(): a CREATE INDEX bundled in here aborts the
-- rest of the patch when the index name already exists, and MediaWiki cannot
-- resume a half-applied patch file. Because the guard column would already have
-- been added by the ALTER above it, the patch never re-runs and the index is
-- lost silently.
ALTER TABLE /*_*/spf_feedback
	ADD COLUMN fb_status_user_id INT UNSIGNED NULL DEFAULT NULL AFTER fb_status,
	ADD COLUMN fb_status_timestamp VARBINARY(14) NULL DEFAULT NULL AFTER fb_status_user_id,
	ADD COLUMN fb_priority TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER fb_llm_processed_timestamp;
