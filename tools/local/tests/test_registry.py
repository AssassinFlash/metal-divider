"""The registry: allocation, persistence, and the port it gives each instance."""

from __future__ import annotations

from pathlib import Path

import pytest
from pytest import MonkeyPatch

from md_local import processes, registry
from md_local.instance import Instance
from md_local.registry import FIRST_PORT, Registry


@pytest.fixture()
def root(tmp_path: Path, monkeypatch: MonkeyPatch) -> Path:
    monkeypatch.setattr(processes, "port_is_free", lambda port: True)
    # SHARED carries this Mac's real registry: the instance running on 9890
    # would be visible through the cross-root port check and shift every
    # allocation. The tests run against their own machine state.
    monkeypatch.setattr(registry, "SHARED", tmp_path)
    return tmp_path


def test_a_new_instance_gets_the_first_port(root: Path) -> None:
    instance = Registry(root).register("dev")
    assert instance.port == FIRST_PORT
    assert instance.root == root
    assert instance.created


def test_the_second_instance_gets_the_next_port(root: Path) -> None:
    first = Registry(root).register("dev")
    second = Registry(root).register("second")
    assert second.port == first.port + 1


def test_a_taken_port_is_skipped(root: Path, monkeypatch: MonkeyPatch) -> None:
    free = {FIRST_PORT, FIRST_PORT + 2}
    monkeypatch.setattr(processes, "port_is_free", lambda port: port in free)
    first = Registry(root).register("dev")
    assert first.port == FIRST_PORT
    assert Registry(root).register("other").port == FIRST_PORT + 2


def test_registering_an_existing_name_changes_nothing(root: Path) -> None:
    first = Registry(root).register("dev")
    again = Registry(root).register("dev")
    assert again == first


def test_the_registry_persists_and_reads_back(root: Path) -> None:
    Registry(root).register("dev")
    read = Registry(root).get("dev")
    assert read is not None
    assert read.name == "dev"
    assert read.port == FIRST_PORT
    assert Registry(root).all() == [read]


def test_record_writes_the_checkout_link(root: Path) -> None:
    registry = Registry(root)
    instance = registry.register("dev")
    assert instance.checkout == ""
    with_checkout = Instance(
        instance.name, instance.port, instance.root, "/a/checkout", instance.created
    )
    registry.record(with_checkout)
    assert Registry(root).get("dev") == with_checkout


def test_deregister_removes_the_instance(root: Path) -> None:
    Registry(root).register("dev")
    Registry(root).deregister("dev")
    assert Registry(root).get("dev") is None


def test_names_are_checked(root: Path) -> None:
    with pytest.raises(ValueError):
        Registry(root).register("not-a-name")


def test_another_data_root_shares_this_macs_ports(
    tmp_path: Path, monkeypatch: MonkeyPatch
) -> None:
    # The real stack on this Mac (data root ~/metal-divider-local) and a test
    # run's own root still share one machine: a port the other root holds is
    # never handed out here (de-linker on 8888.. is the same design).
    monkeypatch.setattr(processes, "port_is_free", lambda port: True)
    shared = tmp_path / "shared"
    other = tmp_path / "other"
    # Shield the real machine registry first, then build the two roots' state.
    monkeypatch.setattr(registry, "SHARED", shared)
    Registry(shared).register("dev")  # takes FIRST_PORT on this "machine"
    assert Registry(other).register("here").port == FIRST_PORT + 1
