"""`media-check` — the §6.4 assertions plus the lossless proof.

1. coverage: every ref of all 1420 manifest entries hits a sidecar; every
   unique source has exactly one set of products; no duplicated content.
2. sha256: every tier file recomputes to its declared digest; every raw
   source still recomputes to its manifest digest; the state fingerprint
   matches the current parameters.
3. sizes: each tier decodes at its declared width, aspect height ±1 px,
   top tier <= 2048, no tier wider than its source.
4. format: every tier sniffs as WebP; every video sniffs as MP4.
5. the #6 gallery rules: seq continuous, featured = seq 1 exactly, media
   count = export count, alt verbatim, role in the closed vocabulary.
6. budget: the produced media total within the revised ceiling
   (params.TOTAL_BUDGET_BYTES — the evidence trail for the revision from
   the spec's 350 MB lives there).
7. summary table per role (always printed, for the acceptance record).
L. lossless tiers decode back pixel-identical to the re-resized source —
   the PNG per-tier lossless verification; cut sheets are lossless on every
   tier. Any FAIL is a non-zero exit.
"""

from __future__ import annotations

import hashlib
import io
import json
from concurrent.futures import ProcessPoolExecutor, as_completed
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from PIL import Image

from . import images, inventory, params, state
from .inventory import BuildUnit, Plan
from .state import SourceRecord, State

_MAX_EXAMPLES = 5


@dataclass
class Report:
    name: str
    problems: list[str]

    @property
    def ok(self) -> bool:
        return not self.problems


def _resolve(out_root: Path, file: str, product: str | None) -> Path:
    """A sidecar `file`: bare name = sibling of its manifest, else root path."""
    if "/" in file or product is None:
        return out_root / file
    return out_root / "products" / product / file


def _verify_source(
    raw_dir: Path,
    out_root: Path,
    unit: BuildUnit,
    record: SourceRecord,
) -> list[str]:
    """Assertions 2, 3, 4 and L for one source; runs in a worker process."""
    problems: list[str] = []
    src = raw_dir / unit.read_name
    data = src.read_bytes()
    if hashlib.sha256(data).hexdigest() != unit.sha256:
        problems.append(f"{unit.read_name}: raw source sha256 drifted")
        return problems
    if unit.kind == "video":
        assert record.video_file is not None
        blob = (out_root / record.video_file).read_bytes()
        if record.video_bytes is not None and len(blob) != record.video_bytes:
            problems.append(f"{unit.read_name}: video byte count changed")
        if blob[4:8] != b"ftyp":
            problems.append(f"{unit.read_name}: video does not sniff as MP4")
        return problems

    sniffed = images.sniff_format(str(src))
    if sniffed != record.sniffed:
        problems.append(f"{unit.read_name}: sniffed {sniffed} != {record.sniffed}")
    img = images.load_image(str(src))
    if record.width != img.width or record.height != img.height:
        problems.append(
            f"{unit.read_name}: decoded {img.width}x{img.height} != recorded "
            f"{record.width}x{record.height}"
        )
    widths = params.tier_widths(img.width)
    if {int(w) for w in record.outputs} != set(widths):
        problems.append(f"{unit.read_name}: tier set != plan for its width")
        return problems
    if max(widths) > params.MAX_WIDTH:
        problems.append(f"{unit.read_name}: top tier above the 2048 cap")
    for width in widths:
        tier = record.outputs[str(width)]
        problems.extend(
            _verify_tier(out_root, unit, img, width, tier.file, tier.sha256, tier.mode)
        )
    return problems


def _verify_tier(
    out_root: Path,
    unit: BuildUnit,
    source: Image.Image,
    width: int,
    file: str,
    declared_sha: str,
    mode: str,
) -> list[str]:
    problems: list[str] = []
    path = out_root / file
    blob = path.read_bytes()
    if hashlib.sha256(blob).hexdigest() != declared_sha:
        problems.append(f"{file}: sha256 mismatch")
    decoded = Image.open(io.BytesIO(blob))
    decoded.load()
    if decoded.format != params.FORMAT:
        problems.append(f"{file}: sniffs as {decoded.format}, not WebP")
    if decoded.width != width:
        problems.append(f"{file}: width {decoded.width} != {width}")
    expected_h = images.tier_height(source, width)
    if abs(decoded.height - expected_h) > 1:
        problems.append(f"{file}: height {decoded.height} != {expected_h}±1")
    if width > source.width:
        problems.append(f"{file}: tier wider than source (upscaling)")
    if mode == params.MODE_LOSSLESS:
        reference = images.resize_tier(source, width)
        if not images.pixels_equal(decoded, reference):
            problems.append(f"{file}: lossless tier is not pixel-identical")
    return problems


