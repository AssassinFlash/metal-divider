"""The chat plane's inventory and rewrites, held against the module's own contracts."""

from __future__ import annotations

import io
import json
import tarfile
import time
from pathlib import Path
from types import SimpleNamespace
from typing import cast
from urllib.parse import urlparse

import pytest

from md_deploy import backup as backup_module
from md_deploy import chat, steps
from md_deploy import chat as chat_module
from md_deploy import snapshot as snapshot_module
from md_deploy.target import DeployError, Target

REPO_ROOT = Path(__file__).resolve().parents[3]


def test_rewrite_sql_rewrites_only_the_raplsaich_prefix() -> None:
    """The prefix rule names raplsaich_ tables alone; every other table keeps its prefix."""
    sql = (
        "CREATE TABLE `stg_raplsaich_knowledge` (`id` INT);"
        "INSERT INTO `stg_raplsaich_knowledge` VALUES (1);"
        "CREATE TABLE `stg_posts` (`id` INT);"
        "INSERT INTO `stg_custom_table` VALUES (2);"
    )

    result = chat.rewrite_sql_for_target(
        sql,
        golden_prefix="stg_",
        target_prefix="wp_",
        golden_host="staging.metal-divider.com",
        target_host="metal-divider.com",
    )

    assert "`wp_raplsaich_knowledge`" in result
    assert "`stg_raplsaich_knowledge`" not in result
    assert "`stg_posts`" in result, "a non-raplsaich table keeps its prefix"
    assert "`stg_custom_table`" in result, "a backtick-quoted name is untouched"


def test_rewrite_sql_rewrites_the_golden_host_in_every_wrapped_form() -> None:
    """The seed stores the host plain, JSON-escaped, and in SMTP addresses.

    The hostname bytes themselves are never escaped, so one bare replace covers
    all three wrapped forms without disturbing their surrounding punctuation.
    """
    sql = (
        "VALUES ('https://staging.metal-divider.com/chat/index.php', "
        r"'https:\/\/staging.metal-divider.com\/chat/index.php', "
        "'info@staging.metal-divider.com');"
    )

    result = chat.rewrite_sql_for_target(
        sql,
        golden_prefix="wp_",
        target_prefix="wp_",  # prefix equal: only the host rewrite runs
        golden_host="staging.metal-divider.com",
        target_host="metal-divider.com",
    )

    assert "https://metal-divider.com/chat/index.php" in result
    assert r"https:\/\/metal-divider.com\/chat/index.php" in result, (
        "the JSON-escaped slashes survive the host rewrite"
    )
    assert "info@metal-divider.com" in result
    assert "staging.metal-divider.com" not in result


def test_rewrite_sql_is_the_identity_when_prefix_and_host_match() -> None:
    """A target already on the golden prefix and host is changed in no byte."""
    sql = (
        "INSERT INTO `wp_raplsaich_knowledge` VALUES "
        "('https://metal-divider.com/chat', 'info@metal-divider.com');"
    )

    result = chat.rewrite_sql_for_target(
        sql,
        golden_prefix="wp_",
        target_prefix="wp_",
        golden_host="metal-divider.com",
        target_host="metal-divider.com",
    )

    assert result == sql


def test_the_extensions_are_exactly_the_two_visitor_surfaces() -> None:
    """mdvisitor and mdmobile are the only LHC extensions that ship from the repo."""
    assert chat.LHC_EXTENSIONS == ("mdvisitor", "mdmobile")


def test_the_mu_plugins_are_exactly_the_five_chat_surfaces() -> None:
    """Every chat mu-plugin ships, and nothing more: the launcher, delete, e-mail,
    KB endpoint, and WeCom notify."""
    assert chat.CHAT_MU_PLUGINS == (
        "md-chat-launcher.php",
        "md-chat-delete.php",
        "md-chat-email.php",
        "md-kb-endpoint.php",
        "md-wecom-notify.php",
    )


