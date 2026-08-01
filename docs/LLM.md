# LLM pipeline (what exists vs what to build)

SaintapediaFeedback is designed so **structured reader feedback** can feed an LLM (or any offline analyzer) without putting model keys inside MediaWiki. Today the **storage + export** side is ready; a full automated “AI triage job” is **not** shipped yet.

## What already exists in code

### Schema (`sql/tables.sql`)

| Column | Role |
|--------|------|
| `fb_categories` | JSON array of chips (`inaccurate`, `outdated`, …) — structured signal |
| `fb_comment` | Optional free text |
| `fb_status` | Editor workflow: `new` → `reviewed` / `actioned` / `dismissed` |
| `fb_llm_processed` | `0`/`1` flag for “this row was already sent through an LLM batch” |
| `fb_llm_processed_timestamp` | When that flag was set |

Raw IPs are **not** stored (only a hash for rate limiting). Contact email may be stored in enterprise mode — do **not** send that to a public model without a privacy review.

### PHP store (`FeedbackStore`)

| Method | Purpose |
|--------|---------|
| `exportForPage( $pageId )` | JSON-ready array for one article |
| `exportDashboard( $filters )` | Same for filtered dashboard query (up to 500) |
| `getPendingLlmBatch( $limit )` | Rows with `fb_llm_processed = 0` and status `new` or `reviewed` |
| `markLlmProcessed( $ids )` | Flip `fb_llm_processed` after a successful batch |

### HTTP export (editors only)

- `Special:SaintapediaFeedback/export` — current dashboard filters as JSON  
- `Special:SaintapediaFeedback/export/<pageid>` — one page  

Requires `saintapediafeedback-view`. Response shape:

```json
{
  "count": 2,
  "items": [
    {
      "id": 1,
      "pageId": 900,
      "pageTitle": "Immaculate_Heart_of_Mary_(Diocese_of_Paterson)",
      "timestamp": "20260801012854",
      "categories": ["inaccurate", "outdated"],
      "comment": "Thanks!",
      "status": "new",
      "mode": "public"
    }
  ]
}
```

## Recommended architecture (not fully built)

```
Reader → API (captcha, rate limit) → spf_feedback
                                      │
                                      ├─► Echo / email (editors)
                                      ├─► Special:SaintapediaFeedback (human triage)
                                      └─► Export / batch job → LLM → markLlmProcessed
                                                              └─► optional: notes for editors
```

**Do not** call an LLM from the request that submits feedback (latency, keys, abuse). Prefer:

1. **Pull job** (cron / systemd / Canasta sidecar):  
   - Call `getPendingLlmBatch()` via a **maintenance script** (to add) or authenticated export URL.  
   - Send only `id`, `pageTitle`, `categories`, `comment`, `timestamp` to the model.  
   - On success: `markLlmProcessed( $ids )`.  
2. **Human still owns status** — LLM can suggest tags or draft replies; `fb_status` stays editor-controlled unless you deliberately automate.

## Example offline prompt (illustrative)

```
You are helping wiki editors triage article feedback.
For each item, return JSON: { "id", "priority": "high|medium|low",
  "summary": "...", "suggested_action": "edit|sources|dismiss|needs_info" }.
Do not invent page content; only use the feedback fields provided.
```

## What is *not* implemented yet

| Piece | Notes |
|-------|--------|
| Maintenance script `processFeedbackLlm.php` | Would call store + external API |
| API module for LLM workers | Would need a bot right + token, separate from public submit |
| Writing LLM output back into MW | Would need new columns or a talk-page bot |
| Auto-changing `fb_status` | Intentionally left to humans for now |
| SpaceXAI / provider binding | Choose outside the extension (env + job) |

## Safe defaults

- Export only for users with `saintapediafeedback-view`.  
- Never export `fb_ip_hash` or contact email to a third-party model by default.  
- Cap batch size (store already limits).  
- Idempotency via `fb_llm_processed`.

## Natural next implementation slice

1. `maintenance/ProcessFeedbackLlm.php` that:
   - loads pending batch,
   - posts JSON to a configured webhook/URL,
   - marks processed on HTTP 2xx.
2. Config: `$wgSaintapediaFeedbackLlmWebhook`, `$wgSaintapediaFeedbackLlmBatchSize`.
3. Integration test with a mock HTTP endpoint.

Until then, editors use **Export as JSON** from the dashboard (or per-page export) and run analysis offline.