def _check_coverage(
    manifest: list[inventory.ManifestEntry],
    plan: Plan,
    product_sidecars: dict[str, dict[str, Any]],
    site_sidecar: dict[str, Any],
    st: State,
) -> Report:
    problems: list[str] = []
    for entry in manifest:
        for ref in entry.refs:
            if ref.startswith("product:"):
                handle = ref.split(":", 1)[1]
                media = product_sidecars.get(handle, {}).get("media", [])
                if not any(m["source"]["sha256"] == entry.sha256 for m in media):
                    problems.append(f"{ref}: {entry.local_name} not in sidecar")
            else:
                entries = site_sidecar.get("refs", {}).get(ref, [])
                if not any(e["source"]["sha256"] == entry.sha256 for e in entries):
                    problems.append(f"{ref}: {entry.local_name} not in sidecar")
    names = {e.local_name for e in manifest}
    for name in sorted(names):
        sha = next(e.sha256 for e in manifest if e.local_name == name)
        record = st.entries.get(sha)
        if record is None or (not record.outputs and record.video_file is None):
            problems.append(f"{name}: no products for its source")
    seen: dict[str, list[str]] = {}
    for record in st.entries.values():
        for tier in record.outputs.values():
            seen.setdefault(tier.sha256, []).append(tier.file)
    for sha, files in sorted(seen.items()):
        if len(files) > 1:
            problems.append(f"duplicate content {sha[:12]}: {files}")
    return Report("coverage (1420 refs, one set per source, no duplicates)", problems)


def _check_products(
    plan: Plan,
    product_sidecars: dict[str, dict[str, Any]],
) -> Report:
    problems: list[str] = []
    for handle, nodes in sorted(plan.products.items()):
        sc = product_sidecars.get(handle)
        if sc is None:
            problems.append(f"{handle}: no manifest.json")
            continue
        media = sc.get("media", [])
        if len(media) != len(nodes):
            problems.append(
                f"{handle}: {len(media)} media != {len(nodes)} export nodes"
            )
        seqs = [m.get("seq") for m in media]
        if seqs != list(range(1, len(media) + 1)):
            problems.append(f"{handle}: seq not continuous from 1: {seqs}")
        featured = [m for m in media if m.get("featured")]
        if len(featured) != 1 or featured and featured[0]["seq"] != 1:
            problems.append(f"{handle}: featured is not exactly seq 1")
        if len(media) != len(nodes):
            continue  # a skip: already failed above; zip would misalign
        for m, node in zip(media, nodes, strict=True):
            label = f"{handle} seq {m.get('seq')}"
            if m.get("alt") != node.alt:
                problems.append(f"{label}: alt not verbatim")
            if m.get("role") not in (*params.ROLE_VOCAB, params.ROLE_UNKNOWN):
                problems.append(f"{label}: role outside the vocabulary")
            if m.get("role") != node.role:
                problems.append(f"{label}: role != export parse")
            if node.entry is not None:
                if m.get("source", {}).get("sha256") != node.entry.sha256:
                    problems.append(f"{label}: source sha256 != join")
                if m.get("source", {}).get("master_src") != node.url:
                    problems.append(f"{label}: master_src != export URL")
            tiers = m.get("tiers", [])
            if not tiers:
                problems.append(f"{label}: no tiers")
            for t in tiers:
                if t.get("mode") not in (params.MODE_LOSSLESS, params.MODE_Q85):
                    problems.append(f"{label}: tier mode invalid")
            if m.get("role") == params.ROLE_CUT_SHEET:
                if m.get("mode") != params.MODE_LOSSLESS or any(
                    t.get("mode") != params.MODE_LOSSLESS for t in tiers
                ):
                    problems.append(f"{label}: cut sheet not lossless on every tier")
    return Report(
        "gallery rules (seq, featured, alt, role; cut sheet lossless)", problems
    )


def _check_state(
    st: State, product_sidecars: dict[str, dict[str, Any]], site_sidecar: dict[str, Any]
) -> Report:
    problems: list[str] = []
    if st.fingerprint != params.params_digest():
        problems.append("state fingerprint does not match the current parameters")
    for handle, sc in sorted(product_sidecars.items()):
        if sc.get("skipped"):
            problems.append(f"products/{handle}: skipped entries {sc['skipped']}")
    if site_sidecar.get("skipped"):
        problems.append(f"site-manifest: skipped entries {site_sidecar['skipped']}")
    if st.skipped:
        problems.append(f"state: skipped entries {st.skipped[:_MAX_EXAMPLES]}")
    return Report("state (fingerprint, zero skips)", problems)


