# SaintapediaFeedback production deploy

**Stable release: v1.8.0** — pin prod to this tag (or a newer `v1.8.x`).
Do not track floating `main`.

See [CHANGELOG.md](./CHANGELOG.md) for what changed.

## Install

1. Place the extension (prefer the tag, not floating `main`):

   ```bash
   cd /path/to/mediawiki/w/extensions   # or user-extensions on Canasta
   git clone --branch v1.8.0 --depth 1 \
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

4. **Run `update.php`** — required for 1.8.0:

   ```bash
   php maintenance/run.php update.php
   # Canasta: canasta maintenance exec -i <instance> -- php maintenance/run.php update.php
   ```

   1.8.0 registers two indexes (`spf_priority`, `spf_public_res`) that older
   installs may be missing; see the CHANGELOG for why.

5. Restart web and confirm **Special:Version** lists SaintapediaFeedback **1.8.0**.

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

## Rights

| Right | Default | Meaning |
|-------|---------|---------|
| `saintapediafeedback-view` | sysop | Dashboard access |
| `saintapediafeedback-viewemail` | sysop | See the submitter's contact email |
| `saintapediafeedback-export` | sysop | Download the JSON export |

Blocked users are denied both submitting and dashboard access, including under
a partial block.

## Rollback

```bash
cd extensions/SaintapediaFeedback && git fetch --tags && git checkout v1.7.3
# or remove SaintapediaFeedback from settings.yaml and restart
```

Rolling back the code is safe: 1.8.0 adds no columns, only indexes, and the
1.7.x code ignores them.

> If `extension.json` is missing, the whole wiki can fatal on every request.
> Keep the load line only when the directory is present.

## Known gaps

- Never run on a wiki with real reader traffic. Rate limiting, captcha and the
  audit trail are verified on scratch wikis and by integration tests only.
- `>= 1.39` is now accurate by inspection — core's `HISTORY` for the namespace
  moves, plus the `class_alias` shims present in 1.43 — but has not been run on
  a real 1.39 or 1.40 wiki.
