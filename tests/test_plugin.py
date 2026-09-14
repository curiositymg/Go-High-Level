"""Checks for the GoHighLevel Integration WordPress plugin.

Two kinds of check live here:

1. Structural invariants that are easy to break by hand (direct-access guards,
   version numbers that must agree, includes that must exist, output that must
   be escaped).
2. The plugin's own logic suite, `tests/run-tests.php`, which runs the real
   classes against stubbed WordPress functions. It needs the `php` CLI; the
   tests that need it are skipped when it isn't installed.
"""

import re
import shutil
import subprocess
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parent.parent
PLUGIN_DIR = REPO_ROOT / "gohighlevel-integration"
MAIN_FILE = PLUGIN_DIR / "gohighlevel-integration.php"

PHP = shutil.which("php")
needs_php = pytest.mark.skipif(PHP is None, reason="php CLI not installed")


def php_files():
    return sorted(REPO_ROOT.rglob("*.php"))


@needs_php
@pytest.mark.parametrize("path", php_files(), ids=lambda p: str(p.relative_to(REPO_ROOT)))
def test_php_file_parses(path):
    result = subprocess.run(
        [PHP, "-l", str(path)], capture_output=True, text=True, check=False
    )
    assert result.returncode == 0, result.stdout + result.stderr


@needs_php
def test_plugin_logic_suite_passes():
    result = subprocess.run(
        [PHP, str(REPO_ROOT / "tests" / "run-tests.php")],
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    assert "0 failures" in result.stdout


@pytest.mark.parametrize(
    "path", sorted(PLUGIN_DIR.rglob("*.php")), ids=lambda p: str(p.relative_to(PLUGIN_DIR))
)
def test_php_file_blocks_direct_access(path):
    """Every plugin file must abort when loaded outside WordPress."""
    source = path.read_text()
    guards = ("defined( 'ABSPATH' ) || exit;", "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;")
    assert any(guard in source for guard in guards), f"{path.name} has no direct-access guard"


def test_versions_agree():
    """The plugin header, the constant and readme.txt must carry one version."""
    main = MAIN_FILE.read_text()

    header = re.search(r"^ \* Version:\s+(\S+)$", main, re.MULTILINE)
    constant = re.search(r"define\( 'GHLD_VERSION', '([^']+)' \);", main)
    stable = re.search(
        r"^Stable tag:\s+(\S+)$", (PLUGIN_DIR / "readme.txt").read_text(), re.MULTILINE
    )

    assert header and constant and stable
    assert header.group(1) == constant.group(1) == stable.group(1)


def test_text_domain_matches_plugin_slug():
    """WordPress only loads translations when the domain matches the folder."""
    main = MAIN_FILE.read_text()
    domain = re.search(r"^ \* Text Domain:\s+(\S+)$", main, re.MULTILINE)

    assert domain and domain.group(1) == PLUGIN_DIR.name

    for path in sorted(PLUGIN_DIR.rglob("*.php")):
        for number, line in enumerate(path.read_text().splitlines(), start=1):
            for used in re.findall(r"'ghl-[a-z-]+'\s*\)", line):
                pytest.fail(f"{path.name}:{number} uses a stale text domain: {used}")


def test_every_include_exists():
    main = MAIN_FILE.read_text()
    includes = re.findall(r"require_once GHLD_PATH \. '([^']+)';", main)

    assert includes, "the bootstrap file loads no classes"
    for relative in includes:
        assert (PLUGIN_DIR / relative).is_file(), f"missing include: {relative}"


def test_class_files_define_their_class():
    """includes/class-ghld-foo.php must define GHLD_Foo."""
    for path in sorted((PLUGIN_DIR / "includes").glob("class-*.php")):
        expected = "GHLD_" + "_".join(
            part.capitalize() for part in path.stem.replace("class-ghld-", "").split("-")
        )
        assert f"class {expected} " in path.read_text(), f"{path.name} should define {expected}"


@pytest.mark.parametrize(
    "path",
    sorted((PLUGIN_DIR / "templates").glob("*.php")),
    ids=lambda p: p.name,
)
def test_templates_escape_their_output(path):
    """Raw `echo $var` in a template must carry an explicit escaping exemption."""
    for number, line in enumerate(path.read_text().splitlines(), start=1):
        stripped = line.strip()
        if not re.match(r"^(echo|print)\s+\$", stripped):
            continue
        assert "phpcs:ignore" in stripped, f"{path.name}:{number} echoes unescaped output"


def test_form_controls_do_not_force_full_width():
    """The filter bar must not stretch fields or buttons to the column width."""
    css = (PLUGIN_DIR / "assets" / "css" / "gohighlevel-integration.css").read_text()

    block = re.search(r"\.ghld-input,\s*\.ghld-select \{(.*?)\}", css, re.DOTALL)
    assert block, "the input/select rule is missing"
    # (?<![a-z-]) so this does not match max-width / min-width.
    assert not re.search(
        r"(?<![a-z-])width:\s*100%", block.group(1)
    ), "form fields must not be forced to full width"

    button = re.search(r"^\.ghld-button \{(.*?)\}", css, re.DOTALL | re.MULTILINE)
    assert button and "width: auto" in button.group(1), "buttons should size to their content"


def test_no_short_open_tags():
    for path in php_files():
        assert "<?=" not in path.read_text(), f"{path.name} uses a short open tag"


def test_rest_endpoint_takes_no_tag_arguments():
    """A visitor must not be able to widen a directory's scope over REST."""
    source = (PLUGIN_DIR / "includes" / "class-ghld-rest.php").read_text()

    assert "GHLD_Shortcode::get_instance" in source
    assert "'tags'" not in source, "the REST route should never accept its own tag scope"