def _summary_table(
    plan: Plan,
    st: State,
    product_sidecars: dict[str, dict[str, Any]],
) -> str:
    """Per-role slot view over the galleries; physical totals from the state.

    A slot's tiers may reference a shared source's files (byte-identical
    twins), so the role rows count bytes per gallery slot while the TOTAL
    rows count each produced file exactly once.
    """
    master_bytes = {sha: group.entries[0].bytes for sha, group in plan.groups.items()}
    roles: dict[str, dict[str, int]] = {}
    for handle in plan.products:
        for m in product_sidecars.get(handle, {}).get("media", []):
            role = str(m.get("role", params.ROLE_UNKNOWN))
            bucket = roles.setdefault(
                role, {"media": 0, "tiers": 0, "final": 0, "master": 0}
            )
            bucket["media"] += 1
            bucket["tiers"] += len(m.get("tiers", []))
            bucket["final"] += sum(t.get("bytes", 0) for t in m.get("tiers", []))
            sha = m.get("source", {}).get("sha256")
            if isinstance(sha, str):
                bucket["master"] += master_bytes.get(sha, 0)

    product_shas = {u.sha256 for u in plan.units if u.out_dir.startswith("products/")}
    product_final = site_tiers = site_final = video_bytes = 0
    for sha, record in st.entries.items():
        video_bytes += record.video_bytes or 0
        tier_bytes = sum(t.bytes for t in record.outputs.values())
        if sha in product_shas:
            product_final += tier_bytes
        else:
            site_final += tier_bytes
        site_tiers += len(record.outputs)
    product_master = sum(master_bytes[sha] for sha in product_shas)

    lines = ["role | media | tiers | final bytes | master bytes | ratio"]
    for role in sorted(roles):
        b = roles[role]
        ratio = f"{b['master'] / b['final']:.2f}x" if b["final"] else "-"
        lines.append(
            f"{role} | {b['media']} | {b['tiers']} | {b['final']:,} | "
            f"{b['master']:,} | {ratio}"
        )
    lines.append(
        f"TOTAL product images | {len(plan.products)} galleries | "
        f"- | {product_final:,} | {product_master:,} | "
        f"{product_master / product_final:.2f}x"
    )
    lines.append(
        f"site-only images (all refs) | - | {site_tiers} | {site_final:,} | - | -"
    )
    lines.append(
        f"videos passthrough | - | - | {video_bytes:,} | {video_bytes:,} | 1.00x"
    )
    lines.append(
        f"GRAND TOTAL | - | - | {product_final + site_final + video_bytes:,} | - | -"
    )
    return "\n".join(lines)


def run(
    raw_dir: Path,
    manifest_path: Path,
    products_path: Path,
    out_root: Path,
    workers: int,
) -> int:
    manifest = inventory.load_manifest(manifest_path)
    by_master_src = {e.master_src: e for e in manifest if e.master_src}
    products = inventory.load_products(products_path, by_master_src)
    plan = inventory.plan(manifest, products, raw_dir)
    st = state.load(out_root)
    if st is None:
        print("FAIL: no _state.json — run `just media-build` first")
        return 1

    product_sidecars: dict[str, dict[str, Any]] = {}
    for handle in plan.products:
        path = out_root / "products" / handle / "manifest.json"
        if path.exists():
            product_sidecars[handle] = json.loads(path.read_text(encoding="utf-8"))
    site_path = out_root / "site-manifest.json"
    site_sidecar: dict[str, Any] = (
        json.loads(site_path.read_text(encoding="utf-8")) if site_path.exists() else {}
    )

    reports: list[Report] = []

    # Assertions 2, 3, 4, L — one worker task per source.
    problems: list[str] = []
    with ProcessPoolExecutor(max_workers=workers) as pool:
        futures = {
            pool.submit(
                _verify_source, raw_dir, out_root, unit, st.entries[unit.sha256]
            ): unit
            for unit in plan.units
            if unit.sha256 in st.entries
        }
        for future in as_completed(futures):
            unit = futures[future]
            try:
                problems.extend(future.result())
            except Exception as exc:  # noqa: BLE001 — reported as a FAIL
                problems.append(
                    f"{unit.read_name}: verification error {type(exc).__name__}: {exc}"
                )
    missing = [u.sha256 for u in plan.units if u.sha256 not in st.entries]
    for sha in missing:
        problems.append(f"{sha[:12]}: no state record")
    reports.append(
        Report("per-source (sha256, sizes, format, lossless pixels)", problems)
    )

    reports.append(_check_coverage(manifest, plan, product_sidecars, site_sidecar, st))
    reports.append(_check_products(plan, product_sidecars))
    reports.append(_check_state(st, product_sidecars, site_sidecar))

    media_bytes = 0
    for record in st.entries.values():
        media_bytes += record.video_bytes or 0
        media_bytes += sum(t.bytes for t in record.outputs.values())
    over = media_bytes > params.TOTAL_BUDGET_BYTES
    reports.append(
        Report(
            f"budget ({media_bytes / 1e6:.1f} MB <= "
            f"{params.TOTAL_BUDGET_BYTES / 1e6:.0f} MB)",
            [f"total {media_bytes:,} bytes over budget"] if over else [],
        )
    )

    failed = False
    for report in reports:
        status = "PASS" if report.ok else "FAIL"
        if not report.ok:
            failed = True
        print(f"{status}  {report.name}")
        for problem in report.problems[:_MAX_EXAMPLES]:
            print(f"      {problem}")
        if len(report.problems) > _MAX_EXAMPLES:
            print(f"      … and {len(report.problems) - _MAX_EXAMPLES} more")
    print()
    print(_summary_table(plan, st, product_sidecars))
    return 1 if failed else 0
