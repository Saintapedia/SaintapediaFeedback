# Completing the feedback loop

Product direction for SaintapediaFeedback: help **enterprise editors** improve pages, give **wiki admins** knobs, and leave room for **AI ops** and **SME trust** (e.g. federal employees on [Open USA Project](https://openusaproject.com/wiki/Portal:Homepage)).

## Decisions locked in

| Topic | Decision |
|-------|----------|
| Who can manage | Configurable via `MediaWiki:SaintapediaFeedback-access`; default **all logged-in** (`user`) |
| Audit trail | Yes — last actor + timestamp; `spf_feedback_log` |
| Toolbox count | Yes — “Page feedback (N new)” for managers |
| **Public count chip** | **Default off** — use public *resolutions* instead of a noisy badge |
| Watchlist Echo | Default **on** — watchers get Echo on new feedback |
| **Talk pages** | **Do not auto-post.** Keep work notes on the dashboard |
| **Actioned → public** | Optional: publish a short **resolution** (not the raw reader comment) on a public list |
| LLM | Export/batch ready; proactive AI edits via future job |
| SME auth | `fb_priority` reserved; Login.gov-class IdP later |

## Talk pages vs dashboard notes (your direction)

**Do not require Talk comments before marking actioned.**

| Layer | Where | Who sees it |
|-------|--------|-------------|
| Reader feedback | Dashboard only | Managers |
| **Work note** (private) | Dashboard when processing | Managers only |
| **Public resolution** | `Special:SaintapediaFeedback/resolutions/<pageid>` | Anyone |
| Talk page | Optional later / human-written | Watchers of Talk |

**Recommended UX when marking actioned:**

1. **Encourage** a private work note (“what did you change?”) — recommended, not hard-required.  
2. Optional checkbox: **Publish this resolution**.  
3. Optional short **public summary** (safe text only).  
4. **No automatic Talk edit** — avoids clutter; transparency lives on the public resolutions list.

If a wiki wants Talk noise, editors can still paste a one-liner and link to `/resolutions/<pageid>` themselves.

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

See [LLM.md](LLM.md). Worker pulls pending batch → proposes/applies edits under a bot → audit actor = bot → mark processed.

## Config cheat sheet

```php
$wgSaintapediaFeedbackAccessGroups = [ 'user' ];
$wgSaintapediaFeedbackNotifyWatchers = true;
$wgSaintapediaFeedbackNotifyUsers = [ 'Admin' ];
$wgSaintapediaFeedbackShowPublicCounts = false; // hold off
```

Public resolutions URL pattern:

`/wiki/Special:SaintapediaFeedback/resolutions/<pageid>`
