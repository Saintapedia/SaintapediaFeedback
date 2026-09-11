#!/usr/bin/env python3
"""Unit tests for the sidecar's HTTP-layer hardening (F-06, stdlib only)."""

import unittest

from server import MAX_CONTENT_LENGTH, is_loopback_host, parse_content_length


class ParseContentLengthTests(unittest.TestCase):
    def test_missing_header_is_none(self):
        self.assertIsNone(parse_content_length(None))

    def test_non_numeric_header_is_none(self):
        self.assertIsNone(parse_content_length("abc"))
        self.assertIsNone(parse_content_length("12.5"))
        self.assertIsNone(parse_content_length(""))
        self.assertIsNone(parse_content_length("  "))

    def test_negative_header_is_none(self):
        self.assertIsNone(parse_content_length("-1"))
        self.assertIsNone(parse_content_length("-2000000"))

    def test_oversized_header_is_none(self):
        self.assertIsNone(parse_content_length(str(MAX_CONTENT_LENGTH + 1)))

    def test_zero_is_valid(self):
        self.assertEqual(parse_content_length("0"), 0)

    def test_ordinary_value_is_valid(self):
        self.assertEqual(parse_content_length("1234"), 1234)

    def test_max_boundary_is_valid(self):
        self.assertEqual(parse_content_length(str(MAX_CONTENT_LENGTH)), MAX_CONTENT_LENGTH)

    def test_surrounding_whitespace_is_tolerated(self):
        self.assertEqual(parse_content_length(" 42 "), 42)

    def test_leading_plus_or_sign_is_rejected(self):
        # A well-formed Content-Length is digits only; "+5" is not.
        self.assertIsNone(parse_content_length("+5"))


class IsLoopbackHostTests(unittest.TestCase):
    def test_ipv4_loopback(self):
        self.assertTrue(is_loopback_host("127.0.0.1"))
        self.assertTrue(is_loopback_host("127.0.0.2"))

    def test_ipv6_loopback(self):
        self.assertTrue(is_loopback_host("::1"))

    def test_localhost_name(self):
        self.assertTrue(is_loopback_host("localhost"))

    def test_empty_string_is_all_interfaces_not_loopback(self):
        # http.server binds "" to every interface (like 0.0.0.0), so it must
        # never be treated as loopback-safe -- that would let the
        # non-loopback-requires-a-token startup check in main() be bypassed
        # by setting SIDECAR_HOST="".
        self.assertFalse(is_loopback_host(""))

    def test_non_loopback_ipv4(self):
        self.assertFalse(is_loopback_host("0.0.0.0"))
        self.assertFalse(is_loopback_host("10.0.0.5"))
        self.assertFalse(is_loopback_host("203.0.113.5"))

    def test_hostname_is_not_assumed_loopback(self):
        self.assertFalse(is_loopback_host("sidecar.internal.example"))


if __name__ == "__main__":
    unittest.main()
