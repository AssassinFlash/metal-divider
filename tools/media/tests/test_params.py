"""Parameter tests: tier widths and the fingerprint (spec §2.3, §6.3)."""

from __future__ import annotations

from md_media import params


def test_tiers_narrow_source_gets_only_itself() -> None:
    assert params.tier_widths(300) == (300,)
    assert params.tier_widths(480) == (480,)


def test_tiers_original_top_tier_between_ladder_steps() -> None:
    assert params.tier_widths(500) == (480, 500)
    assert params.tier_widths(1500) == (480, 768, 1024, 1500)
    assert params.tier_widths(2047) == (480, 768, 1024, 1536, 2047)


def test_tiers_cap_at_2048_without_upscaling() -> None:
    assert params.tier_widths(2048) == (480, 768, 1024, 1536, 2048)
    assert params.tier_widths(3000) == (480, 768, 1024, 1536, 2048)


def test_fingerprint_is_stable_per_code_state() -> None:
    assert params.params_digest() == params.params_digest()
