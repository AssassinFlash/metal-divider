"""versions_of() and the wp-config constants, read from files shaped like an instance's."""

from __future__ import annotations

from pathlib import Path

from md_local import versions, wordpress
from md_local.instance import Instance


def plugins_of(tmp_path: Path) -> Path:
    instance = Instance("dev", 9890, tmp_path)
    plugins = instance.wp_content / "plugins"
    headers = {
        "woocommerce/woocommerce.php": "* Version: 11.2.1",
        "advanced-custom-fields/acf.php": "* Version: 6.8.10",
        "windpress/windpress.php": "* Version:             3.2.92",
        "wp-seopress/seopress.php": "* Version: 10.3",
        "wp-seopress-pro/seopress-pro.php": "* Version: 10.2.1",
        "livecanvas/livecanvas-plugin-index.php": "Version: 4.9.5",
        "28-wp-markdown-editor/yansir-md.php": "Version: 1.0.1",
    }
    for relative, line in headers.items():
        path = plugins / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(f"<?php\n/**\n * Plugin Name: X\n {line}\n */\n")
    theme = instance.wp_content / "themes" / "picostrap5" / "style.css"
    theme.parent.mkdir(parents=True, exist_ok=True)
    theme.write_text(f"/*\n Version: {versions.PICOSTRAP5_VERSION}\n*/\n")
    version = instance.docroot / "wp-includes" / "version.php"
    version.parent.mkdir(parents=True)
    version.write_text(f"$wp_version = '{versions.WORDPRESS_VERSION}';\n")
    return plugins


def test_versions_of_reads_every_pinned_plugin(tmp_path: Path) -> None:
    plugins_of(tmp_path)

    found = wordpress.versions_of(Instance("dev", 9890, tmp_path))
    assert found == wordpress.PINNED_VERSIONS


def test_versions_of_a_bare_instance_reports_missing(tmp_path: Path) -> None:
    found = wordpress.versions_of(Instance("dev", 9890, tmp_path))
    assert set(found.values()) == {None}


def test_wp_config_constants_define_the_instance(tmp_path: Path) -> None:
    constants = wordpress.wp_config_constants(Instance("dev", 9890, tmp_path))

    assert "define( 'WP_HOME', 'http://127.0.0.1:9890' );" in constants
    assert "define( 'WP_SITEURL', 'http://127.0.0.1:9890' );" in constants
    assert "define( 'WP_ENVIRONMENT_TYPE', 'local' );" in constants
    assert "define( 'DISABLE_WP_CRON', true );" in constants
    for name in wordpress.SALTS:
        assert f"define( '{name}', '" in constants
    # The isolation mu-plugin's paths, and the phase-2 allowlist's.
    assert "define( 'MD_LOCAL_MAIL_DIR', '" in constants
    assert "define( 'MD_LOCAL_ISOLATION_LOG', '" in constants
    assert "define( 'MD_LOCAL_ALLOW_HOSTS', '" in constants


def test_wp_config_constants_quote_php_specials(tmp_path: Path) -> None:
    instance = Instance("quote", 9890, tmp_path)
    constants = wordpress.wp_config_constants(instance)
    assert f"define( 'MD_LOCAL_MAIL_DIR', '{instance.mail_dir}' );" in constants
