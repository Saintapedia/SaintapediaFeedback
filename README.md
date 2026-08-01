# SaintapediaFeedback

MediaWiki extension: floating **“Improve this article”** widget for readers, plus an editor **dashboard** to list, search, filter, and process feedback.

- **Readers:** submit without an account (public mode), with hCaptcha, rate limits, and block checks.
- **Editors:** `Special:SaintapediaFeedback` (right: `saintapediafeedback-view`, granted to sysop by default).

Requires **MediaWiki ≥ 1.39**.

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

## Editor workflow

| URL / UI | Purpose |
|----------|---------|
| `Special:SaintapediaFeedback` | Dashboard: all items, filters, search, bulk process |
| `Special:SaintapediaFeedback/<pageid>` | One article |
| Toolbox → **Page feedback** | Jump to this page’s feedback (users with the right) |
| Export as JSON | LLM / offline analysis of current filters or one page |

**Bulk process:** select checkboxes → choose Mark reviewed / actioned / dismiss → Apply to selected.

**Search:** free text over comment text and page title (max 100 chars; `%`/`_` stripped).

## Rights

| Right | Default | Meaning |
|-------|---------|---------|
| `saintapediafeedback-view` | sysop | View/process feedback, export, toolbox link |

```php
$wgGroupPermissions['editor']['saintapediafeedback-view'] = true;
```

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
composer install   # if using a PHPUnit project root
./vendor/bin/phpunit tests/phpunit/unit
```

Or run the unit files with any PHPUnit 9+ that can autoload `includes/`:

```bash
phpunit --bootstrap tests/phpunit/bootstrap.php tests/phpunit/unit
```

## Version

1.3.0 — dashboard, search, bulk process, editor toolbox link, optional Echo/email notify, docs and unit tests.