def test_chat_push_plan_names_every_layer_it_touches() -> None:
    """The plan is the inventory: extensions, mu-plugins, the Rapls tree, excludes."""
    plan = "\n".join(chat.chat_push_plan())

    assert ", ".join(chat.LHC_EXTENSIONS) in plan
    for mu in chat.CHAT_MU_PLUGINS:
        assert mu in plan
    assert chat.RAPLS_PLUGIN_DIR in plan
    for exclude in ("settings/", "cache/", "var/"):
        assert exclude in plan


def test_verify_probes_are_five_https_urls_under_the_base() -> None:
    """Each probe's URL is well-formed and answers under the target's own host."""
    base = "https://staging.metal-divider.com"
    probes = chat.chat_verify_probes(base)

    assert len(probes) == 5
    for name, url, marker in probes:
        assert name, "every probe has a name"
        parsed = urlparse(url)
        assert parsed.scheme == "https", url
        assert parsed.netloc == "staging.metal-divider.com", url
        assert marker, "every probe asserts on a body marker"


def test_verify_probe_markers_are_base_url_independent() -> None:
    """A marker naming the host would pass staging and fail production at once."""
    for _, _, marker in chat.chat_verify_probes("https://staging.metal-divider.com"):
        assert "staging" not in marker


def test_option_specs_are_name_safe_secret_triples() -> None:
    """Each option row carries credentials, so its secret tuple must never be empty.

    An empty secret tuple would make the exporter ship the whole row through the
    seed file, which the docstring forbids ("never through the seed file").
    """
    for name, safe, secret in chat.CHAT_OPTION_SPECS:
        assert isinstance(name, str) and name
        assert isinstance(safe, tuple)
        assert isinstance(secret, tuple) and secret, name
    assert [name for name, _, _ in chat.CHAT_OPTION_SPECS] == [
        "md_wecom_notify",
        "md_kb_endpoint",
    ]


def test_the_lhc_seed_tables_name_the_config_surface() -> None:
    """The seed whitelist is the config surface: bot chain, operators, catalogs."""
    for table in (
        "lh_departament",
        "lh_generic_bot_bot",
        "lh_generic_bot_trigger",
        "lh_webhook",
        "lh_users",
        "lh_chat_config",
        "lh_canned_msg",
        "lh_abstract_email_template",
    ):
        assert table in chat.LHC_SEED_TABLES, table
    # credential-bearing tables ship in their own sanitized file
    assert "lh_generic_bot_rest_api" not in chat.LHC_SEED_TABLES
    assert set(chat.LHC_SEED_SECRET_TABLES) == {
        "lh_generic_bot_rest_api",
        "lh_abstract_rest_api_key",
    }


def test_the_rapls_seed_tables_name_the_knowledge() -> None:
    """Only the knowledge rows ship; settings and index are per-target."""
    assert chat.RAPLS_SEED_TABLES == ("raplsaich_knowledge",)
    for table in ("raplsaich_settings", "raplsaich_index", "raplsaich_unanswered_log"):
        assert table not in chat.RAPLS_SEED_TABLES


def test_runtime_tables_appear_in_no_seed_list() -> None:
    """Chats, messages and notification bookkeeping never leave the host."""
    runtime = (
        "lh_chat",
        "lh_msg",
        "lh_chat_online_user",
        "md_wecom_notify_chats",
        "md_wecom_notify_sends",
        "raplsaich_unanswered_log",
    )
    seed_lists = (
        chat.LHC_SEED_TABLES,
        chat.LHC_SEED_SECRET_TABLES,
        chat.RAPLS_SEED_TABLES,
    )
    for table in runtime:
        for seed_list in seed_lists:
            assert table not in seed_list, (table, seed_list)


def test_runtime_options_are_excluded_from_the_seed() -> None:
    """The cooldown and send-rate maps are runtime state, not configuration."""
    spec_names = {name for name, _safe, _secret in chat.CHAT_OPTION_SPECS}
    for option in ("md_wecom_notify_chats", "md_wecom_notify_sends"):
        assert option in chat.CHAT_OPTION_EXCLUDES, option
        assert option not in spec_names, option


