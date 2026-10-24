"""md-template-convert: the old site's rendered HTML to the repository's
LiveCanvas templates and WindPress volumes (ticket #32, spec 19).

Usage:
  md-template-convert --raw .scratch/32/raw \
      --theme-assets ~/Documents/shopify-builder/theme/assets \
      --plugin plugins/md-site \
      --media-state ~/Documents/metal-divider/data/media-final/_state.json \
      [--scratch .scratch/32]

The inputs are the ticket's evidence, not shipped artefacts: the raw
pages under --raw, the old theme's stylesheets, and the media pipeline's
state (the source-address → final-name map). The outputs land in the
plugin's content/ directories; the evidence (asset map, DEBT ledger,
per-page reports) under --scratch.
"""

from __future__ import annotations

import argparse
import json
from collections.abc import Callable
from pathlib import Path

from .blogfork import fork_templates, fork_volumes
from .convert import Converter
from .pageplan import PAGES
from .slots import pdp_sample, slot_grid, slot_hub, slot_pdp
from .upgrade import upgrade


def _slot_for(
    page: dict[str, str], raw_files: list[Path]
) -> Callable[[str], str] | None:
    """The page's slot pass, as a callable over each section's raw body.

    The PDP's slot anchors on the representative sample's own values,
    extracted from the same raw page; the grid and hub slots carry no
    per-sample values.
    """
    kind = page.get("slot")
    if kind == "pdp":
        sample_html = raw_files[0].read_text(encoding="utf-8")
        from .sections import section_html, sections

        bodies = [
            section_html(sample_html, section)
            for section in sections(sample_html)
            if section.name in ("breadcrumb", "pattern_product_cinematic")
        ]
        sample = pdp_sample("\n".join(bodies))
        return lambda body: slot_pdp(body, sample)
    if kind == "grid":
        return slot_grid
    if kind == "hub":
        return slot_hub
    return None


def _pdp_partials(
    raw_dir: Path, converter: Converter, raw_file: Path
) -> dict[str, str]:  # noqa: ARG001
    """The PDP's two single-source content partials (spec 19 §5.2), cut
    from the same representative sample and moved through the same
    transforms: the where-works rows and the technical FAQ — payloads
    the old site carried identically on all 146 products (spec 08 F2).
    """
    import re

    from .csslib import Minter
    from .sections import section_html, sections

    html = raw_file.read_text(encoding="utf-8")
    body = ""
    for section in sections(html):
        if section.name == "pattern_product_cinematic":
            body = section_html(html, section)
    out: dict[str, str] = {}
    minter = Minter()
    patterns = {
        "md-where-works": r'(<div class="pdp-apps-rows[^"]*"[^>]*>)(.*?)(</div>)',
        "md-pdp-faq": r'(<div class="pdp-faq-list[^"]*"[^>]*>)(.*?)(</div>)',
    }
    for name, pattern in patterns.items():
        match = re.search(pattern, body, re.DOTALL)
        if match is None:
            raise SystemExit(f"PDP partial {name}: anchor not found in {raw_file}")
        piece, _s, _i, _blocks = converter.convert_body(match.group(2), minter)
        out[name] = piece
    return out


