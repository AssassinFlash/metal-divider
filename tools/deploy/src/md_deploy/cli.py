"""`md-deploy <step>`: the deployment pipeline of docs/specs/20-staging-deploy.md.

    md-deploy deploy           snapshot → build → upload → atomic switch → present
    md-deploy rollback-deploy  restore the previous deployed files by rename
    md-deploy resume-deploy    verify/archive a pending batch after an interruption
    md-deploy snapshot         a database snapshot to this Mac (deploy takes one itself)
    md-deploy snapshots        list the snapshots on this Mac
    md-deploy restore-snapshot put a database snapshot back
    md-deploy backup           the complete backup of the site (weekly, before data writes)
    md-deploy media            push the finalized uploads in chunks (the media plane)
    md-deploy verify           the read-only regression subset
    md-deploy maintenance      the maintenance window on|off
    md-deploy contract         read the target's versions back, against the local pins
    md-deploy windpress-check  the checks after a WindPress compile (mtime, anchors)
    md-deploy chat-seed        pull the sanitized chat seed from the golden host
    md-deploy chat-push        deploy the chat plane (golden relay + repo code + seed)
    md-deploy chat-verify      the read-only chat probes
    md-deploy drill            rehearse switch → rollback → switch on a local instance

`--target` names one of the three configured targets: `dev` (the local
instance), `local:<instance>` (any other), `staging`, `prod`. Nothing writes
unless `--write` is given; `drill` writes by design and says so.
"""

from __future__ import annotations

import argparse
import hashlib
import sys
from datetime import UTC, datetime
from pathlib import Path

from md_deploy import backup as backup_module
from md_deploy import chat as chat_module
from md_deploy import drill as drill_module
from md_deploy import media as media_module
from md_deploy import plan, steps
from md_deploy import snapshot as snapshot_module
from md_deploy.target import DeployError, Target, named_target

# The data root on this Mac (full backups, snapshots, workspace); one
# definition in backup.py.
DATA_HOME = backup_module.DATA_HOME


def repository_root() -> Path:
    """The repository this tool was run from: `<repo>/tools/deploy/src/md_deploy/cli.py`."""
    return Path(__file__).resolve().parents[4]


def default_uploads(target: Target) -> Path:
    """The local dev instance's uploads directory, the media push's default source."""
    return Path(target.root) / "wp-content" / "uploads"


def _common() -> argparse.ArgumentParser:
    """The options every step takes, so `just deploy verify --target …` works.

    They live on a shared parent parser rather than on the top-level one: the
    recipes pass the step name first (`just deploy verify --target dev`), and
    argparse would refuse a top-level option that comes after it.
    """
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument(
        "--target",
        default="dev",
        help="dev (the local instance), local:<instance>, staging or prod",
    )
    parser.add_argument(
        "--write",
        action="store_true",
        help="perform the changes; without it each step reports what it would do",
    )
    return parser


def _parser() -> argparse.ArgumentParser:
    common = _common()
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("deploy", parents=[common], help="the file plane + the DB plane")
    sub.add_parser(
        "rollback-deploy", parents=[common], help="restore the previous files"
    )
    sub.add_parser(
        "resume-deploy", parents=[common], help="verify/archive a pending batch"
    )
    sub.add_parser("snapshot", parents=[common], help="database snapshot to this Mac")
    sub.add_parser("snapshots", parents=[common], help="list the snapshots on this Mac")
    restoring = sub.add_parser(
        "restore-snapshot", parents=[common], help="put a database snapshot back"
    )
    restoring.add_argument("name", help="db-YYYYMMDDTHHMMSSZ.sql.gz (see `snapshots`)")
    sub.add_parser("backup", parents=[common], help="the complete backup")
    pushing = sub.add_parser(
        "media", parents=[common], help="push the finalized uploads in chunks"
    )
    pushing.add_argument(
        "--source",
        type=Path,
        help="the finalized uploads directory (default: the dev instance's)",
    )
    sub.add_parser("verify", parents=[common], help="the read-only regression subset")
    sub.add_parser("contract", parents=[common], help="the version contract readback")
    checking = sub.add_parser(
        "windpress-check", parents=[common], help="the checks after a WindPress compile"
    )
    checking.add_argument(
        "--before",
        type=int,
        default=0,
        help="the cache mtime from before the compile (from the deploy's log)",
    )
    installing = sub.add_parser(
        "windpress-install",
        parents=[common],
        help="install a compiled WindPress cache, behind a coverage gate",
    )
    installing.add_argument(
        "--css",
        type=Path,
        required=True,
        help="the compiled stylesheet to install (e.g. the dev instance's cache)",
    )
    installing.add_argument(
        "--base",
        default="",
        help="the URL whose pages the gate reads (default: the target's own)",
    )
    installing.add_argument(
        "--pages",
        type=Path,
        default=None,
        help="a file with one page path per line (default: the sitemap's pages)",
    )
    window = sub.add_parser(
        "maintenance", parents=[common], help="the maintenance window"
    )
    window.add_argument("state", choices=("on", "off"))
    rehearsal = sub.add_parser(
        "drill", parents=[common], help="switch → rollback → switch on a local instance"
    )
    rehearsal.add_argument(
        "--instance", default="dev", help="the local instance to rehearse on"
    )
    sub.add_parser(
        "chat-seed", parents=[common], help="pull the sanitized chat seed from staging"
    )
    sub.add_parser(
        "chat-push", parents=[common], help="deploy the chat plane to the target"
    )
    sub.add_parser("chat-verify", parents=[common], help="the read-only chat probes")
    return parser