def test_rest_probe_targets_the_namespace_index() -> None:
    """The REST probe GETs the namespace index and greps the escaped route."""
    base = "https://staging.metal-divider.com"
    probes = chat.chat_verify_probes(base)
    rest = [p for p in probes if p[0] == "rest-route-exists"]
    assert len(rest) == 1
    _name, url, marker = rest[0]
    assert url == base + "/wp-json/md/v1"
    assert marker == "kb" + chr(92) + "/answer", (
        "PHP json_encode escapes the slash; the plain form never matches"
    )


def test_seed_files_separate_what_ships_from_what_is_local_only() -> None:
    """schema is local validation only; the hash restore is not a file at all."""
    for key in ("tables", "secret_tables", "knowledge", "options"):
        assert "local-only" not in chat.SEED_FILES[key], key
    assert "local-only" in chat.SEED_FILES["schema"]
    # The retired password-hash restore must not appear as any seed file;
    # it survives only as a defensive skip for old tarballs.
    for filename in chat.SEED_FILES.values():
        assert "passwords" not in filename, filename
    assert chat.SEED_LOCAL_ONLY_NAMES == ("staging-passwords-local-only.sql",)
    # The shipped seed is SQL; the options row travels as JSON.
    assert chat.SEED_FILES["options"].endswith(".json")
    assert chat.SEED_FILES["tables"].endswith(".sql")


def test_push_plan_names_only_operations_the_code_runs() -> None:
    """Every plan line is executed, in execution order; host-side steps stay manual."""
    plan = chat.chat_push_plan()
    assert plan[-1].startswith("defer to the runbook for the manual items:")
    joined = "\n".join(plan)
    for executed in (
        "snapshot the target database first",
        "open the maintenance window",
        "merge the chat option rows on the target host",
        "purge the LHC compiled-template cache",
        "hash-verify the shipped lhc-extensions and mu-plugins",
        "close the maintenance window",
    ):
        assert executed in joined
    assert "installer state check" not in joined, "not executed by chat-push"


def test_push_plan_lists_the_envelope_in_execution_order() -> None:
    """Snapshot first, the window around every mutation, purge after it closes."""
    plan = chat.chat_push_plan()
    snapshot = next(i for i, s in enumerate(plan) if s.startswith("snapshot"))
    opened = next(
        i for i, s in enumerate(plan) if s.startswith("open the maintenance window")
    )
    relay = next(i for i, s in enumerate(plan) if s.startswith("relay the golden"))
    closed = next(i for i, s in enumerate(plan) if s == "close the maintenance window")
    purge = next(i for i, s in enumerate(plan) if s.startswith("purge the LHC"))
    assert snapshot < opened < relay < closed < purge
    assert purge == len(plan) - 2, "only the manual runbook line may follow"
    assert closed < purge, "purge runs after the window: the notify 503s inside it"


def test_golden_skip_keys_cover_every_sanitized_seed_file() -> None:
    """The golden host must skip every seed file that carries sanitized credentials."""
    importable = chat.seed_import_files()
    for key in chat.GOLDEN_SKIP_KEYS:
        assert key in chat.SEED_FILES
        assert chat.SEED_FILES[key] in importable, (
            f"{key} must be importable on real targets"
        )
        assert key in chat.GOLDEN_SKIP_REASONS, (
            f"{key} needs a skip reason for the actions log"
        )
    assert set(chat.GOLDEN_SKIP_KEYS) == {
        "users",
        "users_setting",
        "chat_config",
        "secret_tables",
    }


def test_is_golden_target_is_label_or_base_url() -> None:
    """The rehearsal (staging onto itself) must be recognized as the golden host."""

    class Fake:
        def __init__(self, label: str, base_url: str) -> None:
            self.label = label
            self.base_url = base_url

    def as_target(label: str, base_url: str) -> Target:
        return cast(Target, Fake(label, base_url))

    golden = as_target("staging", "https://staging.metal-divider.com")
    assert chat.is_golden_target(as_target("staging", "https://other.example"), golden)
    assert chat.is_golden_target(
        as_target("canary", "https://staging.metal-divider.com/"), golden
    ), "a relabeled pointer at the same site is still the golden host"
    assert not chat.is_golden_target(
        as_target("prod", "https://metal-divider.com"), golden
    )
    assert not chat.is_golden_target(as_target("dev", "http://localhost:8080"), golden)


