"""Sidecar assembly (spec §5): the migrator's only media input.

Two sidecar shapes, both `md-media/sidecar@1`:

- products/{handle}/manifest.json — the gallery in seq order, alt verbatim,
  role, source identity, per-tier products. A tier's `file` is a bare file
  name when it sits in this manifest's own directory, otherwise a path
  relative to the output root (a byte-identical source owned by another
  unit — spec §3.2: one set of products per sha256).
- site-manifest.json — every non-product ref grouped at the top level; file
  paths are always relative to the output root. Videos are passthrough
  entries listing their poster finals.
"""

from __future__ import annotations

import json
from pathlib import Path

from . import params
from .inventory import ManifestEntry, Plan, ProductMedia
from .state import SourceRecord, State


def _tiers(
    record: SourceRecord,
    root_relative: bool,
    own_dir: str | None,
) -> list[dict[str, object]]:
    tiers = []
    for width, tier in sorted(record.outputs.items(), key=lambda kv: int(kv[0])):
        name = tier.file if root_relative or own_dir is None else Path(tier.file).name
        tiers.append(
            {
                "w": int(width),
                "file": name,
                "bytes": tier.bytes,
                "sha256": tier.sha256,
                "mode": tier.mode,
            }
        )
    return tiers


def _top_mode(record: SourceRecord) -> str:
    """The media-level mode is the top tier's mode (the attachment file)."""
    widest = max(int(w) for w in record.outputs)
    return record.outputs[str(widest)].mode


def _source_block(
    entry: ManifestEntry,
    record: SourceRecord,
) -> dict[str, object]:
    return {
        "file": entry.local_name,
        "sha256": entry.sha256,
        "width": record.width,
        "height": record.height,
        "master_src": entry.master_src,
        "cdn_path": entry.cdn_path,
    }


def _write(path: Path, payload: dict[str, object]) -> None:
    """Write atomically; an unchanged sidecar keeps its mtime (resume proof)."""
    blob = json.dumps(payload, indent=1, ensure_ascii=False)
    if path.exists() and path.read_text(encoding="utf-8") == blob:
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(blob, encoding="utf-8")
    tmp.replace(path)


def _skip_reasons(state: State, plan: Plan) -> dict[str, str]:
    """sha256 -> why it has no products, from planning or build failures."""
    reasons: dict[str, str] = {}
    for item in state.skipped:
        sha = item.get("sha256")
        if isinstance(sha, str):
            reasons.setdefault(sha, str(item.get("reason", "unknown")))
    for item in plan.errors:
        sha = item.get("sha256")
        if isinstance(sha, str):
            reasons.setdefault(sha, str(item.get("reason", "unknown")))
    return reasons


def _product_manifest(
    handle: str,
    media: list[ProductMedia],
    plan: Plan,
    state: State,
    reasons: dict[str, str],
) -> dict[str, object]:
    entries: list[dict[str, object]] = []
    skipped: list[dict[str, object]] = []
    for m in media:
        if m.entry is None:
            skipped.append({"seq": m.seq, "url": m.url, "reason": "no manifest entry"})
            continue
        record = state.entries.get(m.entry.sha256)
        if record is None or not record.outputs:
            skipped.append(
                {
                    "seq": m.seq,
                    "url": m.url,
                    "reason": reasons.get(m.entry.sha256, "source produced no outputs"),
                }
            )
            continue
        unit = plan.unit_by_sha.get(m.entry.sha256)
        own_dir = unit.out_dir if unit is not None else None
        entries.append(
            {
                "seq": m.seq,
                "featured": m.seq == 1,
                "role": m.role,
                "alt": m.alt,
                "source": _source_block(m.entry, record),
                "mode": _top_mode(record),
                "tiers": _tiers(
                    record,
                    root_relative=own_dir != f"products/{handle}",
                    own_dir=own_dir,
                ),
            }
        )
    payload: dict[str, object] = {
        "schema": params.SCHEMA,
        "product": handle,
        "media": entries,
    }
    if skipped:
        payload["skipped"] = skipped
    return payload


def _site_manifest(
    plan: Plan,
    state: State,
    reasons: dict[str, str],
) -> dict[str, object]:
    refs: dict[str, list[dict[str, object]]] = {}
    skipped: list[dict[str, object]] = []
    for ref in sorted(plan.site_refs):
        group_entries: list[dict[str, object]] = []
        for entry in plan.site_refs[ref]:
            record = state.entries.get(entry.sha256)
            if record is None:
                skipped.append(
                    {
                        "ref": ref,
                        "local_name": entry.local_name,
                        "reason": reasons.get(
                            entry.sha256, "source produced no outputs"
                        ),
                    }
                )
                continue
            if record.video_file is not None:
                group_entries.append(
                    {
                        "ref": ref,
                        "passthrough": True,
                        "source": _source_block(entry, record),
                        "file": record.video_file,
                        "bytes": record.video_bytes,
                        "sha256": entry.sha256,
                        "posters": _posters_of(entry.sha256, plan, state),
                    }
                )
                continue
            if not record.outputs:
                skipped.append(
                    {
                        "ref": ref,
                        "local_name": entry.local_name,
                        "reason": reasons.get(
                            entry.sha256, "source produced no outputs"
                        ),
                    }
                )
                continue
            owner = plan.product_owner.get(entry.sha256)
            if owner is not None:
                handle, seq = owner
                node = plan.products[handle][seq - 1]
                alt: str | None = node.alt
                role = node.role
            else:
                alt = None
                role = params.ROLE_UNKNOWN
            group_entries.append(
                {
                    "ref": ref,
                    "role": role,
                    "alt": alt,
                    "source": _source_block(entry, record),
                    "mode": _top_mode(record),
                    "tiers": _tiers(record, root_relative=True, own_dir=None),
                }
            )
        refs[ref] = group_entries
    payload: dict[str, object] = {
        "schema": params.SCHEMA,
        "site": True,
        "refs": refs,
    }
    if skipped:
        payload["skipped"] = skipped
    return payload


def _posters_of(
    video_sha: str,
    plan: Plan,
    state: State,
) -> list[dict[str, object]]:
    """Top-tier finals of the poster frames of one passthrough video."""
    video = plan.unit_by_sha.get(video_sha)
    if video is None or video.video_name is None:
        return []
    prefix = video.read_name.split(".", 1)[0]
    posters: list[dict[str, object]] = []
    for unit in plan.units:
        if unit.sha256 == video_sha or unit.kind != "image":
            continue
        if unit.out_dir != "site/_videos" or not params.POSTER_RE.search(
            unit.read_name
        ):
            continue
        if unit.read_name.split(".", 1)[0] != prefix:
            continue
        record = state.entries.get(unit.sha256)
        if record is None or not record.outputs:
            continue
        widest = max(int(w) for w in record.outputs)
        tier = record.outputs[str(widest)]
        posters.append(
            {
                "w": widest,
                "file": tier.file,
                "bytes": tier.bytes,
                "sha256": tier.sha256,
            }
        )
    return posters


def write_sidecars(out_root: Path, plan: Plan, state: State) -> None:
    """Write every product manifest and the site manifest."""
    reasons = _skip_reasons(state, plan)
    for handle in sorted(plan.products):
        payload = _product_manifest(handle, plan.products[handle], plan, state, reasons)
        _write(out_root / "products" / handle / "manifest.json", payload)
    _write(out_root / "site-manifest.json", _site_manifest(plan, state, reasons))
