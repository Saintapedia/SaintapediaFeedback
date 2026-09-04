# SaintapediaFeedback

MediaWiki extension: floating **“Improve this article”** widget for readers, plus an editor **dashboard** to list, search, filter, and process feedback.

| Audience | What they get |
|----------|----------------|
| **Readers** | Submit without an account (public mode), with hCaptcha, rate limits, and block checks. Hide the floating button (× or long-press) for this tab; restore from the screen-edge tab or **Tools → Improve this article** |
| **Editors** | Dashboard + toolbox link (`saintapediafeedback-view`, granted to **sysop** by default). Seeing the contact email and downloading the JSON export each need their own separate right (`saintapediafeedback-viewemail`, `saintapediafeedback-export`), also sysop by default |

Requires **MediaWiki ≥ 1.39**.

---

## Where is the dashboard?

Log in as a user who has the `saintapediafeedback-view` right (usually **Admin** / sysop), then open:

| Wiki | Dashboard URL |
|------|----------------|
| **Path on any wiki** | `/wiki/Special:SaintapediaFeedback` |
| **Local Canasta dev** (`mwdev`, port 8080) | **http://localhost:8080/wiki/Special:SaintapediaFeedback** |
| Per article | `/wiki/Special:SaintapediaFeedback/<pageid>` |
| From an article page | Toolbox → **Page feedback** |

Operator-facing wiki copy (paste onto the wiki as `Project:SaintapediaFeedback`) lives in [docs/wiki/Project-SaintapediaFeedback.wiki](docs/wiki/Project-SaintapediaFeedback.wiki). Project namespace keeps it outside the widget’s default `[0]` list and out of article search.

Default filter is **New**. Use the status chips (or **All**) to see other items. Search, bulk process, and JSON export are on that same page.

Anonymous users and ordinary named accounts get a permission error on the special page (by design; default access is sysop).

---

## Install (each wiki)

1. Clone or copy into `extensions/SaintapediaFeedback` (or Canasta `user-extensions/` + symlink into `extensions/`).
2. Load the extension and run schema updates:

```php
// LocalSettings.php or Canasta settings PHP
wfLoadExtension( 'SaintapediaFeedback' );
```

```bash
php maintenance/run.php update.php
# Canasta: canasta maintenance exec -i <instance> -- php maintenance/run.php update.php
```

3. Confirm **Special:Version** lists SaintapediaFeedback.

---

## Public wiki (recommended defaults)

Almost all submitters are anonymous. Use ConfirmEdit’s **hCaptcha** module with **real** site keys (not test keys).

For a full checklist (keys, verification steps, PII/email decision, ongoing abuse handling) see **[docs/PUBLIC-DEPLOYMENT.md](docs/PUBLIC-DEPLOYMENT.md)**.

```php
wfLoadExtension( 'ConfirmEdit' );
wfLoadExtension( 'ConfirmEdit/hCaptcha' );
$wgCaptchaClass = 'HCaptcha';
$wgHCaptchaSiteKey   = getenv( 'HCAPTCHA_SITE_KEY' );
$wgHCaptchaSecretKey = getenv( 'HCAPTCHA_SECRET_KEY' );

wfLoadExtension( 'SaintapediaFeedback' );
$wgSaintapediaFeedbackMode = 'public';
// Dashboard is sysop-only by default. Option C (any named account):
// $wgSaintapediaFeedbackAccessGroups = [ 'user' ];
// $wgSaintapediaFeedbackRequireCaptcha = null; // auto: on for public
// $wgSaintapediaFeedbackRateLimit = 5;

// Who gets Echo alerts (must have saintapediafeedback-view)
$wgSaintapediaFeedbackNotifyUsers = [ 'Admin', 'EditorBot' ];
// Optional email (needs working $wgSMTP)
// $wgSaintapediaFeedbackNotifyEmail = 'editors@example.org';
```

Use a **different hCaptcha site key per public domain**.

## Enterprise wiki