class _PushTarget:
    """A fake remote target with enough surface for one chat_push run."""

    def __init__(self, *, label: str, base_url: str, root: str) -> None:
        self.label = label
        self.base_url = base_url
        self.root = root
        self.rsync_remote = f"{label}-host:"
        self.shell_calls: list[str] = []
        self.wp_calls: list[tuple[str, ...]] = []
        self.copied_in: list[str] = []
        self.copied_files: list[str] = []
        self.shell_error: str | None = None
        self.eval_file_output: str | None = None

    def path_of(self, relative: str) -> str:
        return f"{self.root}/{relative}"

    def shell(self, script: str) -> str:
        if self.shell_error is not None:
            raise DeployError(self.shell_error)
        self.shell_calls.append(script)
        return ""

    def wp_on_target(self) -> tuple[str, ...]:
        return ("wp", "--path", self.root)

    def wp(self, *args: str, check: bool = True) -> str:
        self.wp_calls.append(args)
        if args[:3] == ("config", "get", "table_prefix"):
            return "wp_\n"
        if args[0] == "eval":
            marker_set = any(
                a[:3] == ("option", "update", chat.BRINGUP_OPTION)
                for a in self.wp_calls
            )
            if chat.BRINGUP_OPTION in args[1] and marker_set:
                return "true\n"
            return "false\n"
        if args[0] == "eval-file":
            if self.eval_file_output is not None:
                return self.eval_file_output
            return f"OK {args[2]} merged=1 total=2"
        return "Success"

    def copy_in(self, source: Path, relative: str) -> None:
        assert source.is_dir(), source
        self.copied_in.append(relative)

    def copy_file_in(self, source: Path, relative: str) -> None:
        """Mirrors the real Target contract: absolute relatives are rejected."""
        assert source.is_file(), source
        if relative.startswith("/"):
            raise DeployError(f"{relative!r} is not a path inside the WordPress root")
        self.copied_files.append(relative)

    def tree(self, relative: str) -> list[str]:
        source = REPO_ROOT / "lhc-extensions" / relative.removeprefix("chat/extension/")
        lines = []
        for path in sorted(source.rglob("*")):
            if path.is_file() and not path.is_symlink():
                rel = str(path.relative_to(source))
                lines.append(f"{backup_module.sha256(path)}  ./{rel}")
        return lines


def _write_minimal_seed(home: Path, *, with_credentials: bool) -> None:
    out = chat.seed_directory(home)
    out.mkdir(parents=True, exist_ok=True)
    for key in ("tables", "knowledge"):
        (out / chat.SEED_FILES[key]).write_text(
            "-- INSERT INTO stg_raplsaich_knowledge;", encoding="utf-8"
        )
    if with_credentials:
        for key in ("users", "users_setting", "chat_config", "secret_tables"):
            (out / chat.SEED_FILES[key]).write_text(
                "-- REPLACE INTO stg_lh_users;", encoding="utf-8"
            )
    payload = {
        "md_wecom_notify": {"lhc_base_url": "https://staging.metal-divider.com/chat"},
        "md_kb_endpoint": {"transfer_words": ["price"]},
    }
    (out / chat.SEED_FILES["options"]).write_text(json.dumps(payload), encoding="utf-8")


