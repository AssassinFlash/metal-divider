"""Where a deploy runs: the dev instance on this Mac, or one of the two sites.

The steps in `steps` and `media` never know which: they call `wp`, `shell`,
`copy_out`, `copy_in`, `http` and read `root`/`base_url`. Three targets exist
and nowhere else in the tool does a transport appear:

- `local_target(name)` — an instance of the local stack (`just up <name>`,
  tools/local), which is what the drill runs against and what the `dev`
  target names. WP-CLI runs on this Mac; "shell" is `bash -c` here; copying
  is rsync between two local directories.
- `remote_target(site)` — the production host and the review site, both
  add-on sites of the owner's existing Hostinger subscription (owner ruling
  2026-10-24: no new subscription; the revision recorded on ticket #35).
  One ssh alias serves both; the WordPress roots differ per site. WP-CLI is
  `/usr/local/bin/wp` on the host's PHP 8.3; "shell" is that ssh command;
  copying is rsync to `<host>:<wordpress root>`.

The alias itself is host-side setup (the cutover ticket wires it in
~/.ssh/config, modelled on the de-linker connection; credentials never enter
this repository — AGENTS.md §4). Until it exists every remote step fails at
the first ssh call with ssh's own "does not resolve" message.
"""

from __future__ import annotations

import base64
import json
import os
import shlex
import subprocess
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from http.client import HTTPMessage
from pathlib import Path, PurePosixPath
from typing import IO, Any, cast

# The ssh-config alias of the owner's Hostinger account (the subscription
# both sites hang on). Created by the host-side ticket; nothing here can or
# should carry a credential (AGENTS.md §4).
HOST = "hostinger-metal-divider"

# This project's own batch namespace on the host account, and the root the
# de-linker project owns and prunes. Both live under $HOME; see
# backup_root() for the measurement that forced the split.
NAMESPACE = "deploy-prev-metal-divider"
LEGACY_ROOT = "deploy-prev"

# The WordPress roots and addresses of the two remote targets. The path shape
# is the platform's add-on-site convention (~/domains/<site>/public_html,
# read on the same subscription 2026-10-24: de-linker.com and
# sunriver-inverter.com both live under ~/domains/); the directories come
# into existence with the host-side ticket's site creation.
SITES: dict[str, tuple[str, str]] = {
    "prod": (
        "/home/u388076051/domains/metal-divider.com/public_html",
        "https://metal-divider.com",
    ),
    "staging": (
        "/home/u388076051/domains/staging.metal-divider.com/public_html",
        "https://staging.metal-divider.com",
    ),
}

WP_CLI = "/usr/local/bin/wp"

# The paths under the WordPress root a deploy reads or writes. One list, so
# the targets cannot drift.
PLUGINS = "wp-content/plugins"
UPLOADS = "wp-content/uploads"
MU_PLUGINS = "wp-content/mu-plugins"
THEMES = "wp-content/themes"
HTACCESS = ".htaccess"

# The environment variables that carry the review site's basic-auth pair
# (hPanel's password-protected directories) to the verify step. Credentials
# live in the environment, never in the repository; without them verify asks
# anonymously and the 401s it sees are the finding.
STAGING_AUTH_ENV = "MD_DEPLOY_STAGING_AUTH"

# A payload this small goes in one rsync leg: the per-entry connections exist
# for the multi-GB transfers the host drops mid-flight (the blueprint
# measured 2.6 GB cut at ~8 minutes on the same platform), and paying one
# handshake per file made a 23 MB deployment take 22 minutes. Above the cap
# the entry walk stays.
SMALL_UPLOAD_BYTES = 64 * 1024 * 1024

# One batched media leg carries at most this many bytes: the host drops long
# transfers, and a dropped leg costs the batch it was carrying (a re-run
# skips what earlier legs already wrote).
UPLOAD_BATCH_BYTES = 96 * 1024 * 1024

# How many times one chunk of a remote transfer is retried before the step
# fails: the host drops long connections (same platform, blueprint-measured),
# and rsync resumes what a tar stream would restart.
TRANSFER_ATTEMPTS = 4


