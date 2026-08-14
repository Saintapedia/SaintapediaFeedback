#!/usr/bin/env python3
"""Triage a ProcessFeedbackLlm batch via SpaceXAI (api.x.ai). Does not write to MediaWiki."""

from __future__ import annotations

import json
import os
import re
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ALLOWED_ITEM_KEYS = ("id", "pageId", "pageTitle", "timestamp", "categories", "comment", "status")
XAI_URL = "https://api.x.ai/v1/responses"
DEFAULT_MODEL = "grok-4.6"

SYSTEM_PROMPT = """You are helping wiki editors triage article feedback.
For each item, return a JSON array only (no prose) of objects:
{"id": <int>, "priority": "high"|"medium"|"low", "summary": "<one sentence>",
 "suggested_action": "edit"|"sources"|"dismiss"|"needs_info"}
Do not invent page content. Use only the fields provided.
Do not change workflow status; these are suggestions only."""


class ModelError(Exception):
    pass


def sanitize_batch(raw: Any) -> dict[str, Any]:
    if not isinstance(raw, dict):
        raw = {}
    items = raw.get("items") or []
    clean = []
    for item in items:
        if not isinstance(item, dict):
            continue
        row = {k: item.get(k) for k in ALLOWED_ITEM_KEYS if k in item}
        if "id" not in row:
            continue
        clean.append(row)
    return {"count": len(clean), "items": clean}


def parse_triage_text(text: str) -> list[dict[str, Any]]:
    text = (text or "").strip()
    fence = re.search(r"```(?:json)?\s*(\[.*?\])\s*```", text, re.S)
    if fence:
        text = fence.group(1)
    try:
        data = json.loads(text)
    except json.JSONDecodeError as e:
        raise ModelError(f"model output is not JSON: {e}") from e
    if not isinstance(data, list):
        raise ModelError("model output must be a JSON array")
    return data


def extract_output_text(body: dict[str, Any]) -> str:
    if isinstance(body.get("output_text"), str) and body["output_text"]:
        return body["output_text"]
    chunks = []
    for item in body.get("output") or []:
        if not isinstance(item, dict):
            continue
        for part in item.get("content") or []:
            if isinstance(part, dict) and part.get("type") == "output_text":
                chunks.append(part.get("text") or "")
    return "".join(chunks)


class XaiClient:
    def __init__(self, api_key: str, model: str = DEFAULT_MODEL):
        self.api_key = api_key
        self.model = model

    def complete(self, system: str, user: str) -> str:
        payload = {
            "model": self.model,
            "store": False,
            "input": [
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
        }
        req = urllib.request.Request(
            XAI_URL,
            data=json.dumps(payload).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {self.api_key}",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                body = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", errors="replace")
            raise ModelError(f"xAI HTTP {e.code}: {detail[:300]}") from e
        except urllib.error.URLError as e:
            raise ModelError(f"xAI transport: {e}") from e
        text = extract_output_text(body)
        if not text.strip():
            raise ModelError("xAI returned empty output_text")
        return text


def handle_batch(raw: Any, client: XaiClient | None, out_dir: Path) -> dict[str, Any]:
    batch = sanitize_batch(raw)
    if not batch["items"]:
        return {"status": 200, "count": 0, "triage": [], "error": None}

    if client is None:
        return {
            "status": 503,
            "count": batch["count"],
            "triage": [],
            "error": "XAI_API_KEY is not set; refusing so MediaWiki will not mark rows processed",
        }

    user = json.dumps(batch["items"], ensure_ascii=False)
    try:
        text = client.complete(SYSTEM_PROMPT, user)
        triage_rows = parse_triage_text(text)
    except ModelError as e:
        return {"status": 502, "count": batch["count"], "triage": [], "error": str(e)}

    out_dir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    path = out_dir / f"{stamp}-{batch['count']}.json"
    record = {
        "received": batch,
        "triage": triage_rows,
        "model": getattr(client, "model", DEFAULT_MODEL),
    }
    path.write_text(json.dumps(record, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return {"status": 200, "count": batch["count"], "triage": triage_rows, "file": str(path), "error": None}


def client_from_env() -> XaiClient | None:
    key = os.environ.get("XAI_API_KEY", "").strip()
    if not key:
        return None
    model = os.environ.get("XAI_MODEL", DEFAULT_MODEL).strip() or DEFAULT_MODEL
    return XaiClient(key, model)
