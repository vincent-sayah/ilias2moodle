from pathlib import Path


def test_phase6_nonstandard_multichoice_fractions_use_cloze_multiresponse():
    builder = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")
    validator = Path(
        "moodle/local_iliasmigration/classes/phase6_scoring_policy_validator.php"
    ).read_text(encoding="utf-8")

    assert "MULTICHOICE_NONSTANDARD_FRACTIONS_TO_CLOZE" in builder
    assert "render_fractional_multichoice_cloze" in builder
    assert "{1:MULTIRESPONSE:" in builder
    assert "has_nonstandard_selected_fractions" in builder
    assert "can_preserve_multiple_choice_as_multiresponse" in builder

    assert "MULTICHOICE_NONSTANDARD_FRACTIONS_TRANSFORM" in validator
    assert "assert_core_multichoice_fractions_accept" in validator
    assert "match_grade_options" in validator
    assert "multiple_choice_nonstandard_fraction_review_count" in validator