def _connection_dropped(detail: str) -> bool:
    """Whether stderr is the host closing the SSH, not a command that ran and failed.

    rsync reports the same drop as "unexpectedly closed" and often does not
    repeat ssh's own line. Matching only the ssh phrase would retry the
    listing and miss the transfer.
    """
    folded = detail.lower()
    return (
        "connection closed" in folded
        or "connection reset" in folded
        or "unexpectedly closed" in folded
    )


def _pause_after_drop(failures: int) -> None:
    """Wait before another try. `failures` is how many drops have already happened.

    1s, then 2s, then 4s. An immediate second handshake is what the host had
    just refused.
    """
    time.sleep(2 ** (failures - 1))


def _process_detail(completed: subprocess.CompletedProcess[str]) -> str:
    return (completed.stderr or completed.stdout or "").strip()


def _retry_after(detail: str, attempt: int, *, always: bool) -> bool:
    """Whether attempt `attempt` (0-based) should be followed by another try."""
    if attempt + 1 >= TRANSFER_ATTEMPTS:
        return False
    if _connection_dropped(detail):
        _pause_after_drop(attempt + 1)
        return True
    return always


class DeployError(RuntimeError):
    """A step could not run, or a check it makes failed."""


class _NoRedirects(urllib.request.HTTPRedirectHandler):
    """An opener part that refuses every redirect, so a 3xx stays a 3xx.

    urllib follows redirects by default, and a followed redirect answers with
    the 200 at the end of the chain — the status of the address under test is
    then invisible. Refusing hands the response to the default error handler,
    which raises `HTTPError` carrying the status the address itself answered.
    """

    def redirect_request(
        self,
        req: urllib.request.Request,
        fp: IO[bytes],
        code: int,
        msg: str,
        headers: HTTPMessage,
        newurl: str,
    ) -> urllib.request.Request | None:
        """Never build the follow-up request; see the class comment."""
        del req, fp, code, msg, headers, newurl
        return None


# One opener for every read: it holds no state, and building one is not free.
# `urllib.request.urlopen` is the redirect-following default and is not used.
OPENER = urllib.request.build_opener(_NoRedirects)


def _run(command: list[str], *, cwd: Path | None = None) -> str:
    result = subprocess.run(
        command, capture_output=True, text=True, check=False, cwd=cwd
    )
    if result.returncode != 0:
        detail = (result.stderr or result.stdout).strip()
        raise DeployError(
            f"{' '.join(shlex.quote(part) for part in command)}: {detail}"
        )
    return result.stdout


def remote_entries_command(host: str, source: str) -> list[str]:
    """The one ssh call that lists copy chunks for a tree that is not one leg.

    Uploads, and any other tree over the small-tree cap, still walk chunks:
    the host drops a single long transfer, so each chunk — one month under
    uploads, one depth-2 directory — gets its own connection. Four kinds are
    listed because two are not enough: a file that lives directly inside a
    first-level directory is covered by no directory chunk, and a first-level
    directory without subdirectories would vanish whole — the blueprint
    measured 195 files missing from a backup before this listing gained
    depth-2 files and depth-1 directories.
    """
    # `set -o pipefail` is bash, not the login shell's guarantee, so the script
    # travels as one `bash -c` word (ssh joins argv and the remote shell splits
    # again; an unquoted script would lose every argument after `set`). The
    # finds are `&&`-chained: a `{ find; find; } | sort` group's status is only
    # the last find, and without that chain pipefail still sees sort's 0 when
    # an earlier find died. A partial name list must not become a copy plan.
    script = (
        "set -o pipefail; "
        f"cd {shlex.quote(source)} && "
        "{ find . -maxdepth 1 -type f -printf 'F %p\\n' && "
        "find . -mindepth 2 -maxdepth 2 -type f -printf 'F %p\\n' && "
        "find . -mindepth 2 -maxdepth 2 -type d -printf 'D %p\\n' && "
        "find . -mindepth 1 -maxdepth 1 -type d -printf 'd %p\\n'; } | sort"
    )
    return ["ssh", "-o", "BatchMode=yes", host, f"bash -c {shlex.quote(script)}"]