def _asset_map(media_state: Path) -> dict[str, str]:
    """The media pipeline's answers: source address → final file name.

    The state file indexes by source sha256; the products' manifests
    under media-final are not written yet (ticket #31 imports from
    them), so the map is built from the state entries' master sources
    where the pipeline recorded them. Addresses without an entry fall
    back to the converter's derived stem (transform.asset_name), which
    the importer resolves against the built tiers.
    """
    if not media_state.is_file():
        return {}
    state = json.loads(media_state.read_text(encoding="utf-8"))
    mapping: dict[str, str] = {}
    for entry in state.get("entries", {}).values():
        master = entry.get("master_src")
        outputs = entry.get("outputs", {})
        top = max(
            outputs.values(),
            key=lambda out: out.get("w", 0) if isinstance(out, dict) else 0,
            default=None,
        )
        if master and isinstance(top, dict) and top.get("file"):
            mapping[master] = str(top["file"]).rsplit("/", 1)[-1]
    return mapping


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="md-template-convert")
    parser.add_argument("--raw", type=Path, default=None)
    parser.add_argument("--theme-assets", type=Path, default=None)
    parser.add_argument("--plugin", type=Path, required=True)
    parser.add_argument("--media-state", type=Path, default=None)
    parser.add_argument("--scratch", type=Path, default=None)
    parser.add_argument(
        "--upgrade",
        action="store_true",
        help="Apply the upgrade transforms (spec 19 §4.3's second commit) to the converted templates: T5 inquiry forms to the md-inquiry-cta partial.",
    )
    parser.add_argument(
        "--blog-source",
        type=Path,
        default=None,
        help="The de-linker content/ directory to fork the journal faces from (spec 19 R1).",
    )
    args = parser.parse_args(argv)

    if args.blog_source:
        templates = fork_templates(args.blog_source, args.plugin / "content")
        blog_volumes = fork_volumes(args.blog_source, args.plugin / "content")
        print(f"blog fork: {len(templates)} templates, {len(blog_volumes)} volumes")
        return 0

    if args.raw is None or args.theme_assets is None:
        parser.error(
            "--raw and --theme-assets are required unless --blog-source is given"
        )

    asset_map = _asset_map(args.media_state) if args.media_state else {}
    converter = Converter(args.raw, args.theme_assets, args.plugin, asset_map)

    # The chrome partials first (every template depends on them, spec 19
    # §8's "chrome before content" order), through the same transforms,
    # their styles into chrome.css.
    partials = converter.convert_chrome()
    converter.record_chrome_classes(partials)
    partials_dir = args.plugin / "content" / "livecanvas" / "partials"
    partials_dir.mkdir(parents=True, exist_ok=True)
    for name, html in partials.items():
        (partials_dir / f"{name}.html").write_text(html + "\n", encoding="utf-8")

    reports: list[dict[str, object]] = []
    for page in PAGES:
        raw_files = sorted(args.raw.glob(page["raw"]))
        if not raw_files:
            raise SystemExit(f"missing raw page(s): {args.raw / page['raw']}")
        # A slotted face converts from its representative input; the
        # other samples are the cross-check the slot pass runs against
        # (spec 19 §2.2: the nine PDP representatives, the eight series
        # pages, are the skeleton-consistency check, not extra inputs).
        raw_file = raw_files[0]
        slot = _slot_for(page, raw_files)
        report = converter.convert_page(raw_file, page["template"], slot=slot)
        report["title"] = page["title"]
        reports.append(report)
        template_html = str(report["html"])
        if args.upgrade:
            template_html, upgraded = upgrade(template_html)
            report["upgrades"] = upgraded
        out = args.plugin / "content" / "livecanvas" / f"{page['template']}.html"
        out.write_text(template_html + "\n", encoding="utf-8")
        if page.get("slot") == "pdp":
            # The two single-source partials the PDP template references,
            # cut from the same sample in the same run.
            for name, piece in _pdp_partials(args.raw, converter, raw_file).items():
                (partials_dir / f"{name}.html").write_text(
                    piece + "\n", encoding="utf-8"
                )

    # Every page's classes are known now: the one distribution pass
    # files each stylesheet rule into its single volume.
    converter.distribute()
    volumes = converter.write()
    if args.scratch:
        args.scratch.mkdir(parents=True, exist_ok=True)
        (args.scratch / "debt-ledger.json").write_text(
            json.dumps(converter.debt, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        (args.scratch / "asset-map.json").write_text(
            json.dumps(asset_map, indent=2, sort_keys=True) + "\n", encoding="utf-8"
        )
    print(
        f"templates: {len(reports)} + 2 partials; volumes: {len(volumes)}; "
        f"raw pages: {args.raw}"
    )
    return 0
