from pathlib import Path


def test_phase6_kprim_is_supported_end_to_end():
    validator = Path(
        "moodle/local_iliasmigration/classes/phase6_package_validator.php"
    ).read_text(encoding="utf-8")
    scoring = Path(
        "moodle/local_iliasmigration/classes/phase6_scoring_policy_validator.php"
    ).read_text(encoding="utf-8")
    builder = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")

    assert "'kprim' => 'multichoice'" in validator
    assert "KPRIM_COMBINATIONS_TO_MULTICHOICE" in scoring
    assert "KPRIM_INTERACTION_CHANGE" in scoring
    assert "KPRIM_COMBINATIONS_TO_MULTICHOICE" in builder
    assert "render_kprim_combinations_multichoice" in builder