def _tree_bytes(source: Path) -> int:
    """Every regular file's size under `source`, for the upload-leg choice."""
    return sum(item.stat().st_size for item in source.rglob("*") if item.is_file())


def local_entries(source: Path) -> list[Path]:
    """A tree's chunks: the four kinds `remote_entries_command` lists.

    Root files; files directly inside a first-level directory; depth-2
    directories; a first-level directory without subdirectories as a whole.
    Nothing may fall between two chunks — files beside a directory chunk are
    chunks of their own.
    """
    entries = [path for path in source.iterdir() if path.is_file()]
    for first in sorted(path for path in source.iterdir() if path.is_dir()):
        nested = sorted(path for path in first.iterdir() if path.is_dir())
        if nested:
            entries.extend(sorted(path for path in first.iterdir() if path.is_file()))
            entries.extend(nested)
        else:
            entries.append(first)
    return entries


@dataclass(frozen=True)
class Target:
    """One WordPress site a deploy can run against, and how to reach it."""

    label: str
    root: str
    base_url: str
    # `prefix` builds the WP-CLI command; a command is `<prefix> <args…>`.
    prefix: tuple[str, ...]
    # How a shell script reaches the machine the site runs on; the script is
    # passed whole, so it may use pipes and quoting.
    shell_prefix: tuple[str, ...] | None
    # Where `copy_in`/`copy_out` place the remote end: None for a local path.
    rsync_remote: str | None
    # The review site: the one target the staging-only artifacts ship to.
    staging: bool = False
    # "user:password" for the site's basic auth (hPanel directory protection),
    # read from the environment; None asks anonymously.
    http_auth: str | None = None

    def wp(self, *args: str, check: bool = True) -> str:
        """Run a WP-CLI command against the site and return its standard output.

        Remote arguments travel quoted: ssh joins its argv with spaces and the
        login shell splits again, so an argument with a space or a parenthesis
        — `wp eval '<php>'`, `wp db query 'DROP TABLE x'` — must be one word
        or the remote shell reads the parenthesis as syntax (blueprint-
        measured: the snippet export died on `get_results(`). A plain word
        quotes to itself, so every other call is unchanged. The local target
        keeps argv-list semantics — nothing there re-splits.
        """
        if self.shell_prefix is None:
            command = [*self.prefix, *args]
        else:
            command = [
                *self.prefix[:-1],
                " ".join([self.prefix[-1], *(shlex.quote(arg) for arg in args)]),
            ]
        if not check:
            result = subprocess.run(
                command, capture_output=True, text=True, check=False
            )
            return result.stdout.rstrip("\n")
        return _run(command).rstrip("\n")

    def wp_json(self, *args: str) -> Any:
        """A WP-CLI command that prints JSON."""
        return json.loads(self.wp(*args, "--format=json"))

    def wp_on_target(self) -> list[str]:
        """WP-CLI as command parts valid ON the machine the site runs on.

        The remote prefix is an ssh command line ("cd <root> && <wp>"); the
        snapshot's dump and restore scripts already run on that machine, so
        the cd becomes `--path` and no transport remains. Running the remote
        form there would ssh to an alias that machine does not have.
        """
        if self.shell_prefix is None:
            return list(self.prefix)
        return [WP_CLI, f"--path={self.root}"]

    def shell_command(self, script: str) -> list[str]:
        """The argv that runs a shell script on the machine the site runs on.

        ssh joins its arguments with spaces and the remote login shell splits
        them again: beside `bash -c` the script must travel as one quoted
        word, or the remote shell eats its first token. One implementation:
        `shell` and the snapshot's stream both call this.
        """
        if self.shell_prefix is None:
            return ["bash", "-c", script]
        return [*self.shell_prefix, shlex.quote(script)]

    def shell(self, script: str) -> str:
        """Run a shell script on the machine the site runs on."""
        return _run(self.shell_command(script))

    def rsync(
        self, source: str, destination: str, *, extra: list[str] | None = None
    ) -> str:
        """Copy `source` to `destination` with rsync, either end possibly a host:path."""
        command = ["rsync", "-az", "--delete", *(extra or [])]
        if source.startswith(f"{HOST}:") or destination.startswith(f"{HOST}:"):
            command += ["-e", "ssh -o BatchMode=yes"]
        command += [source, destination]
        return _run(command)

    def path_of(self, relative: str) -> str:
        """Where a WordPress-root-relative path is on the machine the site runs on.

        The guard is here and not in the remote branch alone: `Path(root) /
        "/etc/passwd"` is `/etc/passwd`, so a removal step handed an absolute
        path would delete outside the site on the local target too.
        """
        if relative.startswith("/") or ".." in relative.split("/"):
            raise DeployError(f"{relative!r} is not a path inside the WordPress root")
        return str(Path(self.root) / relative)

    def address(self, relative: str) -> str:
        """The same path as rsync needs it: `host:path` when the site is remote.

        This is not `path_of`. A shell script, an `-e` test and a `cd` run on
        the machine the site runs on and want the bare path; rsync on this
        Mac wants the host in front of it. Handing the `host:path` form to
        the shell is a directory named after a host that no machine has.
        """
        path = self.path_of(relative)
        if self.rsync_remote is None:
            return path
        host = self.rsync_remote.split(":", 1)[0]
        return f"{host}:{path}"

    def copy_out(
        self, relative: str, destination: Path, *, link_dest: Path | None = None
    ) -> None:
        """Copy a WordPress-root-relative directory off the target into `destination`.

        `link_dest` is the same directory in an earlier backup: rsync
        hard-links every file that has not changed instead of copying it
        again, so a full backup only transfers what changed since the last
        one.
        """
        destination.parent.mkdir(parents=True, exist_ok=True)
        if self.rsync_remote is None and not Path(self.path_of(relative)).is_dir():
            raise DeployError(f"{relative} is not a directory on {self.label}")
        if self.rsync_remote is not None:
            destination.mkdir(parents=True, exist_ok=True)
            base = self.path_of(relative).rstrip("/")
            # Uploads stay chunked even when the measured tree is small: that
            # walk exists because the host drops a multi-GB transfer, and a
            # wrong size must not collapse it into one leg. A small plugins
            # tree is the opposite problem — one directory per connection was
            # ~180 SSH handshakes, and the host closed them. Same cap as
            # copy_in's single leg.
            if (
                relative != UPLOADS
                and self._remote_tree_bytes(base) <= SMALL_UPLOAD_BYTES
            ):
                self._pull_tree(base, destination, link_dest=link_dest)
                return
            listed = self._list_remote(base, relative)
            chunks = _remote_chunks(listed.stdout)
            for path, is_dir in chunks:
                local = destination / path
                local.parent.mkdir(parents=True, exist_ok=True)
                earlier = None
                if link_dest is not None:
                    earlier = link_dest / path if is_dir else (link_dest / path).parent
                self._pull_entry(
                    f"{base}/{path}",
                    local if is_dir else local.parent,
                    is_dir=is_dir,
                    link_dest=earlier,
                )
            return
        # A trailing slash on the source copies the directory's contents, the
        # same thing rsync does with `<host>:<dir>`.
        self.rsync(
            f"{self.address(relative).rstrip('/')}/",
            f"{destination}/",
            extra=_link_dest(link_dest),
        )

    def _list_remote(
        self, source: str, relative: str
    ) -> subprocess.CompletedProcess[str]:
        """The chunk listing, retried only when the connection itself drops.

        A find that exits non-zero is a partial name list. Retrying it would
        not make the names appear, and pipefail already refused to hide it
        behind sort.
        """
        command = remote_entries_command(HOST, source)
        last = ""
        listed = subprocess.CompletedProcess(command, 1, stdout="", stderr="")
        for attempt in range(TRANSFER_ATTEMPTS):
            listed = subprocess.run(
                command, capture_output=True, text=True, check=False
            )
            if listed.returncode == 0:
                return listed
            detail = _process_detail(listed)
            last = detail[:200]
            if not _retry_after(detail, attempt, always=False):
                raise DeployError(f"listing {relative} on {self.label} failed: {last}")
        raise DeployError(f"listing {relative} on {self.label} failed: {last}")

    def _remote_tree_bytes(self, path: str) -> int:
        """Apparent size of a remote tree, for the single-leg choice.

        A dropped connection is retried here too: this read is the first SSH
        of a small-tree pull, and failing it once would skip the backoff the
        transfer itself gets. It is not `shell` — a retried shell would also
        replay writes.
        """
        command = self.shell_command(f"du -sb {shlex.quote(path)}")
        output = ""
        last = ""
        for attempt in range(TRANSFER_ATTEMPTS):
            completed = subprocess.run(
                command, capture_output=True, text=True, check=False
            )
            if completed.returncode == 0:
                output = completed.stdout
                break
            detail = _process_detail(completed)
            last = detail[:200]
            if not _retry_after(detail, attempt, always=False):
                quoted = " ".join(shlex.quote(part) for part in command)
                raise DeployError(f"{quoted}: {last}")
        else:
            quoted = " ".join(shlex.quote(part) for part in command)
            raise DeployError(f"{quoted}: {last}")
        try:
            return int(output.split()[0])
        except (ValueError, IndexError) as error:
            raise DeployError(
                f"could not read the size of {path}: {output.strip()[:200]}"
            ) from error

    def _pull_tree(
        self, source: str, destination: Path, *, link_dest: Path | None = None
    ) -> None:
        """A small remote tree in one rsync leg, retried like a chunk."""
        self._transfer(
            [f"{HOST}:{source}/", f"{destination}/"],
            f"reading {source} from {HOST}",
            link_dest=link_dest,
        )

    def _pull_entry(
        self,
        source: str,
        destination: Path,
        *,
        is_dir: bool,
        link_dest: Path | None = None,
    ) -> None:
        """One chunk of a remote tree, retried.

        The host drops a long transfer; rsync sees files one at a time, so a
        retry resumes the chunk instead of restarting it — which is what a
        tar stream cannot do.
        """
        source_arg = f"{HOST}:{source}/" if is_dir else f"{HOST}:{source}"
        self._transfer(
            [source_arg, f"{destination}/"],
            f"reading {source} from {HOST}",
            link_dest=link_dest,
        )

    def _push_entry(self, source: Path, destination: str) -> None:
        """One chunk of a local tree onto the target, retried like copy_out."""
        source_arg = f"{source}/" if source.is_dir() else str(source)
        self._transfer(
            [source_arg, f"{HOST}:{destination}"],
            f"writing {source} to {HOST}:{destination}",
        )

    def _transfer(
        self,
        arguments: list[str],
        what: str,
        *,
        link_dest: Path | None = None,
        stdin: str | None = None,
        delete: bool = True,
    ) -> None:
        """One retried rsync leg onto or off the host."""
        last = ""
        for attempt in range(TRANSFER_ATTEMPTS):
            completed = subprocess.run(
                [
                    "rsync",
                    "-az",
                    *(("--delete",) if delete else ()),
                    "-e",
                    "ssh -o BatchMode=yes",
                    *_link_dest(link_dest),
                    *arguments,
                ],
                capture_output=True,
                text=True,
                check=False,
                input=stdin,
            )
            if completed.returncode == 0:
                return
            detail = _process_detail(completed)
            last = detail[:200]
            # Other rsync failures still retry at once: that is the existing
            # deploy behaviour. A dropped connection waits, or the next
            # handshake lands in the same refusal.
            if not _retry_after(detail, attempt, always=True):
                break
        raise DeployError(f"{what} failed after {TRANSFER_ATTEMPTS} attempts: {last}")

    def copy_file_out(self, relative: str, destination: Path) -> None:
        """Copy one WordPress-root-relative file off the target to `destination`.

        One file is not the directory case: rsync's trailing slash means "this
        directory's contents", and on a file it is an error — which is how a
        single-file copy once arrived as an empty file instead of being
        taken. Same seam, same machine rules, the shape the caller names.
        """
        destination.parent.mkdir(parents=True, exist_ok=True)
        source = self.path_of(relative)
        if self.rsync_remote is None and not Path(source).is_file():
            raise DeployError(f"{relative} is not a file on {self.label}")
        self.rsync(self.address(relative), str(destination))

    def copy_file_in(self, source: Path, relative: str) -> None:
        """Copy one local file onto the target as the root-relative `relative`."""
        if not source.is_file():
            raise DeployError(f"{source} is not a file")
        self.rsync(str(source), self.address(relative))

    def copy_in(self, source: Path, relative: str) -> None:
        """Copy `source` onto the target as the WordPress-root-relative `relative`.

        The local branch replaces a symlink with a directory first: `just up`
        links this checkout's own plugins into the instance, rsync would
        follow one and write into the repository, and the production copy is
        a directory — which is also what a rollback restores.
        """
        if not source.is_dir():
            raise DeployError(f"{source} is not a directory")
        if self.rsync_remote is None:
            path = Path(self.path_of(relative))
            if path.is_symlink():
                path.unlink()
            path.mkdir(parents=True, exist_ok=True)
            self.rsync(f"{source}/", f"{self.address(relative)}/")
            return
        # One short connection per chunk, retried — the host drops long
        # transfers, and rsync resumes what a tar stream would restart. The
        # chunk list mirrors copy_out's, walked on this side because the
        # source is here. The base is the bare path: `_push_entry` adds the
        # host itself. The directory may not exist yet — a plugin's first
        # deploy — and rsync's receiver creates the final path component
        # only, so every chunk's destination directory is made first, in one
        # shell.
        base = self.path_of(relative).rstrip("/")
        if _tree_bytes(source) <= SMALL_UPLOAD_BYTES:
            # One leg for a small tree; `--delete` keeps the target a mirror
            # of `base` exactly as the batched walk does.
            self.shell(f"mkdir -p {shlex.quote(base)}")
            self._push_tree(source, base)
            return
        # A big tree goes over in batches: one rsync leg per batch of files
        # (`--files-from`), so a dropped connection costs one batch, not the
        # whole tree — and re-running a leg skips the files it already wrote.
        # Directories go first in one shell; the batch size bounds both the
        # leg duration (the host drops long transfers) and the connection
        # count (one per batch, never one per file).
        self.shell(f"mkdir -p {shlex.quote(base)}")
        entries = [e for e in local_entries(source) if e.is_file()]
        batch: list[Path] = []
        batch_bytes = 0
        names_buffer: list[str] = []

        def _flush() -> None:
            if not names_buffer:
                return
            self._transfer(
                [
                    "--files-from=-",
                    f"{source}/",
                    f"{HOST}:{base}/",
                ],
                f"writing {len(batch)} file(s) ({batch_bytes} bytes) to {HOST}:{base}",
                stdin="\n".join(names_buffer),
                delete=False,
            )

        for entry in entries:
            size = entry.stat().st_size
            if batch and batch_bytes + size > UPLOAD_BATCH_BYTES:
                _flush()
                batch, batch_bytes, names_buffer = [], 0, []
            batch.append(entry)
            batch_bytes += size
            names_buffer.append(str(entry.relative_to(source)))
        _flush()

    def _push_tree(self, source: Path, destination: str) -> None:
        """The whole small tree in one rsync leg, retried like `_push_entry`."""
        self._transfer(
            [f"{source}/", f"{HOST}:{destination}/"],
            f"writing {source} to {HOST}:{destination}",
        )

    def exists(self, relative: str) -> bool:
        """Whether a WordPress-root-relative path is on the target."""
        result = self.shell(
            f"if [ -e {shlex.quote(self.path_of(relative))} ]; then echo yes; else echo no; fi"
        )
        return result.strip() == "yes"

    def tree(self, relative: str) -> list[str]:
        """Every file under a WordPress-root-relative path, as `<digest>  <path>`.

        The deploy host (Linux) has sha256sum but not shasum; local dev
        targets (macOS) the reverse — the first available form wins.
        """
        tool = (
            "shasum -a 256"
            if "shasum" in self.shell("command -v shasum || true")
            else "sha256sum"
        )
        listing = self.shell(
            f"cd {shlex.quote(self.path_of(relative))} && find . -type f -print0 "
            f"| sort -z | xargs -0 {tool}"
        )
        return [line.strip() for line in listing.splitlines() if line.strip()]

    def fetch(
        self, path: str, *, cache_buster: str | None = None
    ) -> tuple[int, HTTPMessage, str]:
        """Fetch one path from the site: (status, headers, body).

        Every request carries a cache-buster when the caller asks for one:
        production serves through LiteSpeed's page cache, and a cached answer
        is not evidence about the code under test. Redirects are not followed
        (OPENER): a caller asking about a 301 is asking about the address,
        not about where it points.
        """
        url = self.base_url.rstrip("/") + "/" + path.lstrip("/")
        if cache_buster:
            separator = "&" if "?" in url else "?"
            url = f"{url}{separator}cbq={cache_buster}"
        headers = {"User-Agent": "md-deploy"}
        if self.http_auth:
            # Basic auth for the review site's hPanel directory protection;
            # the pair travels in the request, never in the repository.
            encoded = base64.b64encode(self.http_auth.encode()).decode()
            headers["Authorization"] = f"Basic {encoded}"
        request = urllib.request.Request(url, headers=headers)
        try:
            with OPENER.open(request, timeout=30) as response:
                return (
                    response.status,
                    cast("HTTPMessage", response.headers),
                    response.read().decode("utf-8", errors="replace"),
                )
        except urllib.error.HTTPError as error:
            # A 3xx lands here too, by design: `_NoRedirects` leaves it to
            # the default error handler, which raises with the status.
            return (
                error.code,
                cast("HTTPMessage", error.headers),
                error.read().decode("utf-8", errors="replace"),
            )
        except OSError as error:  # a refused connection, a DNS failure, a timeout
            raise DeployError(f"GET {url}: {error}") from error

    def http(self, path: str, *, cache_buster: str | None = None) -> tuple[int, str]:
        """Fetch one path from the site and return (status, body)."""
        status, _, body = self.fetch(path, cache_buster=cache_buster)
        return status, body


