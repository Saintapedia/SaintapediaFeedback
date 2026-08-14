#!/usr/bin/env python3
"""HTTP webhook for ProcessFeedbackLlm.php.

POST /hooks/feedback  JSON {count, items}
Optional Authorization: Bearer $SAINTAPEDIA_LLM_WEBHOOK_TOKEN

Does not talk to MediaWiki. On success (2xx) the maintenance script marks
fb_llm_processed. Missing XAI_API_KEY or model errors are 5xx so rows stay pending.
"""

from __future__ import annotations

import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import triage

HOST = os.environ.get("SIDECAR_HOST", "127.0.0.1")
PORT = int(os.environ.get("SIDECAR_PORT", "8787"))
OUT_DIR = Path(os.environ.get("TRIAGE_OUT", Path(__file__).resolve().parent / "out"))


def _expected_token() -> str:
    return os.environ.get("SAINTAPEDIA_LLM_WEBHOOK_TOKEN", "").strip()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        print(f"sidecar: {self.address_string()} {fmt % args}")

    def _send(self, code: int, body: dict) -> None:
        raw = json.dumps(body, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        if self.path.rstrip("/") == "/health":
            self._send(200, {"ok": True, "has_key": bool(os.environ.get("XAI_API_KEY"))})
            return
        self._send(404, {"error": "not found"})

    def do_POST(self):
        if self.path.rstrip("/") != "/hooks/feedback":
            self._send(404, {"error": "not found"})
            return
        expected = _expected_token()
        if expected:
            got = self.headers.get("Authorization", "")
            if got != f"Bearer {expected}":
                self._send(401, {"error": "unauthorized"})
                return
        length = int(self.headers.get("Content-Length", "0") or 0)
        if length > 2_000_000:
            self._send(413, {"error": "payload too large"})
            return
        try:
            raw = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            self._send(400, {"error": "invalid JSON"})
            return
        result = triage.handle_batch(raw, triage.client_from_env(), OUT_DIR)
        status = int(result.pop("status"))
        self._send(status, result)


def main() -> None:
    httpd = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"sidecar listening on http://{HOST}:{PORT}/hooks/feedback  out={OUT_DIR}")
    httpd.serve_forever()


if __name__ == "__main__":
    main()