def test_chat_push_runs_inside_the_snapshot_and_maintenance_envelope(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Snapshot first, window around every mutation, closed even on failure."""
    target = _PushTarget(
        label="prod", base_url="https://metal-divider.com", root="/home/prod"
    )
    golden = _PushTarget(
        label="staging",
        base_url="https://staging.metal-divider.com",
        root="/home/golden",
    )
    monkeypatch.setattr(chat, "named_target", lambda _name: golden)
    order: list[str] = []

    def _take_success(tgt: object, home: Path) -> SimpleNamespace:
        order.append("snapshot")
        return SimpleNamespace(name="db-20261028T000000Z.sql.gz")

    def _maintenance_success(tgt: object, *, on: bool) -> str:
        order.append("on" if on else "off")
        return "ok"

    monkeypatch.setattr(
        snapshot_module,
        "take",
        _take_success,
    )
    monkeypatch.setattr(
        steps,
        "maintenance",
        _maintenance_success,
    )
    monkeypatch.setattr(backup_module, "stream", lambda *a, **k: order.append("import"))
    _write_minimal_seed(tmp_path, with_credentials=True)

    result = chat.chat_push(cast(Target, target), repository=REPO_ROOT, home=tmp_path)

    assert order[0] == "snapshot"
    assert order[1] == "on"
    assert order[-1] == "off", "the window must close even when a later step fails"
    assert "import" in order
    joined = "\n".join(result.actions)
    assert "open the maintenance window" in joined
    assert "close the maintenance window" in joined
    assert chat.BRINGUP_OPTION in joined, "the one-time credential import is marked"
    marker_updates = [
        args for args in target.wp_calls if args[:2] == ("option", "update")
    ]
    assert any(args[2] == chat.BRINGUP_OPTION for args in marker_updates)
    assert not target.copied_in or all(
        rel.startswith("chat/extension/") for rel in target.copied_in
    )


def test_chat_push_closes_the_window_when_a_step_fails(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    target = _PushTarget(
        label="prod", base_url="https://metal-divider.com", root="/home/prod"
    )
    golden = _PushTarget(
        label="staging",
        base_url="https://staging.metal-divider.com",
        root="/home/golden",
    )
    monkeypatch.setattr(chat, "named_target", lambda _name: golden)
    windows: list[str] = []

    def _maintenance_windows(tgt: object, *, on: bool) -> str:
        windows.append("on" if on else "off")
        return "ok"

    monkeypatch.setattr(
        steps,
        "maintenance",
        _maintenance_windows,
    )
    monkeypatch.setattr(
        snapshot_module,
        "take",
        lambda tgt, home: SimpleNamespace(name="db.sql.gz"),
    )
    monkeypatch.setattr(backup_module, "stream", lambda *a, **k: None)
    target.shell_error = "relay failed"
    _write_minimal_seed(tmp_path, with_credentials=True)

    with pytest.raises(DeployError, match="relay failed"):
        chat.chat_push(cast(Target, target), repository=REPO_ROOT, home=tmp_path)
    assert windows == ["on", "off"]


def test_chat_push_on_the_golden_host_skips_relays_and_credentials(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The rehearsal must neither rsync a tree onto itself nor blank secrets."""
    shared = _PushTarget(
        label="staging",
        base_url="https://staging.metal-divider.com",
        root="/home/golden",
    )
    monkeypatch.setattr(chat, "named_target", lambda _name: shared)
    monkeypatch.setattr(
        snapshot_module, "take", lambda tgt, home: SimpleNamespace(name="db")
    )
    windows: list[str] = []

    def _maintenance_windows(tgt: object, *, on: bool) -> str:
        windows.append("on" if on else "off")
        return "ok"

    monkeypatch.setattr(
        steps,
        "maintenance",
        _maintenance_windows,
    )
    monkeypatch.setattr(backup_module, "stream", lambda *a, **k: None)
    _write_minimal_seed(tmp_path, with_credentials=True)

    result = chat.chat_push(cast(Target, shared), repository=REPO_ROOT, home=tmp_path)

    joined = "\n".join(result.actions)
    assert "skip the /chat/ relay: source and destination are the same path" in joined
    for key in chat.GOLDEN_SKIP_KEYS:
        assert f"skip {chat.SEED_FILES[key]}:" in joined, key
    assert chat.BRINGUP_OPTION not in joined, "no marker on the golden host"
    assert any("rsync" in call for call in shared.shell_calls) is False, (
        "no tree relay at all"
    )
    assert not any(
        "merge-chat-option" in rel or rel.startswith("/") for rel in shared.copied_files
    ), "the merge script never rides copy_file_in"
    assert any(a[0] == "eval-file" for a in shared.wp_calls), (
        "the sanitized option merge still runs on the golden host"
    )
    for a in shared.wp_calls:
        if a[0] == "eval-file":
            assert not a[1].startswith("/") or a[1].startswith(
                "/tmp/md-merge-chat-option-"
            ), a[1]
    assert not any(
        a[:3] == ("option", "update", chat.BRINGUP_OPTION) for a in shared.wp_calls
    ), "no marker write on the golden host"


def test_remote_target_rejects_absolute_temp_paths() -> None:
    """Target.path_of cannot place a file into /tmp; the merge script
    must therefore ride an SSH pipe, never copy_file_in."""
    from md_deploy.target import remote_target

    target = remote_target("staging")
    with pytest.raises(DeployError):
        target.address("/tmp/merge-chat-option.php")


def _options_target() -> _PushTarget:
    return _PushTarget(
        label="prod", base_url="https://metal-divider.com", root="/home/prod"
    )


def _write_options_seed(out_dir: Path, payload: dict[str, object]) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / chat.SEED_FILES["options"]).write_text(
        json.dumps(payload), encoding="utf-8"
    )