def _remote_chunks(listing: str) -> list[tuple[str, bool]]:
    """(path, is_directory) chunks of a remote tree, from the listing.

    A first-level directory with subdirectories is pulled by its
    subdirectories plus its own files; one without them travels whole,
    because nothing else would carry it.
    """
    files: list[str] = []
    directories: list[str] = []
    first_level: list[str] = []
    for line in listing.splitlines():
        kind, _, entry = line.strip().partition(" ")
        path = entry.strip().removeprefix("./")
        if not path or path in (".", ".."):
            continue
        if kind == "F":
            files.append(path)
        elif kind == "D":
            directories.append(path)
        elif kind == "d":
            first_level.append(path)
    with_children = {path.rpartition("/")[0] for path in directories}
    return (
        [(path, False) for path in files]
        + [(path, True) for path in directories]
        + [(path, True) for path in first_level if path not in with_children]
    )


def remote_target(site: str) -> Target:
    """One of the two remote sites, over the one ssh alias."""
    if site not in SITES:
        raise DeployError(f"--target wants staging or prod, got {site!r}")
    root, url = SITES[site]
    flags = ["ssh", "-o", "BatchMode=yes", HOST]
    # Only the review site is password-protected; the environment variable is
    # optional and anonymous is the honest default without it.
    auth = os.environ.get(STAGING_AUTH_ENV) if site == "staging" else None
    return Target(
        label=site,
        root=root,
        base_url=url,
        prefix=(*flags, f"cd {shlex.quote(root)} && {WP_CLI}"),
        shell_prefix=(*flags, "bash -c"),
        rsync_remote=f"{HOST}:{root}",
        staging=site == "staging",
        http_auth=auth,
    )


