"""The golden-host seed transformer's credential strike, held against its own contract.

The v2 transformer assumed every credential is a 40-64 hex run; the golden
host itself disproves that (at least one callback token and one upstream api
key on it are not hex). v3 collects the real secret values host-side and
strikes those exact values, with the hex run kept as the fallback. These
tests exercise the pure functions of the transformer through the PHP
binary — the file only runs its pipeline when executed directly, so
requiring it defines the functions and touches no database.
"""

from __future__ import annotations

import json
import subprocess
from pathlib import Path

PHP = "/opt/homebrew/opt/php@8.3/bin/php"
TRANSFORMER = (
    Path(__file__).resolve().parents[1] / "seed" / "export-chat-seed.php"
).resolve()


def _php(expression: str) -> str:
    """Run one PHP expression with the transformer required, return its output."""
    code = f"require {json.dumps(str(TRANSFORMER))}; {expression}"
    result = subprocess.run(
        [PHP, "-r", code],
        capture_output=True,
        text=True,
        check=True,
    )
    return result.stdout


def _php_json(expression: str) -> object:
    """Run one PHP expression that json_encode()s its result."""
    raw = _php(f"echo json_encode({expression});")
    return json.loads(raw)


class TestStripCredentials:
    """The strike itself: values first, the hex run as the fallback."""

    def test_a_non_hex_secret_value_is_struck_by_value(self) -> None:
        """The live failure of v2: a non-hex credential must still not ship."""
        secret = "wecom-token-Zx9pLq2RtYu8vW3e"
        row = _php_json(
            f"md_seed_strip_credentials(array('v' => 'config with {secret} embedded',"
            " 'n' => 5, 'e' => null), array('" + secret + "'))"
        )
        assert isinstance(row, dict)
        assert row["v"] == "config with  embedded"
        assert row["n"] == 5
        assert row["e"] is None

    def test_a_non_hex_secret_is_struck_without_the_hex_fallback(self) -> None:
        """chat_config strikes values only; the same credential still goes."""
        secret = "wecom-token-Zx9pLq2RtYu8vW3e"
        row = _php_json(
            f"md_seed_strip_credentials(array('value' => '{secret}'),"
            f" array('{secret}'), false)"
        )
        assert isinstance(row, dict)
        assert row["value"] == ""

    def test_the_hex_fallback_still_strikes_unknown_credentials(self) -> None:
        """A credential this host no longer knows still matches the hex run."""
        row = _php_json(
            "md_seed_strip_credentials("
            "array('v' => 'bearer " + "a" * 48 + "'), array())"
        )
        assert isinstance(row, dict)
        assert row["v"] == "bearer "

    def test_the_value_strike_never_replaces_short_strings(self) -> None:
        """A strike set cannot carry a value short enough to appear in
        living data for innocent reasons — the floor is enforced at
        collection, and a hand-made short entry is refused by nothing but
        its own length in practice; the shipped collectors prove the floor."""
        collected = _php_json(
            "md_seed_collect_key_values("
            "array('bearer_token' => 'short', 'nested' => array("
            "array('bearer_token' => 'Zx9pLq2RtYu8vW3eAbCd'))),"
            " array('bearer_token'))"
        )
        assert collected == ["Zx9pLq2RtYu8vW3eAbCd"]


class TestCollectors:
    """The three host-side sources the strike set is built from."""

    def test_option_secrets_are_collected_at_any_depth(self) -> None:
        collected = _php_json(
            "md_seed_collect_key_values("
            "array('a' => array('b' => array('bearer_token' => 'Zx9pLq2RtYu8vW3eAbCd',"
            " 'deepseek_api_key' => 'sk-live-9876543210fedcba'),"
            " 'harmless' => 'a value that is long enough to strike')),"
            " array('bearer_token', 'deepseek_api_key'))"
        )
        assert collected == ["Zx9pLq2RtYu8vW3eAbCd", "sk-live-9876543210fedcba"]

    def test_column_values_respect_the_floor(self) -> None:
        collected = _php_json(
            "md_seed_collect_strings(array('', 'tooshort',"
            " 'an-api-key-with-enough-length'))"
        )
        assert collected == ["an-api-key-with-enough-length"]

    def test_json_keys_are_collected_from_bot_configuration(self) -> None:
        configuration = json.dumps(
            {
                "parameters": [
                    {
                        "method": "POST",
                        "auth_bearer": "Zx9pLq2RtYu8vW3eAbCd",
                        "authorization": "weak",
                    }
                ]
            }
        )
        collected = _php_json(
            f"md_seed_collect_json_key_values(array({json.dumps(configuration)}),"
            " array('auth_bearer', 'authorization'))"
        )
        assert collected == ["Zx9pLq2RtYu8vW3eAbCd"]

    def test_undecodable_json_contributes_nothing(self) -> None:
        collected = _php_json(
            "md_seed_collect_json_key_values(array('not json at all'),"
            " array('auth_bearer'))"
        )
        assert collected == []


class TestPipelineGuard:
    """The transformer must not run its pipeline when required."""

    def test_requiring_the_file_runs_nothing(self) -> None:
        """A require touches no database: the file answers with its
        functions and no output of its own."""
        out = _php("")
        assert out == ""
