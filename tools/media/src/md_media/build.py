"""The full build (spec §6.2): manifest + export join -> finals + sidecars.

`md-media build` reads the media manifest and the admin export, plans the
1340-source corpus into one build unit per unique sha256, encodes the units
in a process pool (worker = CPU count), checkpoints into _state.json after
every few completions, and assembles the sidecars from the checkpoint.
Any skip — a missing source, an undecodable file — is recorded with its
reason in the state and in the sidecars and fails the run.
"""

from __future__ import annotations

import hashlib
import shutil
import time
from collections.abc import Iterator
from concurrent.futures import ProcessPoolExecutor, as_completed
from pathlib import Path

from . import inventory, params, sidecar, state
from .inventory import BuildUnit
from .state import SourceRecord, State, TierRecord

_SAVE_EVERY = 25


def _process_unit(
    raw_dir: Path,
    out_root: Path,
    unit: BuildUnit,
) -> tuple[str, SourceRecord]:
    """Build one unit; runs in a worker process."""
    src = raw_dir / unit.read_name
    if unit.kind == "video":
        assert unit.video_name is not None
        data = src.read_bytes()
        dest = out_root / unit.out_dir / unit.video_name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(data)
        return unit.sha256, SourceRecord(
            width=None,
            height=None,
            sniffed="MP4",
            master_width=None,
            master_height=None,
            video_file=f"{unit.out_dir}/{unit.video_name}",
            video_bytes=len(data),
        )

    from . import images

    sniffed = images.sniff_format(str(src))
    img = images.load_image(str(src))
    role = params.ROLE_CUT_SHEET if unit.force_lossless else params.ROLE_UNKNOWN
    record = SourceRecord(
        width=img.width,
        height=img.height,
        sniffed=sniffed,
        master_width=unit.master_width,
        master_height=unit.master_height,
    )
    for tier in images.encode_tiers(img, role, sniffed):
        name = f"{unit.stem}--{tier.width}w.webp"
        rel = f"{unit.out_dir}/{name}"
        dest = out_root / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(tier.data)
        record.outputs[str(tier.width)] = TierRecord(
            file=rel,
            bytes=len(tier.data),
            sha256=hashlib.sha256(tier.data).hexdigest(),
            mtime=dest.stat().st_mtime,
            mode=tier.mode,
        )
    return unit.sha256, record


def _todo(plan: inventory.Plan, st: State, out_root: Path) -> list[BuildUnit]:
    todo: list[BuildUnit] = []
    for unit in plan.units:
        record = st.entries.get(unit.sha256)
        if record is None or not st.outputs_current(out_root, record):
            todo.append(unit)
    return todo


def _wipe(out_root: Path) -> None:
    """A fingerprint change starts from an empty tree (no mixed parameters)."""
    for child in (out_root / "products", out_root / "site"):
        if child.exists():
            shutil.rmtree(child)
    (out_root / "site-manifest.json").unlink(missing_ok=True)


def _summary(
    st: State,
    plan: inventory.Plan,
    built: int,
    skipped: int,
    elapsed: float,
) -> None:
    image_bytes = video_bytes = 0
    tiers = 0
    for record in st.entries.values():
        video_bytes += record.video_bytes or 0
        for tier in record.outputs.values():
            image_bytes += tier.bytes
            tiers += 1
    print(
        f"built {built} of {len(plan.units)} units "
        f"({skipped} skipped) in {elapsed:.0f}s: "
        f"{tiers} tiers, {image_bytes / 1e6:.1f} MB images, "
        f"{video_bytes / 1e6:.1f} MB videos"
    )


def run(
    raw_dir: Path,
    manifest_path: Path,
    products_path: Path,
    out_root: Path,
    workers: int,
) -> int:
    started = time.monotonic()
    manifest = inventory.load_manifest(manifest_path)
    by_master_src = {e.master_src: e for e in manifest if e.master_src}
    products = inventory.load_products(products_path, by_master_src)
    plan = inventory.plan(manifest, products, raw_dir)

    fingerprint = params.params_digest()
    st = state.load(out_root)
    if st is None or st.fingerprint != fingerprint:
        _wipe(out_root)
        st = State(fingerprint=fingerprint)
    st.skipped = list(plan.errors)

    todo = _todo(plan, st, out_root)
    print(f"{len(plan.units)} sources, {len(todo)} to build, {workers} workers")

    completed = 0
    with ProcessPoolExecutor(max_workers=workers) as pool:
        futures = {
            pool.submit(_process_unit, raw_dir, out_root, unit): unit for unit in todo
        }
        for future in as_completed(futures):
            unit = futures[future]
            try:
                sha, record = future.result()
                st.record(sha, record)
            except Exception as exc:  # noqa: BLE001 — a skip, not a crash
                st.skipped.append(
                    {
                        "local_name": unit.read_name,
                        "sha256": unit.sha256,
                        "refs": sorted(plan.groups[unit.sha256].refs()),
                        "reason": f"{type(exc).__name__}: {exc}",
                    }
                )
            completed += 1
            if completed % _SAVE_EVERY == 0:
                state.save(out_root, st)
                print(f"  {completed}/{len(todo)} done", flush=True)

    state.save(out_root, st)
    sidecar.write_sidecars(out_root, plan, st)
    failures = len(st.skipped) - len(plan.errors)
    _summary(
        st, plan, len(todo) - failures, len(st.skipped), time.monotonic() - started
    )
    return 1 if st.skipped else 0


def iter_media_files(out_root: Path) -> Iterator[Path]:
    """Every produced file under products/ and site/ (sidecars excluded)."""
    for tree in ("products", "site"):
        root = out_root / tree
        if root.exists():
            yield from (p for p in root.rglob("*") if p.is_file())
