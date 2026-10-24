"""The md-media command line: build | check | one."""

from __future__ import annotations

import argparse
import os
from pathlib import Path

from . import build, check, one, params


def _add_corpus_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--raw", type=Path, default=Path(params.DEFAULT_RAW))
    parser.add_argument("--manifest", type=Path, default=Path(params.DEFAULT_MANIFEST))
    parser.add_argument("--products", type=Path, default=Path(params.DEFAULT_PRODUCTS))
    parser.add_argument("--out", type=Path, default=Path(params.DEFAULT_OUT))
    parser.add_argument(
        "--workers",
        type=int,
        default=max(1, (os.cpu_count() or 2) - 1),
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="md-media")
    sub = parser.add_subparsers(dest="command", required=True)

    build_parser = sub.add_parser(
        "build", help="full build: variants, sidecars, checkpoint state"
    )
    _add_corpus_args(build_parser)

    check_parser = sub.add_parser(
        "check", help="verify the final set (§6.4 assertions)"
    )
    _add_corpus_args(check_parser)

    one_parser = sub.add_parser(
        "one", help="single-file final for an owner upload (no tier ladder)"
    )
    one_parser.add_argument("file", type=Path)

    args = parser.parse_args(argv)
    if args.command == "build":
        return build.run(args.raw, args.manifest, args.products, args.out, args.workers)
    if args.command == "check":
        return check.run(args.raw, args.manifest, args.products, args.out, args.workers)
    return one.run(args.file)


if __name__ == "__main__":
    raise SystemExit(main())