```php
wfLoadExtension( 'SaintapediaFeedback' );
$wgSaintapediaFeedbackMode = 'enterprise';
// captcha off by default; longer comments; optional email field
// $wgSaintapediaFeedbackRequireCaptcha = true; // force captcha if desired
```

---

## Editor workflow

| URL / UI | Purpose |
|----------|---------|
| **`Special:SaintapediaFeedback`** | **Dashboard:** all items, filters, search, bulk process |
| `Special:SaintapediaFeedback/<pageid>` | One article |
| Toolbox → **Page feedback** | Jump to this page’s feedback (users with the right) |
| Export as JSON | LLM / offline analysis of current filters or one page |

**Bulk process:** select checkboxes → choose Mark reviewed / actioned / dismiss → Apply to selected.

**Search:** free text over comment text and page title (max 100 characters). LIKE metacharacters are escaped by MediaWiki’s DB layer.

## Who can use the dashboard?

Access is **configurable on-wiki** (no deploy required for day-to-day changes).

### Default (administrators)

If the config page is missing or empty, only **sysop** (and anyone granted `saintapediafeedback-view`) can open the dashboard. Anons, temp accounts, and ordinary named accounts cannot.

To restore option C (any named account) put `user` on the access page, or set `$wgSaintapediaFeedbackAccessGroups = [ 'user' ]`.

### Change who has access

Edit **`MediaWiki:SaintapediaFeedback-access`**.

That page lives in the **MediaWiki** namespace, which core restricts to users with the **`editinterface`** right (by default **sysop** and **interface-admin**). Ordinary editors cannot change who has dashboard access.

One group (or token) per line:

```
# Administrators (default)
sysop

# Option C — any named account (not temp):
# user

# Or restrict further, for example:
# editor
# autoconfirmed
```

| Token / group | Meaning |
|---------------|---------|
| `sysop` | Administrators (**default**) |
| `user` | Any named account — not anon, not a MW temp account (option **C**, opt-in) |
| `autoconfirmed` | Autoconfirmed users |
| `editor` | Your wiki’s editor group (if you have one) |
| `*` | Everyone including anons (not recommended). A line that is only `*` works; `* *` is the wiki-list form. |

Blank lines and `#` or `;` comments are ignored. Cache invalidates on **save, delete, or move** of the access page.

### Blocks

**A site/user block revokes dashboard access**, even if the person is a sysop or matches the access page. Blocking someone is enough to stop bulk-process/export abuse; you do not need to also remove them from a group. (Matches the submit API’s block check.)

### LocalSettings overrides

```php
// PHP default when the MediaWiki page is empty/missing (default is already ['sysop'])
$wgSaintapediaFeedbackAccessGroups = [ 'sysop' ];
// Intranet / option C — any named account:
// $wgSaintapediaFeedbackAccessGroups = [ 'user' ];

// Rename the config page (MediaWiki-namespace DB key, no prefix)
// $wgSaintapediaFeedbackAccessPage = 'SaintapediaFeedback-access';

// Always-on via right (still subject to blocks) — sysop has this by default
$wgGroupPermissions['sysop']['saintapediafeedback-view'] = true;
$wgGroupPermissions['editor']['saintapediafeedback-view'] = true;
```

Anyone who has the **`saintapediafeedback-view`** right **or** matches a group on the access page can manage feedback — unless they are blocked.

| Right | Default | Meaning |
|-------|---------|---------|
| `saintapediafeedback-view` | sysop | Dashboard access — view/process feedback. Allowed if not blocked (plus groups on the access page) |
| `saintapediafeedback-viewemail` | sysop | See the contact-email field, independent of dashboard access — see below |
| `saintapediafeedback-export` | sysop | Download the JSON export, independent of dashboard access — see below |

### Locking down the contact-email field separately

Dashboard access and contact-email visibility are **separate checks**. Widening
`SaintapediaFeedback-access` (e.g. to `user`, so any named editor can triage
feedback) does **not** automatically show them the optional contact-email
field — that's gated by its own right, **`saintapediafeedback-viewemail`**
(default **sysop**), and its own config page,
**`MediaWiki:SaintapediaFeedback-email-access`** (same one-group-per-line
syntax, same cache invalidation on save/delete/move).