def main(argv: list[str] | None = None) -> int:
    # A window runs these steps with the output going to a file, where Python
    # buffers by default: nothing would appear until the step ended, and a
    # step that hangs would look exactly like a step that is working. Progress
    # is the point of a deploy script, so every line goes out as it happens.
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(line_buffering=True)
    parser = _parser()
    args = parser.parse_args(argv)
    repository = repository_root()

    try:
        if args.command == "drill":
            # The drill addresses its own instance; resolving the default
            # target here would demand a `dev` instance the drill never uses.
            return _drill(args, repository)
        target = named_target(args.target)
        if args.command == "deploy":
            if not args.write:
                for item in steps.deployment_artifacts(repository, target):
                    print(f"deploy: would build and stage {item.relative}, then rename")
                for name in steps.absent_artifacts(repository):
                    print(f"deploy: not yet in the tree (skipped): {name}")
                print(
                    "deploy: would snapshot the database, switch in one shell, then verify"
                )
                return 0
            deployed = steps.deploy(
                target,
                repository=repository,
                staging=_workspace(repository) / "staging",
                on_switch=lambda: print("deploy: switch starting", flush=True),
                snapshot_home=DATA_HOME,
            )
            _print(deployed)
            assert deployed.publication is not None
            checked = steps.finish_deploy(
                target,
                deployed.publication,
                cache_buster=_cache_buster(),
                phase_notes=deployed.notes,
            )
            _print(checked)
            return 1 if checked.notes else 0
        if args.command == "rollback-deploy":
            if not args.write:
                print(
                    "rollback-deploy: would restore the previous deployed files "
                    "by rename (no database import)"
                )
                return 0
            restored = steps.rollback_deploy(target, repository=repository)
            _print(restored)
            return 1 if restored.notes else 0
        if args.command == "resume-deploy":
            if not args.write:
                print(
                    "resume-deploy: would verify/archive the pending deployment manifest"
                )
                return 0
            resumed = steps.resume_deploy(
                target, repository=repository, cache_buster=_cache_buster()
            )
            _print(resumed)
            return 1 if resumed.notes else 0
        if args.command == "snapshot":
            taken = snapshot_module.take(target, DATA_HOME)
            print(
                f"snapshot: {taken.path} ({taken.size} bytes, sha256 {taken.sha256[:16]}…)"
            )
            return 0
        if args.command == "snapshots":
            for name in snapshot_module.available(DATA_HOME):
                print(name)
            return 0
        if args.command == "restore-snapshot":
            path = snapshot_module.existing(DATA_HOME, args.name)
            if not args.write:
                print(
                    f"restore-snapshot: would keep the current database as a snapshot, "
                    f"then replace it with {path}"
                )
                return 0
            for line in snapshot_module.restore(target, DATA_HOME, args.name):
                print(f"restore-snapshot: {line}")
            return 0
        if args.command == "backup":
            if not args.write:
                print(
                    f"backup: would take the complete backup of {target.label} "
                    f"under {backup_module.backups_home(DATA_HOME)}"
                )
                return 0
            complete = backup_module.create(target, DATA_HOME)
            print(
                f"backup: {complete.directory} ({complete.files} files, "
                f"dump sha256 {complete.dump_sha256[:16]}…)"
            )
            return 0
        if args.command == "media":
            source = args.source or default_uploads(target)
            if not args.write:
                pieces, ignored = media_module.chunks(source)
                print(f"media: would push {len(pieces)} chunk(s) from {source}")
                if ignored:
                    print(f"media: ignoring {', '.join(ignored)} (not this pipeline's)")
                print(
                    "media: a fresh complete backup is required first (the gate runs on --write)"
                )
                return 0
            actions = media_module.push(target, source)
            for action in actions:
                print(f"media: {action}")
            return 0
        if args.command == "verify":
            result = steps.verify(target, cache_buster=_cache_buster())
            _print(result)
            # A note is a check that failed: the window must not read a
            # 200-only page list as a pass when an entry point is wrong.
            return 1 if result.notes else 0
        if args.command == "contract":
            result = steps.contract(target)
            _print(result)
            return 1 if result.notes else 0
        if args.command == "windpress-check":
            result = steps.windpress_check(target, before=args.before or None)
            _print(result)
            return 1 if result.notes else 0
        if args.command == "windpress-install":
            from md_deploy import windpress_install

            return windpress_install.install_cli(target, args, write=args.write)
        if args.command == "maintenance":
            if not args.write:
                print(f"maintenance: would turn the maintenance file {args.state}")
                return 0
            _print(steps.maintenance(target, on=args.state == "on"))
            return 0
        if args.command == "chat-seed":
            # The export only makes sense from the golden host: a different
            # --target would be accepted and silently ignored by chat_seed.
            if args.target != chat_module.GOLDEN_TARGET:
                print(
                    f"chat-seed: the seed exports from the golden host only "
                    f"({chat_module.GOLDEN_TARGET}), not {args.target}",
                    file=sys.stderr,
                )
                return 2
            if not args.write:
                for step in chat_module.chat_seed_plan(DATA_HOME):
                    print(f"chat-seed: would {step}")
                return 0
            seeded = chat_module.chat_seed(repository=repository, home=DATA_HOME)
            _print(seeded)
            return 1 if seeded.notes else 0
        if args.command == "chat-push":
            if not args.write:
                for step in chat_module.chat_push_plan():
                    print(f"chat-push: would {step}")
                print(f"chat-push: the target is {target.label}")
                return 0
            pushed = chat_module.chat_push(
                target, repository=repository, home=DATA_HOME
            )
            _print(pushed)
            return 1 if pushed.notes else 0
        if args.command == "chat-verify":
            result = chat_module.chat_verify(target, cache_buster=_cache_buster())
            _print(result)
            return 1 if result.notes else 0
        if args.command == "drill":
            return _drill(args, repository)
    except DeployError as error:
        print(f"deploy: {error}", file=sys.stderr)
        return 1
    parser.error(f"unknown command {args.command}")
    return 2


