# SaintapediaFeedback production deploy

**Stable release: v1.9.0** — pin prod to this tag (or a newer `v1.9.x`).
Do not track floating `main`.

See [CHANGELOG.md](./CHANGELOG.md) for what changed.

**Upgrading from 1.8.x?** Read the "Migrating from an on-wiki access/rate-limit/captcha
setup" note in step 3 before you deploy — skipping it can silently change who has
dashboard access.

## Install

1. Place the extension (prefer the tag, not floating `main`):

   ```bash
   cd /path/to/mediawiki/w/extensions   # or user-extensions on Canasta
   git clone --branch v1.9.0 --depth 1 \
     https://github.com/Saintapedia/SaintapediaFeedback.git SaintapediaFeedback
   ```

   On Canasta, if code lives under `user-extensions/`, ensure a symlink:

   ```bash
   ln -sfn ../user-extensions/SaintapediaFeedback /path/to/w/extensions/SaintapediaFeedback
   ```

2. Enable — Canasta `settings.yaml`:

   ```yaml
   extensions:
     - SaintapediaFeedback
   ```

3. Configure. Minimum for a public wiki:

   ```php
   $wgSaintapediaFeedbackMode       = 'public';   // captcha on by default
   $wgSaintapediaFeedbackNamespaces = [ NS_MAIN ];
   $wgSaintapediaFeedbackRateLimit  = 5;          // per IP per day

   // hCaptcha via ConfirmEdit — secrets stay here, never on a wiki page
   wfLoadExtension( 'ConfirmEdit' );
   wfLoadExtension( 'ConfirmEdit/hCaptcha' );
   $wgCaptchaClass      = 'MediaWiki\\Extension\\ConfirmEdit\\hCaptcha\\HCaptcha';
   $wgHCaptchaSiteKey   = 'your-site-key';
   $wgHCaptchaSecretKey = getenv( 'HCAPTCHA_SECRET' );
   ```

   If a captcha is required but the site key is missing, submits **fail closed**
   and the widget shows a configuration error rather than silently accepting spam.

   **Migrating from an on-wiki access/rate-limit/captcha setup (upgrading from
   1.8.x or earlier):** as of 1.9.0, `MediaWiki:SaintapediaFeedback-access`,
   `-email-access`, `-export-access`, `-ratelimit`, and `-require-captcha`
   pages **no longer have any effect** — those five settings are
   `LocalSettings.php`-only now. **Before you deploy**, check whether any of
   those pages currently exist with non-default content on the live wiki, and
   if so, port the equivalent value into `LocalSettings.php`:

   ```php
   $wgSaintapediaFeedbackAccessGroups       = [ 'sysop' ];   // or whatever the old page said
   $wgSaintapediaFeedbackEmailAccessGroups  = [ 'sysop' ];
   $wgSaintapediaFeedbackExportAccessGroups = [ 'sysop' ];
   $wgSaintapediaFeedbackRateLimit          = 5;
   $wgSaintapediaFeedbackRequireCaptcha     = null;          // null = auto from mode
   ```

   Skipping this silently reverts access/rate-limit/captcha to the mode
   default the moment this version deploys — no error, no warning, just a
   behavior change. Notify-user list, show-public-counts, and enable-talklink
   are unaffected; those three stay wiki-overridable.

   Also new: set a dedicated `$wgSaintapediaFeedbackIpHashSecret` (e.g.
   `openssl rand -hex 32`, stored in the environment, not committed) so the
   rate-limit IP hash can be rotated independently of `$wgSecretKey`. Falls
   back to `$wgSecretKey` if left unset, so this is a recommendation, not a
   requirement.

4. **Run `update.php`** (recommended, idempotent — no new columns in 1.9.0,
   but it does pick up the corrected `log_note` migration path for very old
   installs):

   ```bash
   php maintenance/run.php update.php
   # Canasta: canasta maintenance exec -i <instance> -- php maintenance/run.php update.php
   ```

5. Restart web and confirm **Special:Version** lists SaintapediaFeedback **1.9.0**.

## Smoke checklist

| Check | Expected |
|-------|----------|
| Open an article in an enabled namespace (anon) | Floating **Improve this article** button |
| Open the panel, submit with a category | Thank-you message; row appears on the dashboard |
| Submit again past the daily cap | Rate-limit message, no row |
| Dismiss the button (×) | Gone for this tab; returns in a new tab |
| `Special:SaintapediaFeedback` as anon | Permission error, not a stack trace |
| …as sysop | Dashboard with status chips and filters |
| Set an item reviewed, then actioned | Both transitions appear under **History** |
| **History** link on a row | Opens `/detail/<id>` with the full status table |
| JSON export as sysop | Downloads; contains **no** email or IP hash |
| Contact email on the dashboard | Visible only with `saintapediafeedback-viewemail` |
| `MediaWiki:SaintapediaFeedback-access` edited to something wider | **No effect** — dashboard access unchanged (confirms the 1.9.0 LocalSettings-only change actually took) |
| Two moderators action the same item near-simultaneously | Loser sees a clear "couldn't update" message, not a silent no-op |

## Rights

| Right | Default | Meaning |
|-------|---------|---------|
| `saintapediafeedback-view` | sysop (public mode) / any named account (enterprise mode) | Dashboard access |
| `saintapediafeedback-viewemail` | sysop | See the submitter's contact email |
| `saintapediafeedback-export` | sysop | Download the JSON export |

Blocked users are denied both submitting and dashboard access, including under
a partial block.

## Rollback

```bash
cd extensions/SaintapediaFeedback && git fetch --tags && git checkout v1.8.1
# or remove SaintapediaFeedback from settings.yaml and restart
```

Rolling back the code is safe: 1.9.0 adds no new columns. If you added
`LocalSettings.php` values for `AccessGroups`/`EmailAccessGroups`/
`ExportAccessGroups`/`RateLimit`/`RequireCaptcha` as part of the 1.9.0
migration step above, leave them in place on rollback — 1.8.1 reads the same
variables as a fallback when its on-wiki pages are empty, so they do no harm
and keep behavior consistent either way.

> If `extension.json` is missing, the whole wiki can fatal on every request.
> Keep the load line only when the directory is present.

## Known gaps

- Rate limiting under concurrent load (F-01) and moderator conflict handling
  (F-05) have been verified against a real MediaWiki + MariaDB instance under
  genuine concurrent HTTP traffic — see the 2026-09-10 review PR (#25) commits
  for the exact reproduction. The full MediaWiki `phpunit --group
  SaintapediaFeedback` DB-integration suite has still never been run
  end-to-end in any available sandbox (blocked by an unrelated pre-existing
  issue in the environment it was attempted in); run it once against your own
  staging environment before a first production deploy if you want that
  additional signal.
- The floating widget's actual visual rendering (button + hCaptcha) has not
  been checked in a real browser as part of this release — do that manually
  once on staging before announcing publicly.
- `>= 1.39` is now accurate by inspection — core's `HISTORY` for the namespace
  moves, plus the `class_alias` shims present in 1.43 — but has not been run on
  a real 1.39 or 1.40 wiki.