```php
// PHP default when the email-access page is empty/missing (default is sysop)
$wgSaintapediaFeedbackEmailAccessGroups = [ 'sysop' ];
// Rename the email-access config page
// $wgSaintapediaFeedbackEmailAccessPage = 'SaintapediaFeedback-email-access';

// Always-on via right — sysop has this by default
$wgGroupPermissions['sysop']['saintapediafeedback-viewemail'] = true;
```

Use this when you want a broad editor group to see and process feedback, but
only a smaller trusted set to see whatever email address a reader typed in.
A user who fails this check simply sees the row with no contact-email
line — everything else on the dashboard is unaffected.

**Getting to "nobody" takes two steps, not one.** An empty or missing
groups list/page isn't "deny everyone" — it falls back to the `sysop`
default (`FeedbackAccess::DEFAULT_EMAIL_GROUPS`), and `sysop` also holds
`saintapediafeedback-viewemail` directly via `extension.json`'s
`GroupPermissions`, independent of the group-list check. So:

1. Remove the right from sysop in `LocalSettings.php`:
   `$wgGroupPermissions['sysop']['saintapediafeedback-viewemail'] = false;`
2. And point the group list at something no real user group matches — a
   made-up token like `no-one` on `MediaWiki:SaintapediaFeedback-email-access`
   works today (it isn't `*`, isn't `user`, and matches no actual MediaWiki
   group), or a real but nonexistent local group name.

Skipping either step alone still leaves sysop able to see it.

### Locking down bulk export separately

Same pattern again, for the JSON export. Dashboard access does **not** by
itself grant the ability to download the full raw export — that needs
**`saintapediafeedback-export`** (default **sysop**), configurable on-wiki via
**`MediaWiki:SaintapediaFeedback-export-access`**.

```php
$wgSaintapediaFeedbackExportAccessGroups = [ 'sysop' ];
// $wgSaintapediaFeedbackExportAccessPage = 'SaintapediaFeedback-export-access';
$wgGroupPermissions['sysop']['saintapediafeedback-export'] = true;
```

A user who can view/process feedback but lacks this right simply doesn't see
the **Export as JSON** link, and the export URLs return a permission error if
hit directly. Useful when you want a large triage team to process feedback in
the UI but keep bulk offline downloads (which are easier to exfiltrate or
mishandle than on-screen rows) to a smaller set.

**Getting export to "nobody" is the same two-step as email.** Creating
`MediaWiki:SaintapediaFeedback-export-access` with `no-one` is not enough:
sysop still has `saintapediafeedback-export` via `extension.json`. You must
also revoke that right:

```php
$wgGroupPermissions['sysop']['saintapediafeedback-export'] = false;
```

and point the group list at a dummy token (`no-one` on the export-access
page, or `$wgSaintapediaFeedbackExportAccessGroups = [ 'no-one' ];`). An
empty list/page falls back to sysop, same as email.

## On-wiki config for operational settings (no deploy)

A handful of non-secret operational knobs can also be set from a
`MediaWiki:` page instead of `LocalSettings.php`, the same way the access
pages work: one page, plain text, cached an hour, invalidated immediately on
save/delete/move. **When the page is missing or empty, the existing
`LocalSettings.php` value is used** — nothing changes until you create the
page.

| Setting | Page (DB key, no prefix) | Format | Overrides |
|---------|---------------------------|--------|-----------|
| Rate limit | `SaintapediaFeedback-ratelimit` | non-negative integer (`0` = reject every submit; delete the page to revert to PHP, do not write `0`) | `$wgSaintapediaFeedbackRateLimit` / `EnterpriseRateLimit` (mode-appropriate one) |
| Notify users | `SaintapediaFeedback-notify-users` | one username per line | `$wgSaintapediaFeedbackNotifyUsers` |
| Require captcha | `SaintapediaFeedback-require-captcha` | `true` / `false` | `$wgSaintapediaFeedbackRequireCaptcha` (and the mode-based auto default) |
| Show public counts | `SaintapediaFeedback-show-public-counts` | `true` / `false` | `$wgSaintapediaFeedbackShowPublicCounts` |
| Enable Talk link | `SaintapediaFeedback-enable-talklink` | `true` / `false` | `$wgSaintapediaFeedbackEnableTalkLink` |