def _drill(args: argparse.Namespace, repository: Path) -> int:
    """The rehearsal: switch, roll back, switch again (spec 20 §3.3 step 0).

    The record lands under the data root, outside the repository: it is
    evidence about this Mac's run, and the workspace directory is recycled
    with the rest of the tool's own files.
    """
    rehearsal = drill_module.Drill(
        target=named_target(f"local:{args.instance}"),
        repository=repository,
        home=DATA_HOME,
        staging=_workspace(repository) / "staging",
    )
    record = rehearsal.run()
    record["plan"] = {
        "planned_plugins": list(plan.PLANNED_PLUGINS),
        "staging_mu": plan.STAGING_MU,
    }
    destination = drill_module.write(
        record, _workspace(repository) / _today() / "drill.json"
    )
    print(f"drill: record written to {destination}")
    return 0


def _workspace(repository: Path) -> Path:
    """Where a step's intermediate files go: outside the repository, beside the backups.

    Scoped per checkout. The staging tree is a build cache keyed by a
    fingerprint of the source, and two checkouts share neither the source nor
    the fingerprint — so a shared workspace lets one checkout's staging
    deploy overwrite the tree the other is about to publish. Measured
    2026-10-29: a prod deploy from this checkout reported all eleven
    artifacts `deployed … by rename` with a 0.023 s switch and answered every
    verify probe 200, while serving the other checkout's bytes throughout.
    The stamp it wrote was the other checkout's fingerprint, exactly; its own
    differed. The directory name is a digest of the checkout's absolute path,
    so two clones never write to the same tree and neither can publish the
    other's build.
    """
    return DATA_HOME / "deploy-workspace" / _checkout_id(repository)


def _checkout_id(repository: Path) -> str:
    """A short stable name for one checkout: the digest of its absolute path."""
    resolved = str(Path(repository).resolve())
    return hashlib.sha256(resolved.encode("utf-8")).hexdigest()[:12]


def _cache_buster() -> str:
    return datetime.now(UTC).strftime("%Y%m%d%H%M%S")


def _print(result: steps.Result) -> None:
    print(result.describe())


def _today() -> str:
    return datetime.now(UTC).strftime("%Y-%m-%d")


if __name__ == "__main__":
    raise SystemExit(main())
