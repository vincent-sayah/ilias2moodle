from pathlib import Path


def test_phase6_preflights_exported_question_pool_content():
    package_validator = Path(
        "moodle/local_iliasmigration/classes/phase6_package_validator.php"
    ).read_text(encoding="utf-8")
    scoring_validator = Path(
        "moodle/local_iliasmigration/classes/phase6_scoring_policy_validator.php"
    ).read_text(encoding="utf-8")

    assert "validate_question_pool" in package_validator
    assert "checked_content_question_pools" in package_validator
    assert "blocked_question_pools" in package_validator
    assert "PHASE6_QBANK_SOURCE_MISMATCH" in package_validator

    assert "checked_content_question_pool_preflights" in scoring_validator
    assert "moodle_xml_preflight_blocked_question_pools" in scoring_validator
    assert "PHASE6_QBANK_MOODLE_XML_PREFLIGHT_FAILED" in scoring_validator
    assert "question_pool_score_preserving_transform_count" in scoring_validator