Page names are each renameable via a `*Page` config var (e.g.
`$wgSaintapediaFeedbackRateLimitPage`), same convention as
`SaintapediaFeedbackAccessPage`. Line parsing is identical to the access
pages: `#`/`;` lines and blank lines are ignored, a leading wiki-list `* `
marker is stripped, and inline `# comment` text after a value is stripped
too — so `* false`, `* 10 # temporary`, and `Admin # notify lead editor` all
parse the way you'd expect from writing an access page. For a `true`/`false`
setting, the first non-comment line is matched case-insensitively against
`true`/`yes`/`on`/`1` and `false`/`no`/`off`/`0`; anything else (including a
typo) is treated as "no override" and falls back to the PHP value.

**What deliberately did *not* move on-wiki:** the hCaptcha secret key and the
LLM webhook bearer token. `MediaWiki:` pages are readable by anyone even
though editing them is restricted to `editinterface` — putting a secret there
would publish it, not lock it down. Those stay in `LocalSettings.php` / env
vars only.

**Captcha-required and the rate-limit page are security controls**, not just
operational preferences (notify users, public counts, and Talk link are).
Putting them on-wiki means any `editinterface` holder can turn off captcha
or write a huge integer to `SaintapediaFeedback-ratelimit` and neutralize
volume abuse control — without a deploy, a code review, or a PR, only
page-edit history as an audit trail. If that tradeoff doesn't fit your
review process, leave those two pages blank and set
`$wgSaintapediaFeedbackRequireCaptcha` / `$wgSaintapediaFeedbackRateLimit`
in `LocalSettings.php` instead; the on-wiki pages simply won't apply until
they have content. A cache/DB failure while reading the captcha page fails
closed to captcha **required**, so a blip cannot silently turn protection
off; a missing or empty page still uses the PHP/mode default. The PHP
warning for that case says `failing closed`. The other four knobs (rate
limit, notify users, public counts, Talk link) log `using PHP value` on
the same class of failure — they do **not** fail closed. On the
rate-limit page, `0` is a valid integer and **rejects every submission**
(`tryInsertUnderLimit` treats `$limit < 1` as over the limit) — it is not
"unlimited". Delete the page (or leave it blank) to fall back to the PHP
value.

## Security model (public)

- Anyone can submit (no login).
- CSRF + POST-only API; blocked users/IPs denied.
- hCaptcha when required (fail closed if misconfigured).
- Per-IP rate limit (hashed IP only; counted on the primary DB).
- Optional contact email is stored plaintext (so editors can follow up). Dashboard list queries do not select email or IP hash.
- Namespace allowlist on API and widget.
- Status changes: POST + edit token; bulk same.
- Review UI is never public.

## Multi-wiki

One codebase, per-wiki LocalSettings (mode, limits, captcha, notify lists, keys). See also [docs/LLM.md](docs/LLM.md) for the LLM-oriented export pipeline and the `ProcessFeedbackLlm.php` maintenance script.

## Development / tests

Unit tests (no full MediaWiki bootstrap):

```bash
# PHPUnit 9+
php phpunit.phar -c phpunit.xml.dist
# or
./vendor/bin/phpunit -c phpunit.xml.dist
```

## LLM pull job (optional)

Editors can still **Export as JSON**. For a cron / sidecar, set a webhook and run the maintenance script. The extension does **not** call a model itself — it POSTs `{count,items}` (no email, IP hash, or work notes) and marks `fb_llm_processed` on HTTP 2xx. Workflow status stays editor-owned.

