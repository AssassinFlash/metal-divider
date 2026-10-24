"""Instance names and the paths an instance derives from its name and port."""

from __future__ import annotations

from pathlib import Path

import pytest

from md_local.instance import (
    DATABASE_PREFIX,
    Instance,
    check_name,
    database_of,
    directory_of,
)


def test_good_names_pass() -> None:
    for name in ("dev", "smoke", "pytest_12_ab"):
        check_name(name)


@pytest.mark.parametrize(
    "name", ("Dev", "1dev", "not-a-name", "with space", "", "md_" + "x" * 80)
)
def test_bad_names_are_refused(name: str) -> None:
    with pytest.raises(ValueError):
        check_name(name)


def test_the_database_name_has_the_prefix() -> None:
    assert database_of("dev") == DATABASE_PREFIX + "dev"


def test_the_instance_paths_sit_under_its_directory(tmp_path: Path) -> None:
    instance = Instance("dev", 9890, tmp_path)
    assert instance.dir == directory_of(tmp_path, "dev")
    assert instance.docroot == instance.dir / "wordpress"
    assert instance.wp_content == instance.docroot / "wp-content"
    assert instance.admin_file == instance.dir / "admin.txt"
    assert instance.allow_hosts_file == instance.dir / "allow-hosts.txt"
    assert instance.isolation_log == instance.dir / "logs" / "isolation.log"
    assert instance.url == f"http://127.0.0.1:{instance.port}"
    assert instance.database == "md_dev"
