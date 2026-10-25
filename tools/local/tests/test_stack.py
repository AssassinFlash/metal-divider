"""The local stack end to end: real PHP 8.3, WP-CLI, MySQL and WordPress.

The test drives `md-local` as `just` does, with a data root of its own and an
instance name of its own (pytest_<run>). It needs this Mac's tools and the
machine's plugin archives (README.md); CI, which has none of them, skips the
module; anywhere else a missing tool or archive fails it.

The instance is fully recycled at the end (AGENTS.md §0): server stopped,
database dropped, directory and registry entry removed, the instance's Redis
prefix swept.
"""

from __future__ import annotations

import http.client
import os
import secrets as pysecrets
import shutil
import subprocess
import sys
from collections.abc import Iterator
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlsplit

import pytest
from pytest import MonkeyPatch

from md_local import archives, redis, server
from md_local.cli import require_php
from md_local.instance import Instance, database_of
from md_local.licenses import (
    LIVECANVAS_FILE,
    LIVECANVAS_VARIABLE,
    SEOPRESS_FILE,
    SEOPRESS_VARIABLE,
    env_value,
)
from md_local.licenses import directory as licence_directory
from md_local.mysql import CREDENTIALS, MySQL
from md_local.registry import Registry

# Every run names its own instance, so its database and files are its own.
RUN = f"{os.getpid()}_{pysecrets.token_hex(3)}"
NAME = f"pytest_{RUN}"
CLI = Path(sys.executable).with_name("md-local")


def licence_values() -> list[str]:
    """The licence values this Mac holds (empty where the files are absent)."""
    return [
        value
        for file, variable in (
            (LIVECANVAS_FILE, LIVECANVAS_VARIABLE),
            (SEOPRESS_FILE, SEOPRESS_VARIABLE),
        )
        if (value := env_value(licence_directory() / file, variable)) is not None
    ]


def missing_tools() -> list[str]:
    """What this Mac lacks for the stack: md-local's own checks, MySQL, the archives."""
    missing: list[str] = []
    try:
        require_php()  # PHP 8.3 in $PHP (`just test` sets it) and WP-CLI 2.12
    except RuntimeError as error:
        missing.append(str(error))
    try:
        MySQL(CREDENTIALS).query("SELECT 1")
    except (OSError, RuntimeError) as error:
        missing.append(f"a MySQL server that `mysql -u root` reaches ({error})")
    for slug in archives.KNOWN:
        try:
            archives.verified(slug)
        except archives.ArchiveError as error:
            missing.append(str(error))
    return missing


pytestmark = pytest.mark.skipif(
    missing_tools() != [],
    reason=f"this machine cannot build the stack: {'; '.join(missing_tools())}",
)


@dataclass
class Response:
    status: int
    headers: http.client.HTTPMessage
    body: str


class Stack:
    def __init__(self, home: Path, env: dict[str, str]) -> None:
        self.home = home
        self.env = env

    def cli(self, *args: str) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(
            [str(CLI), *args], capture_output=True, text=True, env=self.env, check=False
        )
        if result.returncode != 0:
            pytest.fail(
                f"md-local {' '.join(args)} failed:\n{result.stdout}{result.stderr}"
            )
        return result

    def wp(self, *args: str) -> str:
        return self.cli("wp", NAME, *args).stdout.strip()

    def active_plugins(self) -> list[str]:
        return self.wp("plugin", "list", "--status=active", "--field=name").splitlines()

    def instance(self) -> Instance:
        instance = Registry(self.home).get(NAME)
        assert instance is not None
        return instance


def request(port: int, target: str) -> Response:
    """One HTTP request to 127.0.0.1, redirects not followed, no proxy."""
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=60)
    connection.request("GET", target)
    answer = connection.getresponse()
    body = answer.read().decode(errors="replace")
    headers = answer.headers
    connection.close()
    return Response(answer.status, headers, body)


def sweep_leftovers() -> None:
    """Drop what aborted runs left: pytest-named databases and instance directories.

    The fixture below recycles its own instance; a run that died before the
    teardown leaves md_pytest_* behind in the one shared MySQL and under the
    data root. This sweep runs first, so no leftover survives two runs
    (AGENTS.md §0: what a task no longer needs goes).
    """
    mysql = MySQL(CREDENTIALS)
    found = mysql.query("SHOW DATABASES LIKE 'md\\_pytest\\_%'").split()
    for database in found:
        mysql.drop_database(database.strip())
    data_root = Path(
        os.environ.get("MD_LOCAL_HOME", str(Path.home() / "metal-divider-local"))
    )
    for leftover in data_root.glob("pytest_*"):
        shutil.rmtree(leftover, ignore_errors=True)


@pytest.fixture(autouse=True, scope="session")
def _no_leftovers() -> None:
    sweep_leftovers()


@pytest.fixture()
def stack(tmp_path: Path, monkeypatch: MonkeyPatch) -> Iterator[Stack]:
    home = tmp_path / "md-local-stack"
    monkeypatch.setenv("MD_LOCAL_HOME", str(home))
    made = Stack(home, {**os.environ, "MD_LOCAL_HOME": str(home)})
    yield made
    # The recycle (AGENTS.md §0, docs/agents/local-storage.md): stop the server,
    # drop the database, delete the directory, sweep the Redis prefix, remove
    # the registry entry. Nothing of this run stays behind.
    instance = Registry(home).get(NAME)
    if instance is not None:
        server.stop(instance)
        MySQL(CREDENTIALS).drop_database(database_of(NAME))
        redis.forget(instance)
        if instance.dir.exists():
            shutil.rmtree(instance.dir)
        Registry(home).deregister(NAME)


