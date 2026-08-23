-- Index for the public resolutions list.
-- Registered separately with addExtensionIndex(); see patch-work-notes-public.sql.
CREATE INDEX /*i*/spf_public_res ON /*_*/spf_feedback (fb_page_id, fb_resolution_public, fb_status);