def local_target(name: str) -> Target:
    """One local instance of the stack, addressed the way `just up` builds it."""
    # Imported here: this module is the tool's only link to tools/local, and
    # the tests that build a Target by hand must not need an instance registry.
    from md_local.cli import require_php
    from md_local.instance import home
    from md_local.registry import Registry
    from md_local.wpcli import WpCli

    instance = Registry(home()).get(name)
    if instance is None:
        raise DeployError(f"no local instance named {name!r} (just up {name})")
    wp = WpCli(require_php(), instance)
    return Target(
        label=f"local:{name}",
        root=str(instance.docroot),
        base_url=instance.url,
        prefix=tuple(wp.command()),
        shell_prefix=None,
        rsync_remote=None,
    )


def named_target(name: str) -> Target:
    """The three configured targets: `dev` (the local instance), staging, prod.

    `local:<instance>` names any other local instance, which is how the drill
    gets its own.
    """
    if name.startswith("local:"):
        return local_target(name[len("local:") :])
    if name == "dev":
        return local_target("dev")
    if name in SITES:
        return remote_target(name)
    raise DeployError(
        f"--target wants dev, local:<instance>, staging or prod, got {name!r}"
    )


def backup_root(target: Target) -> str:
    """The host account's private per-site batch store for NEW batches.

    Two levels of separation, because two unrelated things share this account.

    The two sites of this project share one host account, so the store is
    split per site — the domain directory the WordPress root lives under — or
    a staging batch and a production batch would share one `latest` pointer.

    The account's `~/deploy-prev` is not this project's, though: the
    de-linker project's deploy tool uses it as a *flat* root and, after every
    successful archive, deletes every directory under it
    (`linker_cutover/atomic.py`, "this root belongs only to deployment
    batches"). This project's batches used to live at
    `~/deploy-prev/<site>/`, i.e. inside that prune, so a de-linker archive
    could remove this project's `latest` pointer and any pending batch with
    it. Measured on the shared account 2026-10-26: `~/deploy-prev/latest`
    carried a de-linker batch id while this project's own pointer sat one
    level down, and the pending batch `20261026T042025478901Z-1be1823`
    existed nowhere on disk afterwards. New batches therefore go to a
    namespace of this project's own, outside de-linker's root;
    `legacy_backup_root()` below still reads the old place.
    """
    if target.rsync_remote is None:
        return str(Path(target.root).parent / "deploy-prev")
    site = PurePosixPath(target.root).parent.name
    return target.shell(f'printf "%s/{NAMESPACE}/{site}" "$HOME"').strip()


def legacy_backup_root(target: Target) -> str:
    """Where this project's batches lived before the namespace: read-only now.

    Batches in here are still readable — a rollback or a resume of an
    already-archived recovery point must keep working — but nothing new is
    written here, because de-linker's archive prunes every directory under
    `~/deploy-prev` and would take our files with it. On a local target the
    two roots are the same place: the directory sits inside this project's own
    instance tree, where no other project's pruning reaches.
    """
    if target.rsync_remote is None:
        return str(Path(target.root).parent / "deploy-prev")
    site = PurePosixPath(target.root).parent.name
    return target.shell(f'printf "%s/{LEGACY_ROOT}/{site}" "$HOME"').strip()


def _link_dest(earlier: Path | None) -> list[str]:
    """rsync's `--link-dest` for an earlier copy of the same directory, when there is one.

    The path is absolute: rsync reads a relative `--link-dest` against the
    destination, not against where it was started.
    """
    if earlier is None or not earlier.is_dir():
        return []
    return [f"--link-dest={earlier.resolve()}"]