def test_the_whole_stack(stack: Stack) -> None:
    stack.cli("up", NAME)
    instance = stack.instance()

    # The plugin face: every pinned plugin active.
    active = stack.active_plugins()
    for slug in (
        "woocommerce",
        "advanced-custom-fields",
        "windpress",
        "wp-seopress",
        "wp-seopress-pro",
        "livecanvas",
        "28-wp-markdown-editor",
        "md-site",
        "md-inquiry",
    ):
        assert slug in active, f"{slug} is not active (active: {active})"
    # Polylang is phase 2: the slot in versions.py, never the plugin.
    assert "polylang" not in stack.wp("plugin", "list")

    # The theme face: picostrap5 from the archive, the checkout's md-child
    # active on top of it (spec 17 §2.1, branch research/spec-theme-base).
    assert "picostrap5" in stack.wp("theme", "list", "--field=name")
    assert stack.wp("option", "get", "stylesheet") == "md-child"

    # The URL scheme: journal articles under /blog/<slug>/, categories under
    # /category/<slug>/. Two options, so provisioning writes them and refresh
    # repairs them (md_local.permalinks) — the same two values the deploy keeps
    # on staging and production.
    assert stack.wp("option", "get", "permalink_structure") == "/blog/%postname%/"
    assert stack.wp("option", "get", "category_base") == "category"

    # The isolation headers, and a page that answers.
    home = request(instance.port, "/")
    assert home.status == 200
    assert home.headers["Content-Security-Policy"].startswith("default-src 'self'")
    assert home.headers["X-Robots-Tag"] == "noindex, nofollow"

    # Outbound HTTP is refused and logged; wp_mail() lands as a file.
    stack.wp(
        "eval",
        "wp_remote_get( 'https://example.net/' ); "
        "wp_mail( 'to@example.test', 'stack test', 'the body' );",
    )
    assert "https://example.net/" in instance.isolation_log.read_text()
    messages = list(instance.mail_dir.glob("*.eml"))
    assert len(messages) == 1
    assert "the body" in messages[0].read_text()

    # A one-time login link that signs in exactly once.
    url = stack.cli("login", NAME).stdout.strip()
    target = urlsplit(url)
    path = target.path + (f"?{target.query}" if target.query else "")
    assert request(instance.port, path).status == 302
    assert request(instance.port, path).status == 403

    # `status` lists the instance with its database and port.
    table = stack.cli("status").stdout
    assert NAME in table and database_of(NAME) in table
    assert instance.url in table

    # `down` stops the server and keeps the data.
    stack.cli("down", NAME)
    assert server.process(instance).pid() is None
    assert MySQL(CREDENTIALS).database_exists(database_of(NAME))

    # `reset` rebuilds the whole face: plugins, licence state, isolation.
    stack.cli("reset", NAME)
    assert set(stack.active_plugins()) >= {
        "woocommerce",
        "advanced-custom-fields",
        "windpress",
        "wp-seopress",
        "wp-seopress-pro",
        "livecanvas",
        "28-wp-markdown-editor",
        "md-site",
        "md-inquiry",
    }
    assert stack.wp("option", "get", "stylesheet") == "md-child"
    if licence_values():
        assert stack.wp("option", "get", "seopress_pro_license_status") == "valid"
        assert stack.wp("option", "get", "lc_apikey") != ""
    assert request(instance.port, "/").headers["X-Robots-Tag"] == "noindex, nofollow"
    # `reset` provisions the URL scheme too, and `up` repairs it: the two
    # options are database state, so a checkout that changes them or a
    # database imported from another site would otherwise keep drift that no
    # file deploy can carry away. Drift them by hand, then prove `up` repairs
    # them and a second `up` writes nothing (apply() is idempotent by
    # readback, tools/local/tests/test_permalinks.py).
    stack.wp("option", "update", "permalink_structure", "/%postname%/")
    stack.wp("option", "update", "category_base", "")
    assert stack.wp("option", "get", "permalink_structure") == "/%postname%/"

    stack.cli("up", NAME)
    assert stack.wp("option", "get", "permalink_structure") == "/blog/%postname%/"
    assert stack.wp("option", "get", "category_base") == "category"

    # A second `up` on a repaired site writes nothing, and says so.
    again = stack.cli("up", NAME).stdout
    assert "permalinks /blog/%postname%/ categories category (already set)" in again, (
        again
    )


def test_a_bare_instance_is_wordpress_without_the_plugin_face(stack: Stack) -> None:
    """`up --bare`: the mu-smoke CI job's instance (docs/specs/23-mu-plugins.md
    §5.4, branch research/spec-mu-plugins).

    No plugin face, no licence state, and the build never reaches the licensed
    archives — which is what lets CI, where they do not exist, build it too.
    What stays is the rest of the stack: WordPress, the router, the local
    mu-plugins, and a site that answers.
    """
    stack.cli("up", NAME, "--bare")
    instance = stack.instance()

    home = request(instance.port, "/")
    assert home.status == 200
    assert home.headers["X-Robots-Tag"] == "noindex, nofollow"

    plugins = stack.wp("plugin", "list", "--field=name").splitlines()
    assert "woocommerce" not in plugins
    assert not (instance.wp_content / "plugins" / "wp-seopress-pro").exists()
