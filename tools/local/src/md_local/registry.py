"""instances.toml: which instances exist on this Mac and the port each one owns.

The registry lives in the data root, not in the repository: every checkout
(one worktree per ticket) shares the same MySQL server and the same ports, so
only a registry per machine can keep two instances from taking the same port.
"""

from __future__ import annotations

import fcntl
import tomllib
from collections.abc import Iterable, Iterator
from contextlib import contextmanager
from datetime import UTC, datetime
from pathlib import Path

from md_local import processes
from md_local.instance import SHARED, Instance, check_name

# The first port tried. metal-divider starts at 9890, above the range the
# de-linker stack on the same Mac scans from 8888 upward (its registry.py
# FIRST_PORT = 8888), so the two stacks do not take each other's ports; the
# bind test below covers a listener that is up but registered nowhere
# (docs/specs/14-local-stack.md §1.2).
FIRST_PORT = 9890
# The last of the User Ports (RFC 6335 §6); above it are the dynamic ports the
# system hands out to outgoing connections.
LAST_PORT = 49151

HEADER = (
    "# Local WordPress instances of this Mac, written by `md-local up`.\n"
    "# One table per instance: the port it owns. Its database is md_<name>,\n"
    "# its files are in the directory <name>/ next to this file.\n"
)


class Registry:
    """The instances recorded in `<root>/instances.toml`."""

    def __init__(self, root: Path) -> None:
        self.root = root
        self.file = root / "instances.toml"

    def all(self) -> list[Instance]:
        return list(self._read().values())

    def get(self, name: str) -> Instance | None:
        return self._read().get(name)

    def register(self, name: str) -> Instance:
        """Return the instance called `name`, allocating its port if it is new."""
        check_name(name)
        with _machine_lock():
            instances = self._read()
            if name in instances:
                return instances[name]
            taken = _ports(instances.values())
            if self.root.resolve() != SHARED.resolve():
                # Another data root (a test run's) still shares this Mac's ports.
                taken |= _ports(Registry(SHARED)._read().values())
            instances[name] = Instance(name, _free_port(taken), self.root, "", _now())
            self._write(instances)
            return instances[name]

    def record(self, instance: Instance) -> None:
        """Write `instance` back (its checkout link); allocation stays as it is."""
        with _machine_lock():
            instances = self._read()
            known = instances.get(instance.name)
            if known is not None and known != instance:
                instances[instance.name] = instance
                self._write(instances)

    def deregister(self, name: str) -> None:
        """Remove `name` from the registry (its database and directory are gone)."""
        with _machine_lock():
            instances = self._read()
            if instances.pop(name, None) is not None:
                self._write(instances)

    def _read(self) -> dict[str, Instance]:
        if not self.file.exists():
            return {}
        with self.file.open("rb") as handle:
            data = tomllib.load(handle)
        instances = {}
        for name, entry in data.items():
            check_name(name)
            instances[name] = Instance(
                name,
                int(entry["port"]),
                self.root,
                str(entry.get("checkout", "")),
                str(entry.get("created", "")),
            )
        return instances

    def _write(self, instances: dict[str, Instance]) -> None:
        lines = [HEADER]
        for instance in sorted(instances.values(), key=lambda item: item.name):
            # check_name() admits only bare-key characters, so the name needs no quoting.
            lines.append(f"\n[{instance.name}]\n")
            lines.append(f"port = {instance.port}\n")
            if instance.checkout:
                lines.append(f'checkout = "{instance.checkout}"\n')
            if instance.created:
                lines.append(f'created = "{instance.created}"\n')
        self.root.mkdir(parents=True, exist_ok=True)
        temporary = self.file.with_suffix(".toml.tmp")
        temporary.write_text("".join(lines), encoding="utf-8")
        temporary.replace(self.file)


@contextmanager
def _machine_lock() -> Iterator[None]:
    """Serialise allocations on this Mac: two checkouts may run `just up` at once."""
    SHARED.mkdir(parents=True, exist_ok=True)
    with (SHARED / "instances.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(lock, fcntl.LOCK_UN)


def _ports(instances: Iterable[Instance]) -> set[int]:
    return {instance.port for instance in instances}


def _now() -> str:
    return datetime.now(UTC).isoformat(timespec="seconds")


def _free_port(taken: set[int]) -> int:
    """The first port from FIRST_PORT up that is neither taken nor in use."""
    for port in range(FIRST_PORT, LAST_PORT + 1):
        if port not in taken and processes.port_is_free(port):
            return port
    raise RuntimeError(f"no free port between {FIRST_PORT} and {LAST_PORT}")
