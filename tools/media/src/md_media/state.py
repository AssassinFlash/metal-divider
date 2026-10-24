"""Content-addressed checkpoint state (spec §6.3).

`_state.json` maps every source sha256 to its encoded outputs. A rerun skips
a source when the fingerprint matches, the source sha256 is unchanged, and
every output file still passes the cheap first check (size + mtime). Any
change — parameters, tool code, source bytes, a touched output — rebuilds
that source's every tier. A fingerprint change wipes the output trees first,
so old- and new-parameter products can never mix.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path

STATE_NAME = "_state.json"


@dataclass
class TierRecord:
    file: str  # relative to the output root
    bytes: int
    sha256: str
    mtime: float
    mode: str


@dataclass
class SourceRecord:
    width: int | None  # decoded dimensions; None for passthrough videos
    height: int | None
    sniffed: str
    master_width: int | None  # manifest-declared width, when present
    master_height: int | None
    outputs: dict[str, TierRecord] = field(default_factory=dict)  # tier width -> record
    video_file: str | None = None  # passthrough videos have no tiers
    video_bytes: int | None = None


@dataclass
class State:
    fingerprint: str
    entries: dict[str, SourceRecord] = field(default_factory=dict)
    skipped: list[dict[str, object]] = field(default_factory=list)

    def record(self, sha256: str, record: SourceRecord) -> None:
        self.entries[sha256] = record

    def outputs_current(self, out_root: Path, record: SourceRecord) -> bool:
        """The cheap first check: every output exists with size and mtime."""
        for tier in record.outputs.values():
            try:
                stat = (out_root / tier.file).stat()
            except OSError:
                return False
            if stat.st_size != tier.bytes or stat.st_mtime != tier.mtime:
                return False
        if record.video_file is not None:
            try:
                stat = (out_root / record.video_file).stat()
            except OSError:
                return False
            if record.video_bytes is not None and stat.st_size != record.video_bytes:
                return False
        return True


def state_path(out_root: Path) -> Path:
    return out_root / STATE_NAME


def load(out_root: Path) -> State | None:
    path = state_path(out_root)
    if not path.exists():
        return None
    raw = json.loads(path.read_text(encoding="utf-8"))
    entries: dict[str, SourceRecord] = {}
    for sha, eraw in raw.get("entries", {}).items():
        outputs = {
            w: TierRecord(
                file=o["file"],
                bytes=o["bytes"],
                sha256=o["sha256"],
                mtime=o["mtime"],
                mode=o["mode"],
            )
            for w, o in eraw.get("outputs", {}).items()
        }
        entries[sha] = SourceRecord(
            width=eraw["width"],
            height=eraw["height"],
            sniffed=eraw["sniffed"],
            master_width=eraw.get("master_width"),
            master_height=eraw.get("master_height"),
            outputs=outputs,
            video_file=eraw.get("video_file"),
            video_bytes=eraw.get("video_bytes"),
        )
    return State(
        fingerprint=raw["params_fingerprint"],
        entries=entries,
        skipped=raw.get("skipped", []),
    )


def save(out_root: Path, state: State) -> None:
    entries: dict[str, object] = {}
    for sha, rec in state.entries.items():
        body: dict[str, object] = {
            "width": rec.width,
            "height": rec.height,
            "sniffed": rec.sniffed,
            "master_width": rec.master_width,
            "master_height": rec.master_height,
            "outputs": {
                w: {
                    "file": t.file,
                    "bytes": t.bytes,
                    "sha256": t.sha256,
                    "mtime": t.mtime,
                    "mode": t.mode,
                }
                for w, t in rec.outputs.items()
            },
        }
        if rec.video_file is not None:
            body["video_file"] = rec.video_file
            body["video_bytes"] = rec.video_bytes
        entries[sha] = body
    payload = {
        "params_fingerprint": state.fingerprint,
        "entries": entries,
        "skipped": state.skipped,
    }
    blob = json.dumps(payload, indent=1)
    path = state_path(out_root)
    if path.exists() and path.read_text(encoding="utf-8") == blob:
        return
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(blob, encoding="utf-8")
    tmp.replace(path)
