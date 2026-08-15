# Completing the feedback loop

Product direction for SaintapediaFeedback: help **enterprise editors** improve pages, give **wiki admins** knobs, and leave room for **AI ops** and **SME trust** (e.g. federal employees on [Open USA Project](https://openusaproject.com/wiki/Portal:Homepage)).

## Decisions locked in

| Topic | Decision |
|-------|----------|
| Who can manage | Configurable via `MediaWiki:SaintapediaFeedback-access`; default **named accounts** (`user`; not temp) |
| Audit trail | Yes — last actor + timestamp; `spf_feedback_log` |
| Toolbox count | Yes — “Page feedback (N new)” for managers |
| **Public count chip** | **Default off** — use public *resolutions* instead of a noisy badge |
| Watchlist Echo | Default **on** — only watchers who **may manage** feedback (same gate as dashboard; no comment leak to casual watchers) |
| **Talk pages** | **No auto-post.** Optional checkbox: short **link only** to public resolutions |
| **Actioned → public** | **Default on** when marking actioned (single or bulk; uncheck to keep private). Public list shows summary + categories, not raw reader text |
| LLM | Export/batch ready; proactive AI edits via future job |
| SME auth | `fb_priority` reserved; Login.gov-class IdP later |

## Talk pages vs dashboard notes (your direction)

**Do not require Talk comments before marking actioned.**

| Layer | Where | Who sees it |
|-------|--------|-------------|
| Reader feedback | Dashboard only | Managers |
| **Work note** (private) | Dashboard when processing | Anyone who can open the dashboard (default: named accounts). Not on Talk. |
| **Public resolution** | `Special:SaintapediaFeedback/resolutions/<pageid>` | Anyone |
| **Talk (optional)** | Checkbox on actioned → short section + link | Talk watchers |

**UX when marking actioned:**

1. **Encourage** a private work note (“what did you change?”) — recommended, not required.  
2. Optional: **Publish this resolution** (+ short public summary).  
3. Optional: **Also post a short link on Talk** (`$wgSaintapediaFeedbackEnableTalkLink`, default true) — only a heading + link to public resolutions, **never** work notes or raw reader text.  
4. De-duplicated per feedback id (HTML comment marker).

```php
$wgSaintapediaFeedbackEnableTalkLink = true; // show Talk checkbox; false to hide
```

## Enterprise encouragement

1. Watchlist Echo  
2. Toolbox counts  
3. Access page (broad `user` or tight `editor` / staff)  
4. Audit + work notes  
5. Public resolutions for accountability without Talk spam  

## Public counts chip

```php
$wgSaintapediaFeedbackShowPublicCounts = false; // keep default off
```

Prefer linking to **public resolutions** when you want transparency.

## SME / federal employees (Open USA Project)

Target path for experts (e.g. federal employees commenting on portal content):

1. Start feedback on the wiki  
2. Optional “Verify as federal employee / SME” → **Login.gov** (or similar) OAuth **outside** MW passwords  
3. On success, store elevated **`fb_priority`** (+ optional claim metadata)  
4. Dashboard: sort/filter “priority first”; stronger Echo routing later  

SME raises **signal**, not wiki admin rights. Portal example: `https://openusaproject.com/wiki/Portal:Homepage`.

## LLM / Grok Build

See [LLM.md](LLM.md). `ProcessFeedbackLlm.php` pulls a pending batch, POSTs to `$wgSaintapediaFeedbackLlmWebhook`, and marks processed on HTTP 2xx. The worker (not MediaWiki) talks to the model. `fb_status` stays editor-controlled.

## Config cheat sheet

```php
$wgSaintapediaFeedbackAccessGroups = [ 'user' ];
$wgSaintapediaFeedbackNotifyWatchers = true;
$wgSaintapediaFeedbackNotifyUsers = [ 'Admin' ];
$wgSaintapediaFeedbackShowPublicCounts = false; // hold off
```

Public resolutions URL pattern:

`/wiki/Special:SaintapediaFeedback/resolutions/<pageid>`
