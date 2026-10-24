"""Corpus loading and the build plan: manifest, product join, ownership.

Facts this module encodes (spec §1.1, verified against the corpus):

- 1420 manifest entries; the 1063 product entries carry master_src and
  dimensions, the 357 site entries carry neither (null) — site dimensions
  come from decoding, site URL identity from cdn_path.
- 1340 unique local names deduplicate to 1331 unique sha256 contents: 80
  names are re-referenced by a second entry (product galleries shared with
  collection pages), 9 more are byte-identical twins under different names.
- One set of products per unique sha256 (spec §3.2): the product side owns
  the bytes, every other reference points at the product tree's files.
"""

from __future__ import annotations

import json
import re
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path

from . import params


@dataclass(frozen=True)
class ManifestEntry:
    local_name: str
    filename: str
    cdn_path: str
    master_src: str | None
    width: int | None
    height: int | None
    refs: tuple[str, ...]
    bytes: int
    sha256: str


@dataclass(frozen=True)
class ProductMedia:
    """One media node of one product, in the export's gallery order."""

    handle: str
    seq: int  # 1-based; 1 = featured = the old media[0] (#6)
    url: str
    alt: str
    role: str
    entry: ManifestEntry | None  # None = no manifest entry (a skip)


@dataclass
class SourceGroup:
    """Every manifest entry sharing one sha256 (one set of products)."""

    sha256: str
    entries: list[ManifestEntry] = field(default_factory=list)

    @property
    def read_name(self) -> str:
        return min(e.local_name for e in self.entries)

    def refs(self) -> set[str]:
        refs: set[str] = set()
        for e in self.entries:
            refs.update(e.refs)
        return refs


@dataclass(frozen=True)
class BuildUnit:
    """One buildable source: where to read, where to write, how to encode."""

    sha256: str
    kind: str  # "image" | "video"
    read_name: str
    out_dir: str  # relative to the output root, POSIX separators
    stem: str  # file name before the --{width}w.webp suffix
    force_lossless: bool
    video_name: str | None = None  # set for kind == "video"
    master_width: int | None = None  # manifest-declared, when present
    master_height: int | None = None


@dataclass
class Plan:
    units: list[BuildUnit]
    products: dict[str, list[ProductMedia]]  # handle -> gallery order
    site_refs: dict[str, list[ManifestEntry]]  # ref -> entries under it
    groups: dict[str, SourceGroup]  # sha256 -> group
    product_owner: dict[str, tuple[str, int]]  # sha256 -> (handle, seq)
    errors: list[dict[str, object]]  # skips discovered while planning

    @property
    def unit_by_sha(self) -> dict[str, BuildUnit]:
        return {u.sha256: u for u in self.units}


def load_manifest(path: Path) -> list[ManifestEntry]:
    data = json.loads(path.read_text(encoding="utf-8"))
    entries = []
    for raw in data["files"]:
        entries.append(
            ManifestEntry(
                local_name=raw["local_name"],
                filename=raw["filename"],
                cdn_path=raw["cdn_path"],
                master_src=raw["master_src"],
                width=raw["width"],
                height=raw["height"],
                refs=tuple(raw["refs"]),
                bytes=raw["bytes"],
                sha256=raw["sha256"],
            )
        )
    return entries


def parse_role(alt: str) -> str:
    """The #6 §1.2 vocabulary as a substring match on the alt text."""
    lowered = alt.lower()
    for phrase in params.ROLE_VOCAB:
        if phrase in lowered:
            return phrase
    return params.ROLE_UNKNOWN


def load_products(
    path: Path,
    by_master_src: dict[str, ManifestEntry],
) -> dict[str, list[ProductMedia]]:
    """The 146 galleries in export order, each node joined to the manifest.

    The join key is the full CDN URL (spec: 1063/1063 hits on the corpus).
    """
    data = json.loads(path.read_text(encoding="utf-8"))
    products: dict[str, list[ProductMedia]] = {}
    for raw in data["products"]:
        handle = raw["handle"]
        media: list[ProductMedia] = []
        for seq, node in enumerate(raw.get("media", {}).get("nodes", []), start=1):
            url = node["image"]["url"]
            alt = node.get("alt") or ""
            media.append(
                ProductMedia(
                    handle=handle,
                    seq=seq,
                    url=url,
                    alt=alt,
                    role=parse_role(alt),
                    entry=by_master_src.get(url),
                )
            )
        products[handle] = media
    return products


def slugify(name: str) -> str:
    """Site stems: NFKD → ASCII, [a-z0-9-], dashes folded (spec §3.1)."""
    ascii_name = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-zA-Z0-9]+", "-", ascii_name).strip("-").lower()


def ref_dir(ref: str) -> str:
    """The site subtree of a ref: page|home|collection|blog|article (§3.2)."""
    kind, _, slug = ref.partition(":")
    if not slug:
        return f"site/{kind}"
    return f"site/{kind}/{slugify(slug)}"


_LONGEST_SUFFIX = len("--9999w.webp")  # widest possible --{width}w.webp


def _fit_stem(stem: str, sha256: str) -> str:
    """Keep `{stem}--{width}w.webp` within NAME_MAX; hash suffix on overflow."""
    room = params.NAME_MAX - _LONGEST_SUFFIX
    if len(stem) <= room:
        return stem
    keep = room - len(sha256[:8]) - 1
    return f"{stem[:keep]}-{sha256[:8]}"


