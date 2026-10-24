"""This Mac's Redis: what an instance may have left in it.

Phase 1 runs no object cache (owner ruling 2026-10-24: the shared host has no
Redis, so the site ships without one; AGENTS.md §2), so no instance code writes
Redis keys. The cleanup stays because the storage rules make it part of
recycling an instance (docs/agents/local-storage.md): IF a key with this
instance's prefix exists — an experiment, a later phase — it goes, and only it.

`FLUSHDB` / `FLUSHALL` are forbidden on this shared server
(docs/agents/local-storage.md); every deletion is by this instance's own keys.
"""

from __future__ import annotations

import shutil
import subprocess

from md_local.instance import HOST, Instance

# Redis's port: `port 6379` in redis.conf, the file Homebrew's redis service
# starts with (/opt/homebrew/etc/redis.conf, which binds the loopback only).
REDIS_PORT = 6379


def prefix(instance: Instance) -> str:
    """Every key of the instance starts with this, e.g. "md_dev:"."""
    return f"{instance.database}:"


def forget(instance: Instance) -> str:
    """Delete the instance's keys, and only those; report what it did.

    Returns what happened, for `up`/`reset` to say: "no keys", "deleted <n>
    keys", or why Redis could not be asked (nothing writes it in phase 1, so
    that is a note, not a failure).
    """
    cli = shutil.which("redis-cli")
    if cli is None:
        return "redis-cli is not installed; phase 1 writes no Redis keys"
    probe = subprocess.run(
        [cli, "-h", HOST, "-p", str(REDIS_PORT), "PING"],
        capture_output=True,
        text=True,
        check=False,
    )
    if probe.returncode != 0:
        return "Redis is not running; phase 1 writes no Redis keys"
    keys = _redis_cli(cli, "--scan", "--pattern", f"{prefix(instance)}*").splitlines()
    if not keys:
        return "no keys"
    # UNLINK, not DEL: it releases the memory outside the request path.
    _redis_cli(cli, "UNLINK", *keys)
    return f"deleted {len(keys)} keys"


def _redis_cli(cli: str, *args: str) -> str:
    result = subprocess.run(
        [cli, "-h", HOST, "-p", str(REDIS_PORT), *args],
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"redis-cli {args[0]}: {(result.stderr or result.stdout).strip()}"
        )
    return result.stdout
