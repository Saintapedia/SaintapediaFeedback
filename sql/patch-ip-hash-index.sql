-- Rate-limit lookups: COUNT recent rows by hashed IP
CREATE INDEX spf_ip_time ON /*_*/spf_feedback (fb_ip_hash, fb_timestamp);
