"""The inventory is the requirement: these tests hold it to docs/specs/20-staging-deploy.md."""

from __future__ import annotations

from pathlib import Path

from md_deploy import plan, steps
from md_deploy.target import Target


def target(label: str, *, staging: bool = False) -> Target:
    return Target(label, "/site", "http://site", (), None, None, staging=staging)


def test_the_staging_mu_ships_to_staging_alone() -> None:
    """The review site's guards are a property of the review site (spec 20 §3.2)."""
    repository = Path(__file__).resolve().parents[3]

    production = {
        item.relative for item in steps.deployment_artifacts(repository, target("prod"))
    }
    staging = {
        item.relative
        for item in steps.deployment_artifacts(
            repository, target("staging", staging=True)
        )
    }
    dev = {
        item.relative for item in steps.deployment_artifacts(repository, target("dev"))
    }

    assert f"{plan.MU_PLUGINS_DIR}/{plan.STAGING_MU}" in staging
    assert f"{plan.MU_PLUGINS_DIR}/{plan.STAGING_MU}" not in production
    assert f"{plan.MU_PLUGINS_DIR}/{plan.STAGING_MU}" not in dev


def test_every_mu_file_of_this_checkout_is_in_the_inventory() -> None:
    """A mu-plugin added to the checkout and forgotten here ships to no site."""
    repository = Path(__file__).resolve().parents[3]
    checkout_mu = sorted(
        path.name for path in (repository / plan.MU_PLUGINS_REPO).glob("*.php")
    )
    shipped = {
        Path(item.relative).name
        for item in steps.deployment_artifacts(
            repository, target("staging", staging=True)
        )
        if item.relative.startswith(plan.MU_PLUGINS_DIR + "/")
    }
    assert shipped == set(checkout_mu), "a must-use file exists that no target ships"


def test_a_deploy_exclude_cannot_match_a_plugins_own_file() -> None:
    """An exclude that matched a plugin's own file would ship a broken plugin."""
    for pattern in plan.DEPLOY_EXCLUDES:
        for slug in plan.PLANNED_PLUGINS:
            relative = Path(plan.REPO) / slug / f"{slug}.php"
            assert not relative.match(pattern), f"{pattern} would exclude {relative}"


def test_the_presentation_plugin_comes_first() -> None:
    """md-site owns present, which the inquiry pages render through (spec 17 D6)."""
    assert plan.PLANNED_PLUGINS[0] == "md-site"
    assert "md-inquiry" in plan.PLANNED_PLUGINS


def test_absent_artifacts_name_exactly_what_has_not_landed() -> None:
    """The interim report is the contract with the tickets that land later."""
    repository = Path(__file__).resolve().parents[3]
    absent = steps.absent_artifacts(repository)

    for slug in plan.PLANNED_PLUGINS:
        landed = (repository / plan.REPO / slug / f"{slug}.php").is_file()
        assert (f"{plan.REPO}/{slug}" in absent) != landed
    theme = repository / plan.THEMES_REPO / plan.CHILD_THEME
    assert (f"{plan.THEMES_REPO}/{plan.CHILD_THEME}" in absent) != theme.is_dir()


def test_the_verify_subset_is_absolute_paths_of_this_site() -> None:
    """A relative path would resolve against the tool's cwd, not the site."""
    for path in plan.VERIFY_PATHS:
        assert path.startswith("/")
    assert plan.PATTERN_BASE == "/pattern/", "the owner-ruled URL base (AGENTS.md §2)"


def test_the_manual_items_are_printed_and_never_claimed() -> None:
    """The dashboards are not reachable from here; the list must say what it is."""
    assert plan.MANUAL, "spec 20 §4.2 has steps only a person can do"
    assert any("Cloudflare" in item for item in plan.MANUAL)
