# Changelog

Releases are tagged. Pin a production wiki to a tag, not to floating `main`.

Versions before 1.8.0 were not changelogged; their history is in git.

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
