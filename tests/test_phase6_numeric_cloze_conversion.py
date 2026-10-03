from pathlib import Path


def test_phase6_numeric_cloze_is_rendered_as_exact_numerical():
    source = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")

    assert "($gap['input_type'] ?? 'text') === 'numeric'" in source
    assert "cloze_numerical($gap)" in source
    assert ":NUMERICAL:" in source
    assert "':0'" in source
    assert (
        "Numeric Cloze currently requires exact ILIAS varequal scoring."
        in source
    )