def test_write_options_ships_the_merge_script_through_the_shell(
    tmp_path: Path,
) -> None:
    target = _options_target()
    _write_options_seed(
        tmp_path,
        {
            "md_wecom_notify": {
                "lhc_base_url": "https://staging.metal-divider.com/chat"
            },
            "md_kb_endpoint": {"transfer_words": ["price"]},
        },
    )

    written = chat._write_options(
        cast(Target, target),
        tmp_path,
        golden_host="staging.metal-divider.com",
        target_host="metal-divider.com",
        notes=[],
    )

    assert written == ["md_wecom_notify", "md_kb_endpoint"]
    puts = [c for c in target.shell_calls if "base64 -d" in c]
    assert puts, "the merge script must reach the host over SSH"
    assert "chmod 600" in puts[0]
    assert "/tmp/md-merge-chat-option-" in puts[0]
    assert any(
        c.startswith("rm -f /tmp/md-merge-chat-option-") for c in target.shell_calls
    ), "the script must be removed afterwards"
    assert not target.copied_files, "the merge script must not ride copy_file_in"


def test_import_sql_hands_stream_a_real_file_descriptor() -> None:
    """subprocess needs a real fd; a BytesIO stdin crashed the first live run."""

    class _ImportTarget:
        root = "/home/target/public_html"

        def wp_on_target(self) -> list[str]:
            return ["wp"]

    captured: dict[str, object] = {}

    def fake_stream(tgt: object, script: str, **kwargs: object) -> None:
        captured["script"] = script
        captured["stdin"] = kwargs.get("stdin")

    patcher = pytest.MonkeyPatch()
    patcher.setattr(chat, "backup_module", SimpleNamespace(stream=fake_stream))
    try:
        chat._import_sql(cast(Target, _ImportTarget()), "REPLACE INTO t VALUES (1);")
    finally:
        patcher.undo()

    stdin = captured["stdin"]
    assert stdin is not None and not isinstance(stdin, io.BytesIO)
    fileno = getattr(stdin, "fileno", None)
    assert callable(fileno), "stdin must expose a real file descriptor"
    assert "db import -" in str(captured["script"])


def test_write_options_requires_the_ok_acknowledgement(tmp_path: Path) -> None:
    target = _options_target()
    target.eval_file_output = "merged, trust me\n"
    _write_options_seed(
        tmp_path,
        {
            "md_wecom_notify": {"wecom_to_user": "John"},
            "md_kb_endpoint": {"transfer_words": ["price"]},
        },
    )

    with pytest.raises(DeployError, match="did not acknowledge"):
        chat._write_options(
            cast(Target, target),
            tmp_path,
            golden_host="staging.metal-divider.com",
            target_host="metal-divider.com",
            notes=[],
        )


