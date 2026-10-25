"""The local router's byte-range support (tools/local/router.php).

`php -S` serves a static file whole: it ignores the `Range` header and
answers `200` with the entire body. Every target this stack stands in for
(LiteSpeed in production) answers `206` with a `Content-Range`, and a
browser seeking video relies on that — the local instance answered 200
instead, so a `<video>` could not seek and Chrome aborted the transfer
(net::ERR_ABORTED in the page's network log). These tests pin the router's
behaviour directly, on a bare document root: no WordPress, no MySQL.

The server is `php -S` with the repository router, exactly as
`md_local.server` starts an instance's.
"""

from __future__ import annotations

import http.client
import shutil
import socket
import subprocess
import time
from collections.abc import Iterator
from pathlib import Path

import pytest

ROUTER = Path(__file__).resolve().parents[1] / "router.php"

# 4 KiB of distinctive bytes: a range's content is compared against the
# source, not just its length.
BODY = bytes(range(256)) * 16


def _php() -> str:
    php = shutil.which("php")
    if php is None:
        pytest.skip("PHP is not installed")
    return php


def _free_port() -> int:
    with socket.socket() as candidate:
        candidate.bind(("127.0.0.1", 0))
        return int(candidate.getsockname()[1])


@pytest.fixture(scope="module")
def server(tmp_path_factory: pytest.TempPathFactory) -> Iterator[tuple[int, Path]]:
    """A `php -S` with the repository router and one static file."""
    root = tmp_path_factory.mktemp("docroot")
    asset = root / "clip.mp4"
    asset.write_bytes(BODY)
    port = _free_port()
    process = subprocess.Popen(
        [_php(), "-S", f"127.0.0.1:{port}", "-t", str(root), str(ROUTER)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.05)
        else:  # pragma: no cover - a server that never binds is the failure
            pytest.fail("the router's server never accepted a connection")
        yield port, asset
    finally:
        process.terminate()
        process.wait(timeout=10)


def _get(
    port: int, target: str, headers: dict[str, str] | None = None
) -> tuple[int, dict[str, str], bytes]:
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=30)
    connection.request("GET", target, headers=headers or {})
    answer = connection.getresponse()
    body = answer.read()
    status = answer.status
    supplied = {key.lower(): value for key, value in answer.getheaders()}
    connection.close()
    return status, supplied, body


def test_a_plain_request_is_unchanged(server: tuple[int, Path]) -> None:
    """No `Range` header: the whole file, 200 — the built-in server's own answer."""
    port, _asset = server
    status, headers, body = _get(port, "/clip.mp4")
    assert status == 200
    assert body == BODY
    assert headers.get("content-length") == str(len(BODY))


def test_a_closed_range_is_206_with_exactly_those_bytes(
    server: tuple[int, Path],
) -> None:
    """`bytes=0-1023`: the production answer a seeking browser needs."""
    port, _asset = server
    status, headers, body = _get(port, "/clip.mp4", {"Range": "bytes=0-1023"})
    assert status == 206
    assert headers.get("content-range") == f"bytes 0-1023/{len(BODY)}"
    assert headers.get("content-length") == "1024"
    assert headers.get("accept-ranges") == "bytes"
    assert body == BODY[:1024]


def test_a_mid_file_range_returns_the_bytes_at_that_offset(
    server: tuple[int, Path],
) -> None:
    """A seek mid-file reads the file's own bytes, not the first chunk again."""
    port, _asset = server
    status, headers, body = _get(port, "/clip.mp4", {"Range": "bytes=1000-1999"})
    assert status == 206
    assert headers.get("content-range") == f"bytes 1000-1999/{len(BODY)}"
    assert body == BODY[1000:2000]


def test_an_open_ended_range_runs_to_the_end(server: tuple[int, Path]) -> None:
    """`bytes=100-`: what a player asks for when it resumes from an offset."""
    port, _asset = server
    status, headers, body = _get(port, "/clip.mp4", {"Range": "bytes=100-"})
    assert status == 206
    assert headers.get("content-range") == f"bytes 100-{len(BODY) - 1}/{len(BODY)}"
    assert body == BODY[100:]


def test_a_suffix_range_is_the_last_bytes(server: tuple[int, Path]) -> None:
    """`bytes=-500`: the moov-atom tail read a player starts with."""
    port, _asset = server
    status, headers, body = _get(port, "/clip.mp4", {"Range": "bytes=-500"})
    assert status == 206
    assert (
        headers.get("content-range")
        == f"bytes {len(BODY) - 500}-{len(BODY) - 1}/{len(BODY)}"
    )
    assert body == BODY[-500:]


def test_a_range_past_the_end_is_416(server: tuple[int, Path]) -> None:
    """An unsatisfiable range says so, and names the real size."""
    port, _asset = server
    status, headers, _body = _get(port, "/clip.mp4", {"Range": "bytes=999999999-"})
    assert status == 416
    assert headers.get("content-range") == f"bytes */{len(BODY)}"


def test_a_multi_range_header_falls_back_to_the_whole_file(
    server: tuple[int, Path],
) -> None:
    """Multipart ranges are not this stack's contract: nothing asks for them.

    The header is not a single `N-M`, so the router leaves the response to
    the built-in server rather than inventing a multipart body.
    """
    port, _asset = server
    status, _headers, body = _get(port, "/clip.mp4", {"Range": "bytes=0-1,5-6"})
    assert status == 200
    assert body == BODY