def _fit_video_name(slug: str, sha256: str) -> str:
    """Same length rule for a passthrough video's `{slug}.mp4`."""
    room = params.NAME_MAX - len(params.VIDEO_SUFFIX)
    if len(slug) <= room:
        return f"{slug}{params.VIDEO_SUFFIX}"
    keep = room - len(sha256[:8]) - 1
    return f"{slug[:keep]}-{sha256[:8]}{params.VIDEO_SUFFIX}"


def plan(
    manifest: list[ManifestEntry],
    products: dict[str, list[ProductMedia]],
    raw_dir: Path,
) -> Plan:
    """Resolve ownership and output names for every unique source."""
    groups: dict[str, SourceGroup] = {}
    for entry in manifest:
        groups.setdefault(entry.sha256, SourceGroup(entry.sha256)).entries.append(entry)

    # Which products reference which sha, and with what role (the cut-sheet
    # exception is decided per file set: any referencing gallery wins).
    product_refs: dict[str, list[tuple[str, int]]] = {}
    cut_sheet_shas: set[str] = set()
    for handle, media in products.items():
        for m in media:
            if m.entry is None:
                continue
            product_refs.setdefault(m.entry.sha256, []).append((handle, m.seq))
            if m.role == params.ROLE_CUT_SHEET:
                cut_sheet_shas.add(m.entry.sha256)

    errors: list[dict[str, object]] = []
    units: list[BuildUnit] = []
    taken: dict[tuple[str, str], str] = {}  # (dir, stem) -> sha256

    def claim(dir_rel: str, base_stem: str, sha256: str) -> str:
        """Reserve a stem in a directory; hash-suffix on a real collision."""
        stem = _fit_stem(base_stem, sha256)
        if (dir_rel, stem) in taken and taken[(dir_rel, stem)] != sha256:
            stem = _fit_stem(f"{base_stem}-{sha256[:8]}", sha256)
        taken[(dir_rel, stem)] = sha256
        return stem

    for sha256 in sorted(groups):
        group = groups[sha256]
        read_name = group.read_name
        master_w = next((e.width for e in group.entries if e.width is not None), None)
        master_h = next((e.height for e in group.entries if e.height is not None), None)
        if not (raw_dir / read_name).exists():
            errors.append(
                {
                    "local_name": read_name,
                    "sha256": sha256,
                    "refs": sorted(group.refs()),
                    "reason": f"source file missing from {raw_dir}",
                }
            )
            continue
        force_lossless = sha256 in cut_sheet_shas
        owners = product_refs.get(sha256)
        if owners:
            handle, seq = min(owners)
            stem = claim(f"products/{handle}", f"{handle}--{seq:02d}", sha256)
            units.append(
                BuildUnit(
                    sha256=sha256,
                    kind="image",
                    read_name=read_name,
                    out_dir=f"products/{handle}",
                    stem=stem,
                    force_lossless=force_lossless,
                    master_width=master_w,
                    master_height=master_h,
                )
            )
            continue
        if read_name.lower().endswith(params.VIDEO_SUFFIX):
            units.append(
                BuildUnit(
                    sha256=sha256,
                    kind="video",
                    read_name=read_name,
                    out_dir="site/_videos",
                    stem="",
                    force_lossless=False,
                    video_name=_fit_video_name(slugify(Path(read_name).stem), sha256),
                    master_width=master_w,
                    master_height=master_h,
                )
            )
            continue
        poster = any(params.POSTER_RE.search(e.local_name) for e in group.entries)
        out_dir = "site/_videos" if poster else ref_dir(min(group.refs()))
        stem = claim(out_dir, slugify(Path(read_name).stem), sha256)
        units.append(
            BuildUnit(
                sha256=sha256,
                kind="image",
                read_name=read_name,
                out_dir=out_dir,
                stem=stem,
                force_lossless=force_lossless,
                master_width=master_w,
                master_height=master_h,
            )
        )

    # Site side: every non-product ref of every entry, in a stable order.
    site_refs: dict[str, list[ManifestEntry]] = {}
    for entry in sorted(manifest, key=lambda e: (e.local_name, e.cdn_path)):
        for ref in entry.refs:
            if not ref.startswith("product:"):
                site_refs.setdefault(ref, []).append(entry)

    # Orphan product refs: entries a product sidecar will never contain.
    referenced = {
        m.entry.sha256 for media in products.values() for m in media if m.entry
    }
    for entry in manifest:
        if entry.refs and all(r.startswith("product:") for r in entry.refs):
            if entry.sha256 not in referenced:
                errors.append(
                    {
                        "local_name": entry.local_name,
                        "sha256": entry.sha256,
                        "refs": list(entry.refs),
                        "reason": "product ref not joined by any export media node",
                    }
                )

    # Product media nodes with no manifest entry: skips in the product sidecar.
    for handle, media in products.items():
        for m in media:
            if m.entry is None:
                errors.append(
                    {
                        "product": handle,
                        "seq": m.seq,
                        "url": m.url,
                        "reason": "no manifest entry for product media URL",
                    }
                )

    return Plan(
        units=units,
        products=products,
        site_refs=site_refs,
        groups=groups,
        product_owner={sha: min(owners) for sha, owners in product_refs.items()},
        errors=errors,
    )
