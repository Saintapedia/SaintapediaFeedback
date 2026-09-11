#!/usr/bin/env python3
"""HTTP webhook for ProcessFeedbackLlm.php.

POST /hooks/feedback  JSON {count, items}
Optional Authorization: Bearer $SAINTAPEDIA_LLM_WEBHOOK_TOKEN

Does not talk to MediaWiki. On success (2xx) the maintenance script marks
fb_llm_processed. Missing XAI_API_KEY or model errors are 5xx so rows stay pending.
"""

from __future__ import annotations

import hmac
import ipaddress
import json
import os
import re
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import triage

HOST = os.environ.get("SIDECAR_HOST", "127.0.0.1")
PORT = int(os.environ.get("SIDECAR_PORT", "8787"))
OUT_DIR = Path(os.environ.get("TRIAGE_OUT", Path(__file__).resolve().parent / "out"))

# Reject a Content-Length outside this range rather than trust it blindly
# (F-06): a missing/non-numeric/negative header used to raise an uncaught
# ValueError, and a negative value passed the old "> 2_000_000" upper-bound
# check and reached rfile.read(length), which for a negative length reads
# until the client closes the connection instead of a bounded amount.
MAX_CONTENT_LENGTH = 2_000_000

# How long a handler will block waiting on a slow/incomplete request before
# giving up (F-06). Without this, ThreadingHTTPServer has no read timeout and
# a client that opens a connection and trickles (or never sends) its body can
# occupy a worker thread indefinitely.
REQUEST_TIMEOUT_SECONDS = 30

_CONTENT_LENGTH_RE = re.compile(r"[0-9]+")


def _expected_token() -> str:
    return os.environ.get("SAINTAPEDIA_LLM_WEBHOOK_TOKEN", "").strip()


def bearer_ok(got: str, expected: str) -> bool:
    return hmac.compare_digest(got, f"Bearer {expected}")


def parse_content_length(value: str | None) -> int | None:
    """Validates a Content-Length header value.

    Returns the parsed length, or None if the header is missing, is not a
    base-10 non-negative integer, or falls outside [0, MAX_CONTENT_LENGTH].
    Callers must distinguish "missing" from "invalid" themselves (411 vs
    400) rather than silently treating either as a length of 0.
    """
    if value is None:
        return None
    value = value.strip()
    if not _CONTENT_LENGTH_RE.fullmatch(value):
        return None
    length = int(value)
    if length > MAX_CONTENT_LENGTH:
        return None
    return length


def is_loopback_host(host: str) -> bool:
    """True if `host` only ever binds a loopback interface.

    An empty string is deliberately NOT treated as loopback: Python's
    socket layer binds "" to all interfaces (equivalent to 0.0.0.0), which
    is the opposite of loopback-only.
    """
    if host == "localhost":
        return True
    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False


class Handler(BaseHTTPRequestHandler):
    # See socketserver.StreamRequestHandler.setup(): when this is not None
    # it is applied to the connection's socket before each request.
    timeout = REQUEST_TIMEOUT_SECONDS

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
            if not bearer_ok(got, expected):
                self._send(401, {"error": "unauthorized"})
                return
        raw_length = self.headers.get("Content-Length")
        length = parse_content_length(raw_length)
        if length is None:
            if raw_length is None:
                self._send(411, {"error": "Content-Length required"})
            else:
                self._send(400, {"error": "invalid Content-Length"})
            return
        try:
            body = self.rfile.read(length) if length else b""
        except (TimeoutError, OSError):
            # Client stalled past self.timeout; connection is torn down by
            # the framework after this returns. Nothing was processed.
            return
        try:
            raw = json.loads(body or b"{}")
        except json.JSONDecodeError:
            self._send(400, {"error": "invalid JSON"})
            return
        result = triage.handle_batch(raw, triage.client_from_env(), OUT_DIR)
        status = int(result.pop("status"))
        self._send(status, result)


def main() -> None:
    if not is_loopback_host(HOST) and not _expected_token():
        print(
            f"refusing to start: SIDECAR_HOST={HOST!r} is not loopback-only and "
            "SAINTAPEDIA_LLM_WEBHOOK_TOKEN is not set. Set the token before "
            "binding a non-loopback address, or bind 127.0.0.1 (default).",
            file=sys.stderr,
        )
        sys.exit(1)
    httpd = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"sidecar listening on http://{HOST}:{PORT}/hooks/feedback  out={OUT_DIR}")
    httpd.serve_forever()


if __name__ == "__main__":
    main()
