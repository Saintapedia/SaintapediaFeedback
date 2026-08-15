# SaintapediaFeedback

MediaWiki extension: floating **“Improve this article”** widget for readers, plus an editor **dashboard** to list, search, filter, and process feedback.

| Audience | What they get |
|----------|----------------|
| **Readers** | Submit without an account (public mode), with hCaptcha, rate limits, and block checks. Hide the floating button (× or long-press) for this tab; restore from the screen-edge tab or **Tools → Improve this article** |
| **Editors** | Dashboard + toolbox link (`saintapediafeedback-view`, granted to **sysop** by default) |

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
| `saintapediafeedback-view` | sysop | Allowed if not blocked (plus groups on the access page) |
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

**1.6.0** — production hardening: default dashboard access is `sysop` (option C is opt-in); contact email shown to managers; rate-limit lock; export `Cache-Control: private, no-store`; `log_note` schema fix; Special:SpecialPages title; watcher Echo scan capped at 100. Readers can hide the floating button (session + long-press or ×); restore via the edge tab or Tools → Improve this article.

**1.5.2** — audit actors use persistent (named) accounts only; access copy says named accounts, not “all logged-in”.

**1.5.1** — treat MW temp accounts as anonymous on submit **and** dashboard access; inject `TitleFactory` (drops deprecated `Title::newFromID`).

**1.5.0** — `ProcessFeedbackLlm.php` maintenance script + SpaceXAI sidecar for the LLM pull job.

**1.4.2** — preserve work notes on status change; primary-DB rate limit; paginate per-article view; lone `*` access token; omit PII from list queries; IP-hash index; read-only / PRG hardening.

**1.4.0** — audit trail, toolbox counts, watchlist Echo, optional public counts, priority column for future SME, access config page.
