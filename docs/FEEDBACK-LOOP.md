# Completing the feedback loop

Product direction for SaintapediaFeedback: help **enterprise editors** improve pages, give **wiki admins** knobs, and leave room for **AI ops** and **SME trust**.

## Decisions locked in

| Topic | Decision |
|-------|----------|
| Who can manage | Configurable via `MediaWiki:SaintapediaFeedback-access`; default **all logged-in** (`user`) |
| Audit trail | Yes — last actor + timestamp on row; append-only `spf_feedback_log` |
| Toolbox count | Yes — “Page feedback (N new)” for managers |
| Public counts | Optional config (`ShowPublicCounts`) — open vs resolved only, **no free text** |
| Watchlist Echo | Default **on** — watchers of the article get Echo on new feedback (needs Echo) |
| Talk on actioned | **Not auto** (see options below) |
| LLM | Export + batch flags ready; proactive AI edits are a **future job**, not in-request |
| SME auth | Schema has `fb_priority`; Login.gov-class identity is **future**, not MW core login |

## Enterprise encouragement (your goal)

1. **Watchlist Echo** — editors already watching a page learn of new feedback without living in the dashboard.  
2. **Toolbox counts** — when they open the page, “Page feedback (3 new)” pulls them in.  
3. **Access** — enterprise can set the access page to `editor` / staff groups, or leave `user` for broad participation.  
4. **Audit** — multi-editor orgs can see who marked what.  

Optional later: assign owner, SLA on `new` items, weekly digest email.

## Talk pages — options (recommendation)

| Option | When to use |
|--------|-------------|
| **A. No auto Talk** *(default)* | Quiet wikis; status lives in dashboard |
| **B. Optional checkbox on “actioned”** *(recommended next code)* | Editor chooses to post a short templated note |
| **C. Always post on actioned** | High-transparency enterprise; risk of Talk noise |
| **D. Post only for SME / priority** | When SME channel exists |

**Recommendation:** Keep Talk **out of the critical path** for now. Add **B** after audit has shipped and you’ve run a pilot: template like “Addressed feedback #$id (category …).” Do **not** dump raw reader comments onto Talk by default (harassment / PII).

## Public counts (4d)

```php
$wgSaintapediaFeedbackShowPublicCounts = true; // open · resolved chip near FAB
```

Shows social proof without a public comment thread. Resolved = `actioned` only (not dismissed).

## LLM / Grok Build (proactive AI wikis)

**Near term (human-led):** dashboard export / `getPendingLlmBatch` → offline or job → suggestions for editors.

**Medium term (Grok Build / agent):**

```
pending batch → agent reads JSON + page wikitext
  → proposes patch (edit, sources, fix link)
  → human approve OR auto-edit under a bot account with limits
  → markLlmProcessed + set status actioned (with audit actor = bot)
```

Keep **model keys and auto-edit policy outside** the request path. Use a maintenance script or external worker with a **bot password** and clear rate limits. See [LLM.md](LLM.md).

## SME / Login.gov (priority feedback)

Schema already has **`fb_priority`** (0 = normal; higher = SME/trusted).

Future flow (not built):

1. Reader starts feedback → optional “I’m a verified expert”  
2. OAuth / Login.gov (or similar) **outside** MW password  
3. On success, submit with elevated `fb_priority` + maybe verified org claim in a separate table  
4. Dashboard sorts/filters “priority first”; Echo can prefer priority  

Do **not** conflate SME with sysop rights. SME raises **signal**, not wiki power.

## Suggested next implementation order

1. ~~Access page + default logged-in~~ (PR #6)  
2. ~~Audit + toolbox count + watchlist Echo + public counts flag~~ (this work)  
3. Optional Talk note on actioned (checkbox)  
4. LLM webhook maintenance script  
5. SME OAuth + priority filter UI  

## Config cheat sheet

```php
$wgSaintapediaFeedbackAccessGroups = [ 'user' ]; // or tighten
$wgSaintapediaFeedbackNotifyWatchers = true;     // Echo to page watchers
$wgSaintapediaFeedbackNotifyUsers = [ 'Admin' ]; // extra named recipients
$wgSaintapediaFeedbackShowPublicCounts = false;  // set true for open/resolved chip
```
