# SaintapediaFeedback

MediaWiki extension: floating **“Improve this article”** widget for readers, plus an editor **dashboard** to list, search, filter, and process feedback.

| Audience | What they get |
|----------|----------------|
| **Readers** | Submit without an account (public mode), with hCaptcha, rate limits, and block checks |
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

Anonymous users get a permission error on the special page (by design).

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

### Default (option C — all logged-in users)

If the config page is missing or empty, **any registered account** can open the dashboard (`user` group token). Anons cannot.

### Change who has access

Edit **`MediaWiki:SaintapediaFeedback-access`** (sysops only by default — MediaWiki namespace is protected).

One group (or token) per line:

```
# All logged-in users (default / option C)
user

# Or restrict, for example:
# sysop
# editor
# autoconfirmed
```

| Token / group | Meaning |
|---------------|---------|
| `user` | Any registered account (option **C**) |
| `autoconfirmed` | Autoconfirmed users |
| `sysop` | Administrators |
| `editor` | Your wiki’s editor group (if you have one) |
| `*` | Everyone including anons (not recommended) |

Blank lines and `#` comments are ignored. After you save, cache is invalidated automatically.

### LocalSettings overrides

```php
// PHP default when the MediaWiki page is empty/missing (default is already ['user'])
$wgSaintapediaFeedbackAccessGroups = [ 'user' ];

// Rename the config page (MediaWiki-namespace DB key, no prefix)
// $wgSaintapediaFeedbackAccessPage = 'SaintapediaFeedback-access';

// Always-on via right (bypasses the page list) — sysop has this by default
$wgGroupPermissions['sysop']['saintapediafeedback-view'] = true;
$wgGroupPermissions['editor']['saintapediafeedback-view'] = true;
```

Anyone who has the **`saintapediafeedback-view`** right **or** matches a group on the access page can manage feedback.

| Right | Default | Meaning |
|-------|---------|---------|
| `saintapediafeedback-view` | sysop | Always allowed (plus groups on the access page) |
## Security model (public)

- Anyone can submit (no login).
- CSRF + POST-only API; blocked users/IPs denied.
- hCaptcha when required (fail closed if misconfigured).
- Per-IP rate limit (hashed IP only).
- Namespace allowlist on API and widget.
- Status changes: POST + edit token; bulk same.
- Review UI is never public.

## Multi-wiki

One codebase, per-wiki LocalSettings (mode, limits, captcha, notify lists, keys). See also [docs/LLM.md](docs/LLM.md) for the LLM-oriented export pipeline.

## Development / tests

Unit tests (no full MediaWiki bootstrap):

```bash
# PHPUnit 9+
php phpunit.phar -c phpunit.xml.dist
# or
./vendor/bin/phpunit -c phpunit.xml.dist
```

## Version

**1.3.0** — dashboard, search, bulk process, editor toolbox link, optional Echo/email notify, docs and unit tests.
