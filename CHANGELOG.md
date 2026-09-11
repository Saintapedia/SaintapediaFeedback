# Changelog

Releases are tagged. Pin a production wiki to a tag, not to floating `main`.

Versions before 1.8.0 were not changelogged; their history is in git.

## Unreleased

Fixes for a 2026-09-10 external code review (source-verified against this repo;
[PR #25](https://github.com/Saintapedia/SaintapediaFeedback/pull/25)), plus one
requested behavior change.

### Features

- **Enterprise mode defaults dashboard access to any named account.** When
  `$wgSaintapediaFeedbackAccessGroups` is unset, the default is now
  mode-dependent: sysop-only for public mode (unchanged), `['user']` (option
  C, any named account, not temp/anon) for enterprise mode. Intranet wikis
  generally have far more trusted logged-in staff than a public wiki's
  sysop-only default fits. Email and export access are unaffected — they
  stay sysop-only regardless of mode. Set `$wgSaintapediaFeedbackAccessGroups`
  explicitly to override either mode's default.

### Security / privacy fixes

- **Rate-limit race under concurrency.** The named lock guarding
  `tryInsertUnderLimit()` could release before its insert's transaction
  committed, letting concurrent requests exceed the configured limit. Now
  uses `IDatabase::getScopedLockAndFlush()`, which ties release to
  commit/rollback.
- **Schema upgrade could abort with a duplicate-column error.** Two patches
  both tried to add `spf_feedback_log.log_note`; a legacy install upgrading
  through both in one `update.php` run could hit a duplicate-column error.
  `patch-work-notes-public.sql` no longer touches `log_note` — `patch-log-note.sql`
  is its sole owner.
- **Access, email-access, and export-access are LocalSettings.php-only.**
  These, plus the rate limit and CAPTCHA requirement, used to also be
  overridable from `MediaWiki:`-namespace pages editable by anyone holding
  `editinterface`, with no deploy or code review. That on-wiki override is
  removed for these five settings. Notify-user list, show-public-counts, and
  enable-Talk-link remain wiki-overridable (operational preferences, not
  security controls).
- **Contact email can never be made public.** `$wgSaintapediaFeedbackEmailAccessGroups`
  now silently drops a `*` token (logging a warning) before the access check
  runs, regardless of how it was configured — there is no way to make reader
  email visible to anonymous/everyone.
- **Raw reader comments no longer duplicated into Echo.** Echo event storage
  used to carry up to 200 characters of the raw comment; notifications now
  point recipients at the dashboard instead.
- **IP hash is no longer a stable, indefinitely linkable identifier.**
  Replaced `sha256(ip . $wgSecretKey)` with an HMAC over a dedicated,
  rotatable secret (`$wgSaintapediaFeedbackIpHashSecret`, falls back to
  `$wgSecretKey` if unset) plus a UTC-date bucket, so the same address
  hashes differently each day. The rate-limit count now checks both
  today's and yesterday's hash for the same address, so the day-bucketed
  hash doesn't turn the cap into a UTC-midnight reset — an earlier draft
  of this fix missed that a client could otherwise submit up to the limit
  just before midnight and the limit again just after, doubling the
  effective daily cap in under a minute; caught in review before merge.
- **Moderation status updates are now concurrency-safe.** `updateStatus()`
  used to read-then-unconditionally-overwrite; two racing moderators could
  silently clobber each other's transition history. Now guarded by an
  optimistic-concurrency `WHERE fb_status = $old`.
- **LLM processing has an explicit disable gate.** New
  `$wgSaintapediaFeedbackEnableLlm` (default `false`); non-dry-run processing
  refuses to post or mark rows even if `--webhook` is passed on the command
  line. The deeper F-03 validation gap (the runner trusts bare HTTP 2xx
  instead of a verified per-ID acknowledgment) is still open and documented
  as an activation blocker in `docs/LLM.md`.
- **Sidecar hardening.** `Content-Length` is validated (missing/invalid →
  411/400, oversized → 400) instead of raising or under-checking; requests
  time out after 30s; the sidecar refuses to start on a non-loopback bind
  without a token configured.
- **Contact-email retention.** New `maintenance/ExpireContactEmails.php` plus
  `$wgSaintapediaFeedbackContactEmailRetentionDays` (default `0` = disabled)
  clears `fb_contact_email` on rows past a configurable age, leaving the rest
  of the row intact. Nothing runs automatically until an operator sets a
  retention window and schedules the job.

### Docs

- README distinguishes the MediaWiki 1.39 compatibility floor (tested
  against, EOL) from a production recommendation.

### Upgrade notes

- **If you run enterprise mode and have never set `$wgSaintapediaFeedbackAccessGroups`,
  dashboard access widens on upgrade** — from sysop-only to any named account
  (option C). If you want to keep it sysop-only, set
  `$wgSaintapediaFeedbackAccessGroups = [ 'sysop' ];` explicitly before
  upgrading. Public mode is unaffected either way.
  **This also affects Echo notifications**: `$wgSaintapediaFeedbackNotifyWatchers`
  defaults to `true` and filters recipients through the same dashboard-access
  check, so a named account that gains dashboard access this way also starts
  receiving Echo alerts on new submissions for pages it watches (the alert
  itself never contains the raw comment — F-04 — but the comment is one click
  away once someone has dashboard access). If you don't want that expansion,
  either pin `AccessGroups` to `[ 'sysop' ]` or set `NotifyWatchers = false`
  before upgrading. Email and export access are unaffected either way — they
  stay sysop-only regardless of mode.
- If you relied on `MediaWiki:SaintapediaFeedback-access`,
  `-email-access`, `-export-access`, `-ratelimit`, or `-require-captcha`
  pages to configure this extension, **those pages now do nothing.** Move
  the equivalent settings to `LocalSettings.php`
  (`$wgSaintapediaFeedbackAccessGroups`, `EmailAccessGroups`,
  `ExportAccessGroups`, `RateLimit`/`EnterpriseRateLimit`,
  `RequireCaptcha`) before upgrading if you were using non-default values on
  those pages.
- `$wgSaintapediaFeedbackAccessPage`, `EmailAccessPage`, `ExportAccessPage`,
  `RateLimitPage`, and `RequireCaptchaPage` no longer exist. Remove them from
  `LocalSettings.php` if present — they are now unused, not just deprecated.
- No `update.php` required for these changes (no schema change beyond the
  F-02 migration-patch fix, which only affects a specific legacy upgrade
  path).

## 1.8.1 — 2026-09-04

### Fixes

- **MediaWiki 1.45 special-page titles.** `OutputPage::setPageTitle()` no
  longer accepts a `Message` (T343994). Special:SaintapediaFeedback crashed
  with `ParameterTypeException: Bad value for parameter $name: must be a
  string`. Titles go through `setPageTitleMsg()` on MW 1.41+, with a
  `method_exists` fallback so the declared `>= 1.39.0` floor still works.

### Tests

- Source-level regression test scans `includes/` so a `Message` cannot be
  passed to `setPageTitle()` again.

### Upgrade notes

No `update.php`. No configuration changes.

## 1.8.0 — 2026-09-02

### Features

- **Status history is readable.** `spf_feedback_log` had been written on every
  status transition since the audit patch, and nothing ever read it — the
  dashboard showed only the denormalized "status last set by X on Y" pair, so
  everything before the last change was collected and then unreachable. New
  per-item view at `Special:SaintapediaFeedback/detail/<id>` shows when, who,
  the transition and the reviewer note, with a **History** link on each row.
- **Hand-off to a field-level suggestion tool.** When something on the page
  answers `mw.hook( 'saintapediasuggest.ready' )`, the reader panel offers
  *"Correcting a specific fact instead?"*, which closes it and fires
  `mw.hook( 'saintapediasuggest.open' )`. Hidden unless answered, so nothing
  changes on a wiki without such a tool, and there is no dependency in either
  direction.

### Fixes

- **`">= 1.39.0"` is now true.** The code imported core classes that were not
  namespaced until later — `Title`, `TitleFactory`, `User`, `WebRequest` and
  `CommentStoreComment` (1.41), `OutputPage` (1.42), plus `Config`,
  `ExtensionRegistry` and `WikitextContent`. On 1.39 or 1.40 that was a fatal
  at class load. Switched to the un-namespaced names, which core still provides
  via `class_alias` on 1.43.
- **A schema patch could abort and never re-run.** `patch-audit-priority.sql`
  and `patch-work-notes-public.sql` each ran `ALTER TABLE` then `CREATE INDEX`
  in one file. MediaWiki cannot resume a half-applied patch, so an index-name
  clash aborted the remainder — and because the guard column had already been
  added by the preceding `ALTER`, the patch never ran again. Reproduced on 1.43:
  `update.php` died with `Duplicate key name 'spf_priority'`, leaving
  `fb_work_note`, `fb_resolution_public`, `fb_resolution_summary` and
  `spf_feedback_log.log_note` absent. Indexes now live one per patch, registered
  with `addExtensionIndex()`.
- **hCaptcha is no longer injected twice.** The widget appended its own script
  tag unconditionally; on a wiki running a second extension that does the same,
  hCaptcha initialised twice. It now reuses a tag already in the document.

### Tests

- Integration suite for the store layer (25 tests). Two of them cover privacy
  guarantees previously asserted only by reading the code: list queries and
  JSON exports must never materialize `fb_contact_email` or `fb_ip_hash`.

### Upgrade notes

`update.php` **is** required — 1.8.0 registers two indexes (`spf_priority`,
`spf_public_res`) that older installs may be missing because of the patch bug
above. No data migration; no configuration changes.

### Install pin

```bash
git clone --branch v1.8.0 --depth 1 \
  https://github.com/Saintapedia/SaintapediaFeedback.git SaintapediaFeedback
```
