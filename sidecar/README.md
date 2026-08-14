# SpaceXAI feedback sidecar

Consumes the JSON that `ProcessFeedbackLlm.php` POSTs. Calls SpaceXAI (`https://api.x.ai/v1/responses`, model `grok-4.6` by default) and writes triage suggestions to disk. **Does not** change MediaWiki `fb_status`.

The wiki never holds `XAI_API_KEY`. If the key is missing or xAI fails, the sidecar returns **5xx** so rows stay unprocessed.

## Run

```bash
export XAI_API_KEY=...                    # https://console.x.ai
export SAINTAPEDIA_LLM_WEBHOOK_TOKEN=...  # same as $wgSaintapediaFeedbackLlmWebhookToken
# optional: XAI_MODEL=grok-4.6  SIDECAR_PORT=8787  TRIAGE_OUT=./out

python3 server.py
```

LocalSettings on the wiki:

```php
$wgSaintapediaFeedbackLlmWebhook = 'http://127.0.0.1:8787/hooks/feedback';
$wgSaintapediaFeedbackLlmWebhookToken = getenv( 'SAINTAPEDIA_LLM_WEBHOOK_TOKEN' ) ?: '';
```

Then:

```bash
php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php --dry-run
php maintenance/run.php extensions/SaintapediaFeedback/maintenance/ProcessFeedbackLlm.php
```

If MediaWiki runs in Docker (Canasta), `127.0.0.1` inside the web container is not the host. Use a host IP or attach the sidecar to the same compose network and set the webhook to `http://sidecar:8787/hooks/feedback`.

## Tests

```bash
cd sidecar && python3 -m unittest test_triage.py
```

Stdlib only — no pip packages.
