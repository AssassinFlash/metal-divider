"""Checkpoint state tests: round trip and the cheap first check (§6.3)."""

from __future__ import annotations

from pathlib import Path

from md_media import params, state


def _record(out_root: Path) -> state.SourceRecord:
    tier_file = out_root / "site/home/hero--480w.webp"
    tier_file.parent.mkdir(parents=True, exist_ok=True)
    tier_file.write_bytes(b"tier-bytes")
    return state.SourceRecord(
        width=1600,
        height=1000,
        sniffed="JPEG",
        master_width=1600,
        master_height=1000,
        outputs={
            "480": state.TierRecord(
                file="site/home/hero--480w.webp",
                bytes=len(b"tier-bytes"),
                sha256="ab" * 32,
                mtime=tier_file.stat().st_mtime,
                mode=params.MODE_Q85,
            )
        },
    )


def test_state_round_trip(tmp_path: Path) -> None:
    record = _record(tmp_path)
    st = state.State(fingerprint="fp", entries={"sha": record})
    state.save(tmp_path, st)
    loaded = state.load(tmp_path)
    assert loaded is not None
    assert loaded.fingerprint == "fp"
    assert list(loaded.entries) == ["sha"]
    back = loaded.entries["sha"]
    assert back.width == 1600 and back.sniffed == "JPEG"
    assert back.outputs["480"].file == "site/home/hero--480w.webp"
    assert back.outputs["480"].mtime == record.outputs["480"].mtime


def test_outputs_current_passes_the_first_check(tmp_path: Path) -> None:
    st = state.State(fingerprint="fp", entries={"sha": _record(tmp_path)})
    assert st.outputs_current(tmp_path, st.entries["sha"])


def test_outputs_current_fails_on_missing_size_or_mtime(tmp_path: Path) -> None:
    st = state.State(fingerprint="fp", entries={"sha": _record(tmp_path)})
    record = st.entries["sha"]
    tier = tmp_path / record.outputs["480"].file
    keep = tier.read_bytes()

    def resync() -> None:
        """Rewrite the file and re-baseline the record against reality."""
        tier.write_bytes(keep)
        record.outputs["480"].mtime = tier.stat().st_mtime

    tier.unlink()
    assert not st.outputs_current(tmp_path, record)  # gone

    resync()
    assert st.outputs_current(tmp_path, record)  # healthy again

    tier.write_bytes(keep + b"x")
    assert not st.outputs_current(tmp_path, record)  # size drift

    resync()
    assert st.outputs_current(tmp_path, record)

    record.outputs["480"].mtime += 1
    assert not st.outputs_current(tmp_path, record)  # mtime drift


def test_video_records_round_trip_without_tiers(tmp_path: Path) -> None:
    video = tmp_path / "site/_videos/clip.mp4"
    video.parent.mkdir(parents=True, exist_ok=True)
    video.write_bytes(b"\x00\x00\x00\x18ftypisom")
    st = state.State(
        fingerprint="fp",
        entries={
            "vsha": state.SourceRecord(
                width=None,
                height=None,
                sniffed="MP4",
                master_width=None,
                master_height=None,
                video_file="site/_videos/clip.mp4",
                video_bytes=video.stat().st_size,
            )
        },
    )
    state.save(tmp_path, st)
    loaded = state.load(tmp_path)
    assert loaded is not None
    record = loaded.entries["vsha"]
    assert record.width is None and record.video_file == "site/_videos/clip.mp4"
    assert st.outputs_current(tmp_path, record)
