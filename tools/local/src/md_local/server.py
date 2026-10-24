"""PHP's built-in web server for an instance."""

from __future__ import annotations

import os
import shutil
from pathlib import Path

from md_local.checkout import Checkout
from md_local.instance import HOST, Instance
from md_local.processes import Background

# Worker processes of PHP's built-in server (PHP_CLI_SERVER_WORKERS, PHP 7.4+,
# PHP manual "Built-in web server"). Six because Chromium opens up to six
# connections to one host (net/socket/client_socket_pool_manager.cc): a page's
# parallel requests are served side by side instead of queueing behind one
# process.
WORKERS = 6

# The production host does not exist yet, so there are no host limits to copy
# (the de-linker stack copied its host's). These are dev-working values, sized
# for WooCommerce admin pages under PHP's built-in server; the host supply
# ticket replaces them with the host's real limits and the direction of the
# copy flips (docs/specs/14-local-stack.md §2.3).
LIMITS = {
    "memory_limit": "512M",
    "post_max_size": "64M",
    "upload_max_filesize": "64M",
    "max_execution_time": "120",
    "max_input_time": "120",
    "max_input_vars": "3000",
}

# Seconds to wait for the port: PHP's server listened 0.12 s after the start on
# this Mac (measured in the de-linker stack, 2026-10-09); the margin covers a
# cold disk cache.
START_TIMEOUT = 10


def install(instance: Instance, checkout: Checkout) -> None:
    """Write the instance's php.ini and copy the router next to it.

    Copied, not linked: the server keeps working when the checkout that
    started it is removed.
    """
    instance.php_ini.write_text(php_ini(instance))
    shutil.copyfile(checkout.router, instance.router)


def php_ini(instance: Instance) -> str:
    lines = [
        "; Written by md-local; rewritten on every `up`.",
        "; Dev limits (no production host yet; see tools/local/src/md_local/server.py).",
        *(f"{key} = {value}" for key, value in LIMITS.items()),
        "; mail() never reaches this Mac's sendmail (Postfix would deliver it); wp_mail()",
        "; is captured by the isolation mu-plugin, a direct mail() call fails.",
        "sendmail_path = /usr/bin/false",
        "; Errors go to the instance's log, not into the page.",
        "display_errors = Off",
        "log_errors = On",
        f'error_log = "{instance.log_dir / "php-error.log"}"',
    ]
    return "\n".join(lines) + "\n"


def process(instance: Instance) -> Background:
    return Background(instance.run_dir / "server.pid", instance.log_dir / "server.log")


def start(instance: Instance, php: Path) -> None:
    """(Re)start the server, so it runs the files of the checkout that called `up`."""
    process(instance).restart(
        [
            str(php),
            "-c",
            str(instance.php_ini),
            "-S",
            f"{HOST}:{instance.port}",
            "-t",
            str(instance.docroot),
            str(instance.router),
        ],
        {**os.environ, "PHP_CLI_SERVER_WORKERS": str(WORKERS)},
        instance.port,
        START_TIMEOUT,
    )


def stop(instance: Instance) -> bool:
    return process(instance).stop()
