from pathlib import Path


def test_phase6_effective_qtypes_keeps_type_names():
    source = Path(
        "moodle/local_iliasmigration/classes/phase6_executor.php"
    ).read_text(encoding="utf-8")
    assert "'effective_qtypes' => array_count_values($effective)," in source
    assert "'effective_qtypes' => array_values(array_count_values($effective))," not in source