```php
$wgSaintapediaFeedbackLlmWebhook = 'https://llm.example.org/hooks/feedback';
$wgSaintapediaFeedbackLlmWebhookToken = getenv( 'SAINTAPEDIA_LLM_WEBHOOK_TOKEN' ) ?: '';
$wgSaintapediaFeedbackLlmBatchSize = 100;
```

```bash
php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php --dry-run
php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php
# Canasta:
# canasta maintenance exec -i <instance> -- php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php
```

This repo includes a SpaceXAI worker in [`sidecar/`](sidecar/README.md):

```bash
export XAI_API_KEY=...
export SAINTAPEDIA_LLM_WEBHOOK_TOKEN=...   # match LocalSettings
python3 sidecar/server.py                  # http://127.0.0.1:8787/hooks/feedback
```

## Completing the loop

See **[docs/FEEDBACK-LOOP.md](docs/FEEDBACK-LOOP.md)** for enterprise encouragement, Talk-page options, public counts, LLM/agent path, and future SME (Login.gov) priority feedback.

```php
// Echo watchers who can manage feedback (default true; never notifies users without dashboard access)
$wgSaintapediaFeedbackNotifyWatchers = true;
// Public open/resolved chip (no free text)
$wgSaintapediaFeedbackShowPublicCounts = false;
// Optional checkbox on actioned: short Talk link only (default true)
$wgSaintapediaFeedbackEnableTalkLink = true;
```

## Version

See [CHANGELOG.md](CHANGELOG.md) for 1.8.0 and later.

**1.7.3** — operator wiki page paste target is `Project:SaintapediaFeedback` (not mainspace), so the widget does not appear on the cheat-sheet. Draft: [docs/wiki/Project-SaintapediaFeedback.wiki](docs/wiki/Project-SaintapediaFeedback.wiki).

**1.7.2** — overlay-read warnings say `failing closed` only for captcha; the other knobs log `using PHP value`. Operator wiki page draft originally shipped as a mainspace paste; 1.7.3 moves it to Project:.

**1.7.1** — wiki-config overlay reads fail closed with a warning: captcha stays required if that page cannot be read; contact-email and JSON export deny (instead of 500ing) if their access pages fail. Docs: rate-limit page is a security control like captcha; `0` on that page rejects every submit.

**1.7.0** — separate `saintapediafeedback-export` right + `MediaWiki:SaintapediaFeedback-export-access` page for bulk JSON export, independent of dashboard access. Five operational settings (rate limit, notify-user list, require-captcha, show-public-counts, enable-Talk-link) can now be set from `MediaWiki:` pages instead of `LocalSettings.php`; PHP config remains the fallback when a page is empty/missing. Secrets (hCaptcha key, LLM webhook token) intentionally stay LocalSettings/env-only.

**1.6.1** — separate `saintapediafeedback-viewemail` right + `MediaWiki:SaintapediaFeedback-email-access` page so contact-email visibility can be restricted independently of general dashboard access (default: sysop, same as before).

**1.6.0** — production hardening: default dashboard access is `sysop` (option C is opt-in); contact email shown to managers; rate-limit lock; export `Cache-Control: private, no-store`; `log_note` schema fix; Special:SpecialPages title; watcher Echo capped at 100 eligible managers (scan 1000). Readers can hide the floating button (session + long-press or ×); restore via the edge tab or Tools → Improve this article.

**1.5.2** — audit actors use persistent (named) accounts only; access copy says named accounts, not “all logged-in”.

**1.5.1** — treat MW temp accounts as anonymous on submit **and** dashboard access; inject `TitleFactory` (drops deprecated `Title::newFromID`).

**1.5.0** — `ProcessFeedbackLlm.php` maintenance script + SpaceXAI sidecar for the LLM pull job.

**1.4.2** — preserve work notes on status change; primary-DB rate limit; paginate per-article view; lone `*` access token; omit PII from list queries; IP-hash index; read-only / PRG hardening.

**1.4.0** — audit trail, toolbox counts, watchlist Echo, optional public counts, priority column for future SME, access config page.
