#!/usr/bin/env python3
"""Unit tests for the SpaceXAI feedback sidecar (stdlib only)."""

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import triage


class FakeClient:
    def __init__(self, text=None, error=None):
        self.text = text
        self.error = error
        self.calls = []

    def complete(self, system, user):
        self.calls.append((system, user))
        if self.error:
            raise triage.ModelError(self.error)
        return self.text


class PayloadTests(unittest.TestCase):
    def test_sanitize_drops_pii_keys(self):
        raw = {
            "count": 1,
            "items": [
                {
                    "id": 7,
                    "pageTitle": "Example",
                    "categories": ["outdated"],
                    "comment": "Fix the date",
                    "status": "new",
                    "email": "reader@example.org",
                    "fb_contact_email": "x@y.z",
                    "fb_ip_hash": "abc",
                    "workNote": "secret",
                }
            ],
        }
        batch = triage.sanitize_batch(raw)
        item = batch["items"][0]
        self.assertEqual(item["id"], 7)
        self.assertNotIn("email", item)
        self.assertNotIn("fb_contact_email", item)
        self.assertNotIn("fb_ip_hash", item)
        self.assertNotIn("workNote", item)

    def test_empty_batch(self):
        batch = triage.sanitize_batch({"count": 0, "items": []})
        self.assertEqual(batch["items"], [])


class HandleBatchTests(unittest.TestCase):
    def test_empty_does_not_call_model(self):
        client = FakeClient(text="[]")
        with tempfile.TemporaryDirectory() as tmp:
            result = triage.handle_batch({"items": []}, client, Path(tmp))
        self.assertEqual(result["status"], 200)
        self.assertEqual(result["triage"], [])
        self.assertEqual(client.calls, [])

    def test_missing_key_is_503(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = triage.handle_batch(
                {"items": [{"id": 1, "comment": "x", "categories": [], "pageTitle": "P", "status": "new"}]},
                None,
                Path(tmp),
            )
        self.assertEqual(result["status"], 503)
        self.assertIn("XAI_API_KEY", result["error"])

    def test_model_error_is_502_and_writes_nothing(self):
        client = FakeClient(error="upstream down")
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp)
            result = triage.handle_batch(
                {"items": [{"id": 1, "comment": "x", "categories": [], "pageTitle": "P", "status": "new"}]},
                client,
                out,
            )
            self.assertEqual(result["status"], 502)
            self.assertEqual(list(out.iterdir()), [])

    def test_success_parses_json_and_writes_file(self):
        payload = json.dumps(
            [
                {
                    "id": 1,
                    "priority": "high",
                    "summary": "Date is wrong",
                    "suggested_action": "edit",
                }
            ]
        )
        client = FakeClient(text=payload)
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp)
            result = triage.handle_batch(
                {"items": [{"id": 1, "comment": "Fix date", "categories": ["outdated"], "pageTitle": "P", "status": "new"}]},
                client,
                out,
            )
            self.assertEqual(result["status"], 200)
            self.assertEqual(result["triage"][0]["suggested_action"], "edit")
            written = list(out.glob("*.json"))
            self.assertEqual(len(written), 1)
            saved = json.loads(written[0].read_text())
            self.assertEqual(saved["triage"][0]["id"], 1)

    def test_prompt_does_not_include_dropped_pii(self):
        client = FakeClient(text="[]")
        with tempfile.TemporaryDirectory() as tmp:
            triage.handle_batch(
                {
                    "items": [
                        {
                            "id": 2,
                            "pageTitle": "P",
                            "categories": [],
                            "comment": "hello",
                            "status": "new",
                            "email": "hidden@example.org",
                        }
                    ]
                },
                client,
                Path(tmp),
            )
        _system, user = client.calls[0]
        self.assertNotIn("hidden@example.org", user)


class ParseTriageTests(unittest.TestCase):
    def test_fenced_json(self):
        text = "```json\n[{\"id\": 3, \"priority\": \"low\", \"summary\": \"ok\", \"suggested_action\": \"dismiss\"}]\n```"
        rows = triage.parse_triage_text(text)
        self.assertEqual(rows[0]["id"], 3)

    def test_fenced_json_with_nested_array(self):
        inner = '[{"id": 1, "priority": "high", "summary": "x", "suggested_action": "edit", "categories": ["outdated"]}]'
        rows = triage.parse_triage_text("```json\n" + inner + "\n```")
        self.assertEqual(rows[0]["id"], 1)
        self.assertEqual(rows[0]["categories"], ["outdated"])

    def test_invalid_text_raises(self):
        with self.assertRaises(triage.ModelError):
            triage.parse_triage_text("not json")

    def test_row_must_be_complete_dict(self):
        with self.assertRaises(triage.ModelError):
            triage.parse_triage_text("[null]")
        with self.assertRaises(triage.ModelError):
            triage.parse_triage_text("[1, 2]")
        with self.assertRaises(triage.ModelError):
            triage.parse_triage_text('[{"id": 1}]')


class FilenameTests(unittest.TestCase):
    def test_out_names_do_not_collide(self):
        a = triage.out_filename(3)
        b = triage.out_filename(3)
        self.assertNotEqual(a, b)


class AuthTests(unittest.TestCase):
    def test_bearer_compare(self):
        import server

        self.assertTrue(server.bearer_ok("Bearer secret", "secret"))
        self.assertFalse(server.bearer_ok("Bearer other", "secret"))
        self.assertFalse(server.bearer_ok("", "secret"))


if __name__ == "__main__":
    unittest.main()
