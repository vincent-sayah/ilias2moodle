from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
RECONCILER = ROOT / "moodle/local_iliasmigration/classes/order_reconciler.php"
CLI = ROOT / "moodle/local_iliasmigration/cli/reconcile_order.php"


def test_order_reconciler_uses_moodle_course_apis_and_preserves_qbank():
    source = RECONCILER.read_text(encoding="utf-8")

    assert "moveto_module(" in source
    assert "course_create_section(" in source
    assert "formatactions::section" in source
    assert "question_pool" in source
    assert "must remain in section 0" in source
    assert "synthetic_section" in source
    assert "__root_segment_" in source

    # The reconciler must not bypass Moodle course APIs by writing core order
    # fields directly.
    assert "set_field('course_sections', 'sequence'" not in source
    assert 'set_field("course_sections", "sequence"' not in source
    assert "set_field('course_modules', 'section'" not in source
    assert 'set_field("course_modules", "section"' not in source


def test_order_reconciler_cli_requires_explicit_dry_run_or_apply():
    source = CLI.read_text(encoding="utf-8")

    assert "--dry-run" in source
    assert "--apply" in source
    assert "Choose exactly one of --dry-run or --apply" in source
    assert "order_reconciler" in source
