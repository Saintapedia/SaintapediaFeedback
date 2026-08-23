-- Index for the priority/status dashboard ordering.
-- Registered separately with addExtensionIndex(); see patch-audit-priority.sql.
CREATE INDEX /*i*/spf_priority ON /*_*/spf_feedback (fb_priority, fb_status, fb_timestamp);