def test_write_options_refuses_nested_secret_keys(tmp_path: Path) -> None:
    target = _options_target()
    _write_options_seed(
        tmp_path,
        {
            "md_wecom_notify": {"integrations": {"bearer_token": "hexhexhex"}},
            "md_kb_endpoint": {"transfer_words": ["price"]},
        },
    )

    with pytest.raises(DeployError, match="integrations.bearer_token"):
        chat._write_options(
            cast(Target, target),
            tmp_path,
            golden_host="staging.metal-divider.com",
            target_host="metal-divider.com",
            notes=[],
        )


def test_chat_push_sets_the_marker_only_after_the_verified_merge(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    target = _PushTarget(
        label="prod", base_url="https://metal-divider.com", root="/home/prod"
    )
    golden = _PushTarget(
        label="staging",
        base_url="https://staging.metal-divider.com",
        root="/home/golden",
    )
    monkeypatch.setattr(chat, "named_target", lambda _name: golden)
    monkeypatch.setattr(
        snapshot_module, "take", lambda tgt, home: SimpleNamespace(name="db")
    )
    monkeypatch.setattr(steps, "maintenance", lambda tgt, *, on: None)
    monkeypatch.setattr(backup_module, "stream", lambda *a, **k: None)
    _write_minimal_seed(tmp_path, with_credentials=True)

    chat.chat_push(cast(Target, target), repository=REPO_ROOT, home=tmp_path)

    eval_indexes = [i for i, a in enumerate(target.wp_calls) if a[0] == "eval-file"]
    marker_indexes = [
        i
        for i, a in enumerate(target.wp_calls)
        if a[:4] == ("option", "update", chat.BRINGUP_OPTION, "1")
    ]
    assert eval_indexes and marker_indexes
    assert marker_indexes[0] > eval_indexes[-1], (
        "the marker must be committed only after the option merge verified"
    )


def test_extract_seed_unpacks_the_flat_dump_tarball(tmp_path: Path) -> None:
    """The extractor must accept the flat members dump-chat-seed.sh tars.

    The dump script runs tar -C /tmp/md-seed <members>, so every member is
    a bare file name; a ../ member and a retired local-only member ride
    along to prove both guards still hold.
    """
    members = [
        "lhc-config-data.sql",
        "lhc-users-setting-sanitized.sql",
        "lhc-schema-local-only.sql",
        "lhc-users-sanitized.sql",
        "lhc-chat-config-sanitized.sql",
        "lhc-secret-tables.sql",
        "rapls-knowledge.sql",
        "chat-options.json",
    ]
    tarball = tmp_path / "md-seed.tar.gz"
    with tarfile.open(tarball, "w:gz") as archive:
        for name in members:
            data = f"-- {name}\n".encode()
            info = tarfile.TarInfo(name)
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
        for hostile in ("../evil.sql", chat.SEED_LOCAL_ONLY_NAMES[0]):
            info = tarfile.TarInfo(hostile)
            info.size = 1
            archive.addfile(info, io.BytesIO(b"x"))

    destination = tmp_path / "out"
    destination.mkdir()

    written = chat._extract_seed(tarball, destination)

    assert written == len(members)
    for name in members:
        assert (destination / name).read_text(encoding="utf-8") == f"-- {name}\n"
    assert not (destination / chat.SEED_LOCAL_ONLY_NAMES[0]).exists()
    assert len(list(destination.iterdir())) == len(members), "nothing else landed"


def test_extract_seed_raises_when_the_tarball_yields_nothing(tmp_path: Path) -> None:
    """A tarball whose members all fail the guards is an error, not a no-op."""
    tarball = tmp_path / "md-seed.tar.gz"
    with tarfile.open(tarball, "w:gz") as archive:
        info = tarfile.TarInfo("md-seed/lhc-config-data.sql")
        info.size = 1
        archive.addfile(info, io.BytesIO(b"x"))

    destination = tmp_path / "out"
    destination.mkdir()

    with pytest.raises(DeployError, match="unpacked to nothing"):
        chat._extract_seed(tarball, destination)
    assert not any(destination.iterdir())


def test_users_setting_ships_sanitized_never_plain() -> None:
    """The per-operator preference tables never ride the plain mysqldump."""
    assert chat.SEED_FILES["users_setting"] in chat.seed_import_files()

    dump = (REPO_ROOT / "tools/deploy/seed/dump-chat-seed.sh").read_text(
        encoding="utf-8"
    )
    data_block = dump.split("--no-create-info", 1)[1].split("lhc-config-data.sql", 1)[0]
    schema_block = dump.split("--no-data", 1)[1].split("lhc-schema-local-only.sql", 1)[
        0
    ]
    assert "lh_users_setting" not in data_block, (
        "the data dump must not carry the credential-bearing tables"
    )
    assert "lh_users_setting" in schema_block, (
        "the local-only schema must still cover them for validation"
    )
    assert "lhc-users-setting-sanitized.sql" in dump.split("SEED_MEMBERS=", 1)[1]

    exporter = (REPO_ROOT / "tools/deploy/seed/export-chat-seed.php").read_text(
        encoding="utf-8"
    )
    assert "lh_users_setting" in exporter
    assert "lhc-users-setting-sanitized.sql" in exporter


def test_verify_tree_accepts_any_hash_spacing(tmp_path: Path) -> None:
    """The readback parses on the 64-hex body, not a fragile two-space split."""
    source = tmp_path / "extension"
    source.mkdir()
    one = source / "one.css"
    one.write_text("body{}", encoding="utf-8")
    digest = backup_module.sha256(one)

    class _SpacingTarget:
        def tree(self, relative: str) -> list[str]:
            return [f"{digest} ./one.css", f"{digest}  ./one.css"]

    notes: list[str] = []
    chat._verify_tree(cast(Target, _SpacingTarget()), source, "chat/extension/x", notes)
    assert notes == []


def test_purge_caches_retries_once_and_records_the_note(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """A purge notify that fails once succeeds on the retry and logs it."""
    calls: list[tuple[str, ...]] = []

    class _FlakyTarget(_PushTarget):
        def wp(self, *args: str, check: bool = True) -> str:
            calls.append(args)
            if (
                args[:2] == ("litespeed-purge", "all")
                and len([c for c in calls if c[:2] == ("litespeed-purge", "all")]) == 1
            ):
                raise DeployError("purge notify failed")
            return ""

    flaky_shell_calls: list[str] = []

    class _RecordingShell(_FlakyTarget):
        def shell(self, script: str) -> str:
            flaky_shell_calls.append(script)
            return ""

    sleeps: list[float] = []
    monkeypatch.setattr(time, "sleep", sleeps.append)
    notes: list[str] = []
    target = _RecordingShell(label="x", base_url="https://x.test", root="/tmp/x")
    chat_module._purge_caches(cast(Target, target), notes)
    purges = [c for c in calls if c[:2] == ("litespeed-purge", "all")]
    assert len(purges) == 2
    assert sleeps == [5]
    assert notes == ["litespeed-purge failed once; retrying after a 5s pause"]
    assert any("mkdir -p" in s for s in flaky_shell_calls), flaky_shell_calls


def test_purge_caches_defers_a_second_failure_to_a_loud_note(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Two purge failures complete the push but fail it via notes."""

    class _DeadTarget(_PushTarget):
        def wp(self, *args: str, check: bool = True) -> str:
            if args[:2] == ("litespeed-purge", "all"):
                raise DeployError("purge notify failed")
            return ""

    monkeypatch.setattr(time, "sleep", lambda _s: None)
    notes: list[str] = []
    dead = _DeadTarget(label="x", base_url="https://x.test", root="/tmp/x")
    chat_module._purge_caches(cast(Target, dead), notes)  # no raise
    assert notes == [
        "litespeed-purge failed once; retrying after a 5s pause",
        "litespeed-purge failed twice; the page cache may serve"
        " stale HTML - run wp litespeed-purge all on the target"
        " before accepting traffic",
    ]
