# Deploying SaintapediaFeedback on a public-facing wiki

A checklist-style runbook for turning on reader feedback on a wiki anyone on the
internet can reach. If you're installing on a closed intranet/enterprise wiki,
see the shorter **Enterprise wiki** section in [README.md](../README.md) instead
— most of the hardening below (captcha, rate limits, PII handling) exists
specifically for the anonymous-public case.

This assumes SaintapediaFeedback is already installed (`wfLoadExtension` +
`update.php` — see [README.md § Install](../README.md#install-each-wiki)) and
you're now configuring it for public traffic.

## 1. Get real hCaptcha keys

Almost every submitter on a public wiki is anonymous, so hCaptcha is the
primary abuse control — the per-IP rate limit alone will not stop a scripted
flood. Skipping this step is the single most common way to end up with a
feedback table full of spam.

1. Create a site at [hcaptcha.com](https://www.hcaptcha.com/) for your wiki's
   domain. **Use a separate site key per public domain** — do not share one
   key across unrelated wikis.
2. Store the site key and secret key as environment variables (or your
   secrets manager), not literals in `LocalSettings.php`.
3. Confirm the keys are the **live** keys, not hCaptcha's test keys — test
   keys always pass and give you zero real protection.

```php
wfLoadExtension( 'ConfirmEdit' );
wfLoadExtension( 'ConfirmEdit/hCaptcha' );
$wgCaptchaClass      = 'HCaptcha';
$wgHCaptchaSiteKey   = getenv( 'HCAPTCHA_SITE_KEY' );
$wgHCaptchaSecretKey = getenv( 'HCAPTCHA_SECRET_KEY' );
```

If `ConfirmEdit`/hCaptcha isn't loaded, or the site key is empty, the
extension **fails closed**: submissions are rejected with
`saintapediafeedback-error-captcha-unavailable` rather than silently
accepting unverified traffic. That's intentional — don't work around it by
setting `SaintapediaFeedbackRequireCaptcha = false` just to make an error go
away; fix the captcha config instead.

## 2. Turn on public mode

```php
wfLoadExtension( 'SaintapediaFeedback' );
$wgSaintapediaFeedbackMode = 'public';
```

Public mode gives you, by default: captcha required, a 500-character comment
cap, a 5-submissions-per-IP-per-day rate limit, and no contact-email field.
All are overridable (below) but the defaults are the recommended starting
point — loosen them deliberately, not by habit.

## 3. Decide who manages the dashboard

**Leave this alone unless you have a reason to change it.** Default access is
`sysop` only — anonymous users, MediaWiki temp accounts, and ordinary named
accounts all get a permission error on `Special:SaintapediaFeedback`.

If you want any logged-in (non-temp) account to triage feedback, that's an
explicit opt-in ("Option C"), done on-wiki without a deploy:

1. Log in as an admin (or `interface-admin`) and edit
   `MediaWiki:SaintapediaFeedback-access`.
2. Put `user` on its own line.

```
# Administrators (default)
sysop

# Option C — any named account (not temp):
user
```

Don't set this to `*` (everyone, including anons) on a public wiki — that
hands dashboard access and bulk-process to anyone who loads the page,
including the raw comment/category text of every submission. It does **not**
by itself grant bulk JSON export or contact-email visibility — those are
separate rights (`saintapediafeedback-export`, `saintapediafeedback-viewemail`,
each defaulting to sysop) with their own access pages, described below.

A block on a user revokes dashboard access immediately, even for someone who
otherwise matches `sysop` or the access page — you do not need to also strip
their group membership to shut off a compromised or abusive account.

## 4. Decide about the contact-email field

Public mode ships with the email field **off**
(`$wgSaintapediaFeedbackEnableEmail = false`). Turning it on stores whatever
address a reader types in `fb_contact_email` as **plaintext** in the
database — there is no encryption at rest for that column, and the
extension does not validate that the address belongs to the submitter.

Before enabling it on a public site:

- Confirm this fits your privacy policy / GDPR (or equivalent) posture —
  you're now collecting PII from anonymous visitors.
- Have a retention/deletion plan for old rows, since nothing in the extension
  ages out old feedback automatically.
- List queries never select this column (`FeedbackStore::exportDashboard` /
  the dashboard list view don't project it), and the **JSON export does not
  include it either** — export omits contact email and IP hash the same way
  the dashboard list does. The only place it's ever shown is the per-row
  detail view on the dashboard, and only to someone who passes the separate
  `saintapediafeedback-viewemail` check (below) — dashboard access alone is
  no longer enough to see it.

If you don't need it, leave it off — it's the lowest-friction privacy choice.

If you *do* enable it, decide who should pass `saintapediafeedback-viewemail`
/ `MediaWiki:SaintapediaFeedback-email-access` — that check, not general
dashboard access, is what actually controls visibility now. See
[README.md § Locking down the contact-email field separately](../README.md#locking-down-the-contact-email-field-separately)
for the two-step process if you want to restrict it below the sysop default.

## 5. Set who gets notified

```php
// Named accounts only; each one must already have saintapediafeedback-view
$wgSaintapediaFeedbackNotifyUsers = [ 'Admin', 'EditorBot' ];

// Optional — requires a working $wgSMTP
// $wgSaintapediaFeedbackNotifyEmail = 'editors@example.org';

// Default true: notify watchers of the article, but only ones who could
// already open the dashboard (same access check — never leaks comment text
// to a casual watcher who lacks saintapediafeedback-view)
$wgSaintapediaFeedbackNotifyWatchers = true;
```

If nobody is watching for new feedback, it will pile up unseen — set at
least one of `NotifyUsers` or a working Echo/watchlist path before you
announce the feature.

## 6. Verify before you announce it

Do these checks from a real browser, logged out, before linking the feature
from your homepage or announcing it anywhere:

- [ ] Submit feedback anonymously on a live page. Confirm the hCaptcha
      widget actually appears and a wrong/empty solve is rejected.
- [ ] Submit 6 times in a row from the same browser/IP (default limit is 5/day
      in public mode) and confirm the 6th is rejected with the rate-limit
      error, not silently accepted.
- [ ] Confirm `Special:SaintapediaFeedback` returns a permission error when
      logged out and when logged in as a plain (non-admin) account.
- [ ] Log in as the intended dashboard user and confirm the new submissions
      show up under the **New** filter.
- [ ] Temporarily misconfigure the hCaptcha site key (empty it) and confirm
      submissions are rejected rather than silently passing — this proves
      the fail-closed behavior is actually wired up on your stack, not just
      in the extension's source.
- [ ] Check `Special:Version` lists SaintapediaFeedback with the version you
      expect.

## 7. Ongoing operational care

- **Namespace scope**: `$wgSaintapediaFeedbackNamespaces` defaults to `[0]`
  (main namespace only). Widen it deliberately if you want feedback on Talk,
  Portal, etc. — every namespace you add is more public surface area for the
  submit API.
- **Abuse response**: block the offending account/IP through normal
  MediaWiki blocking. A block immediately revokes both submit access and,
  if applicable, dashboard access (§3) — you don't need a separate
  extension-specific ban list.
- **On-wiki overrides**: rate limit, notify-user list, captcha-required,
  public counts, and the Talk-link flag can each be set from a `MediaWiki:`
  page instead of `LocalSettings.php` — see [README.md § On-wiki config for
  operational settings](../README.md#on-wiki-config-for-operational-settings-no-deploy)
  and the paste-ready operator page
  [Project-SaintapediaFeedback.wiki](wiki/Project-SaintapediaFeedback.wiki)
  (paste as `Project:SaintapediaFeedback`, not mainspace — the widget’s
  default namespace list is `[0]`, so a mainspace cheat-sheet would get
  its own “Improve this article” button).
  Captcha **and** the rate-limit page are security controls: anyone with
  `editinterface` can flip them without a deploy. On a public wiki, leave
  those two pages **blank** and keep the values in LocalSettings. If you do
  create `MediaWiki:SaintapediaFeedback-require-captcha`, re-run the §6
  fail-closed check after any edit to that page. A cache/DB blip reading
  the captcha page logs `failing closed` and keeps captcha required; a blip
  reading any of the other four knobs logs `using PHP value` and falls back
  to LocalSettings (rate-limit `0` is *not* that fallback — `0` rejects
  every submit; delete the page to revert).
- **Rate-limit tuning**: `$wgSaintapediaFeedbackRateLimit` (default 5/day) is
  per hashed-IP. It will not stop an attacker rotating IPs or using a VPN —
  hCaptcha is your real backstop against scripted abuse, not the rate limit.
- **Public counts / resolutions**: if you want transparency without exposing
  raw reader comments, prefer
  `Special:SaintapediaFeedback/resolutions/<pageid>` (curated summaries editors
  publish) over `$wgSaintapediaFeedbackShowPublicCounts`, which the project
  deliberately defaults to `false` — see
  [FEEDBACK-LOOP.md](FEEDBACK-LOOP.md#public-counts-chip).
- **Backups**: `spf_feedback` and `spf_feedback_log` are ordinary tables in
  your wiki's database — they're covered by whatever backup process already
  covers `page`/`revision`. No separate backup path is needed, but confirm
  that's actually true for your setup rather than assuming it.
- **LLM export, if used**: keep `$wgSaintapediaFeedbackLlmWebhookToken` out of
  version control (env var, not a literal). The extension never sends
  `fb_ip_hash` or contact email to the webhook — see
  [LLM.md](LLM.md#safe-defaults) if you're wiring up the pull job.

## Multi-domain checklist

Running the same codebase on several public wikis? Per domain, confirm:

- [ ] Its own hCaptcha site key/secret (§1) — never reused across domains.
- [ ] Its own `SaintapediaFeedback-access` page reviewed, not inherited by
      accident from a shared LocalSettings default.
- [ ] Its own notify list (§5) — an admin on wiki A is not automatically
      watching wiki B.
