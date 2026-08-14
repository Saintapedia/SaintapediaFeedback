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
   - Run `maintenance/ProcessFeedbackLlm.php` (or authenticated export URL).  
   - Send only `id`, `pageId`, `pageTitle`, `categories`, `comment`, `timestamp`, `status` to the model.  
   - On HTTP 2xx: `markLlmProcessed( $ids )`.  
2. **Human still owns status** — LLM can suggest tags or draft replies; `fb_status` stays editor-controlled unless you deliberately automate.

## Example offline prompt (illustrative)

```
You are helping wiki editors triage article feedback.
For each item, return JSON: { "id", "priority": "high|medium|low",
  "summary": "...", "suggested_action": "edit|sources|dismiss|needs_info" }.
Do not invent page content; only use the feedback fields provided.
```

## Maintenance script (shipped)

`maintenance/ProcessFeedbackLlm.php` is the MediaWiki `Maintenance` interface for the pull job.

```bash
php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php --dry-run
php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php --limit 50
```

| Config | Default | Role |
|--------|---------|------|
| `$wgSaintapediaFeedbackLlmWebhook` | `''` | POST target; empty disables the job unless `--webhook` |
| `$wgSaintapediaFeedbackLlmWebhookToken` | `''` | Optional `Authorization: Bearer …` |
| `$wgSaintapediaFeedbackLlmBatchSize` | `100` | Default `--limit` (capped at 500) |

PHP surface (unit-tested, no MW required):

- `Llm\FeedbackLlmBatchSource` — store contract (`getPendingLlmBatch` / `markLlmProcessed`)
- `Llm\FeedbackLlmPoster` — HTTP POST contract
- `Llm\FeedbackLlmPayload::fromRow()` — PII-safe item shape
- `Llm\FeedbackLlmBatchRunner` — dry-run / 2xx-mark / fail-closed webhook

`--dry-run` prints the batch JSON and does not POST or flip flags. Non-2xx leaves rows pending (idempotent retry).

The webhook is provider-agnostic. This repo ships a SpaceXAI sidecar in [`sidecar/`](../sidecar/README.md): it reads the JSON, calls `https://api.x.ai/v1/responses` with `XAI_API_KEY` (model `grok-4.6` by default, `store: false`), writes suggestions under `sidecar/out/`, and never changes `fb_status`. The wiki never holds the model key.

## What is *not* implemented yet

| Piece | Notes |
|-------|--------|
| API module for LLM workers | Would need a bot right + token, separate from public submit |
| Writing LLM output back into MW | Would need new columns or a talk-page bot |
| Auto-changing `fb_status` | Intentionally left to humans for now |

## Safe defaults

- Export only for users with `saintapediafeedback-view`.  
- Never export `fb_ip_hash` or contact email to a third-party model by default.  
- Cap batch size (store already limits).  
- Idempotency via `fb_llm_processed`.

## Next slices

1. Writing model output back into MW (new columns or a talk-page bot) — still editor-owned status.
2. Wire the sidecar into Canasta compose so the webhook hostname works from `dev-web`.
3. Integration test against a live wiki + mock HTTP endpoint.

Editors can still **Export as JSON** from the dashboard and run analysis offline.
