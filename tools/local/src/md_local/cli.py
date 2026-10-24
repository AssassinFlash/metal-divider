"""`md-local`: create, start, stop and reset the local WordPress instances.

The instance name defaults to `dev` on the `just` side (spec §1.1: one
instance, reused by every ticket); this CLI takes it explicitly, as `just`
passes it.
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
from collections.abc import Callable
from pathlib import Path

from md_local import archives, redis, server, versions, wordpress, wpcli
from md_local.checkout import Checkout
from md_local.instance import Instance, check_name, database_of, directory_of, home
from md_local.mysql import CREDENTIALS, MySQL
from md_local.registry import Registry
from md_local.wpcli import WpCli

# Failures the user can act on, printed as one line. Every error class of this
# package (ArchiveError, DownloadError, MySQLError, ProcessError, WpCliError)
# is a RuntimeError; ValueError is a bad instance name.
ERRORS = (RuntimeError, ValueError, subprocess.CalledProcessError)


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    try:
        result: int = args.run(args)
    except ERRORS as error:
        print(f"md-local: {error}", file=sys.stderr)
        return 1
    return result


def up(args: argparse.Namespace) -> int:
    # Everything that can refuse is checked before a new name takes a port.
    check_name(args.name)
    php, checkout = _preconditions()
    registry = Registry(home())
    instance = registry.get(args.name)
    if instance is None:
        _refuse_foreign(args.name)
        instance = registry.register(args.name)
    say = _sayer(instance)
    registry.record(
        Instance(
            instance.name,
            instance.port,
            instance.root,
            str(checkout.root),
            instance.created,
        )
    )
    if instance.is_provisioned():
        wordpress.refresh(instance, php, checkout, say)
    elif instance.dir.exists() or MySQL(CREDENTIALS).database_exists(instance.database):
        raise RuntimeError(
            f"{instance.name} is incomplete (an earlier `up` stopped part-way); "
            f"`just reset {instance.name}` builds it again"
        )
    else:
        say(f"new instance on port {instance.port}")
        wordpress.provision(instance, php, checkout, MySQL(CREDENTIALS), say)
    server.start(instance, php)
    _summary(instance)
    return 0


def down(args: argparse.Namespace) -> int:
    instance = _registered(args.name)
    stopped = ["server"] if server.stop(instance) else []
    print(
        f"{instance.name}: stopped {', '.join(stopped)}"
        if stopped
        else f"{instance.name}: not running"
    )
    return 0


def reset(args: argparse.Namespace) -> int:
    instance = _registered(args.name)
    php, checkout = _preconditions()  # before anything is deleted
    say = _sayer(instance)
    server.stop(instance)
    mysql = MySQL(CREDENTIALS)
    say(f"deleting database {instance.database} and {instance.dir}")
    mysql.drop_database(instance.database)
    if instance.dir.exists():
        shutil.rmtree(instance.dir)
    say(f"redis {redis.forget(instance)}")
    wordpress.provision(instance, php, checkout, mysql, say)
    server.start(instance, php)
    _summary(instance)
    return 0


def login(args: argparse.Namespace) -> int:
    instance = _built(args.name)
    # The login name travels in the environment, so no quoting puts it into PHP code.
    url = WpCli(require_php(), instance).run(
        "eval",
        "echo md_local_login_url( (string) getenv( 'MD_LOCAL_LOGIN' ) );",
        env={"MD_LOCAL_LOGIN": args.user},
    )
    if not url:
        raise RuntimeError(f"{instance.name} has no user {args.user}")
    print(url)
    if server.process(instance).pid() is None:
        print(
            f"(the server is stopped: `just up {instance.name}` first)", file=sys.stderr
        )
    return 0


def status(args: argparse.Namespace) -> int:
    instances = Registry(home()).all()
    if not instances:
        print(f"no instances in {home()}")
        return 0
    rows = [("name", "url", "database", "server", "WordPress", "Woo", "checkout")]
    for instance in instances:
        found = wordpress.versions_of(instance)
        checkout = wordpress.linked_checkout(instance)
        rows.append(
            (
                instance.name,
                instance.url,
                instance.database,
                "running" if server.process(instance).pid() else "stopped",
                found["WordPress"] or "-",
                found["WooCommerce"] or "-",
                str(checkout) if checkout else (instance.checkout or "-"),
            )
        )
    widths = [max(len(row[column]) for row in rows) for column in range(len(rows[0]))]
    for row in rows:
        print(
            "  ".join(
                cell.ljust(width) for cell, width in zip(row, widths, strict=True)
            ).rstrip()
        )
    return 0


def wp(args: argparse.Namespace) -> int:
    instance = _built(args.name)
    return WpCli(require_php(), instance).passthrough(args.args)


def archive(args: argparse.Namespace) -> int:
    archives.collect(args.slug, args.source, _plain_sayer)
    return 0


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="md-local",
        description="Local WordPress instances of metal-divider.com.",
    )
    commands = parser.add_subparsers(required=True, metavar="command")

    def command(
        name: str, run: Callable[[argparse.Namespace], int], help_text: str
    ) -> argparse.ArgumentParser:
        sub = commands.add_parser(name, help=help_text, description=help_text)
        sub.set_defaults(run=run)
        return sub

    sub = command(
        "up", up, "create the instance if it does not exist, then (re)start its server"
    )
    sub.add_argument("name")
    sub = command("down", down, "stop the instance's server")
    sub.add_argument("name")
    sub = command(
        "reset", reset, "delete the instance's database and files, then build it again"
    )
    sub.add_argument("name")
    sub = command("login", login, "print a one-time login link (ten minutes, one use)")
    sub.add_argument("name")
    sub.add_argument("--user", default=wordpress.ADMIN_USER)
    command("status", status, "list the instances")
    sub = command("wp", wp, "run WP-CLI against the instance")
    sub.add_argument("name")
    sub.add_argument("args", nargs=argparse.REMAINDER)
    sub = command(
        "archive",
        archive,
        "collect a licensed plugin into the local archives (a .zip or a directory)",
    )
    sub.add_argument("slug")
    sub.add_argument("source", type=Path)
    return parser


def require_php() -> Path:
    """PHP 8.3 ($PHP; the justfile passes Homebrew's php@8.3), checked with WP-CLI 2.12."""
    name = os.environ.get("PHP", "php")
    found = shutil.which(name)
    check = ""
    if found is not None:
        check = subprocess.run(
            [found, "-r", "echo PHP_MAJOR_VERSION, '.', PHP_MINOR_VERSION;"],
            capture_output=True,
            text=True,
            check=False,
        ).stdout
    if found is None or check != versions.PHP_VERSION:
        raise RuntimeError(
            f"PHP {versions.PHP_VERSION} is required, {name} is {check or 'not runnable'}: "
            "brew install php@8.3, then run through `just` (README.md)"
        )
    wpcli.require_version(Path(found))
    return Path(found)


def _preconditions() -> tuple[Path, Checkout]:
    """What up and reset need, checked before they register, build or delete anything."""
    php = require_php()
    return php, Checkout.here()


def _refuse_foreign(name: str) -> None:
    """A new name must not take over a database or directory that something else made."""
    directory = directory_of(home(), name)
    if directory.exists():
        raise RuntimeError(f"{directory} exists but is not a registered instance")
    if MySQL(CREDENTIALS).database_exists(database_of(name)):
        raise RuntimeError(
            f"database {database_of(name)} exists but is not a registered instance"
        )


def _registered(name: str) -> Instance:
    instance = Registry(home()).get(name)
    if instance is None:
        raise RuntimeError(f"there is no instance {name}; `just up {name}` creates it")
    return instance


def _built(name: str) -> Instance:
    instance = _registered(name)
    if not instance.is_provisioned():
        raise RuntimeError(
            f"{name} is not built; `just up {name}` or `just reset {name}`"
        )
    return instance


def _sayer(instance: Instance) -> wordpress.Say:
    def say(message: str) -> None:
        print(f"{instance.name}: {message}", flush=True)

    return say


def _plain_sayer(message: str) -> None:
    print(message, flush=True)


def _summary(instance: Instance) -> None:
    print(
        f"{instance.name}: {instance.url}/ "
        f"(wp-admin: {wordpress.ADMIN_USER}, password in {instance.admin_file})"
    )
    print(
        f"{instance.name}: mail in {instance.mail_dir}/, "
        f"blocked requests in {instance.isolation_log}"
    )
