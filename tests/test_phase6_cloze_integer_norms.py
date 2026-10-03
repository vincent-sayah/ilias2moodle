from pathlib import Path


def test_phase6_cloze_uses_integer_norms_and_core_preflight():
    builder = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")
    validator = Path(
        "moodle/local_iliasmigration/classes/phase6_scoring_policy_validator.php"
    ).read_text(encoding="utf-8")

    assert "cloze_integer_norms" in builder
    assert "integer_gcd" in builder
    assert "return '{' . $norm . ':MULTICHOICE:'" in builder
    assert ". $norm\n            . ':NUMERICAL:'" in builder

    assert "assert_core_multianswer_accepts" in validator
    assert "qtype_multianswer_extract_question" in validator
    assert "qtype_multianswer_validate_question" in validator
