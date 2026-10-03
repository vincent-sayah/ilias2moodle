from pathlib import Path


def test_phase6_image_matching_uses_cloze_and_embedded_files():
    builder = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")
    validator = Path(
        "moodle/local_iliasmigration/classes/phase6_scoring_policy_validator.php"
    ).read_text(encoding="utf-8")

    assert "IMAGE_MATCHING_TO_CLOZE" in builder
    assert "render_image_matching_cloze" in builder
    assert "@@PLUGINFILE@@" in builder
    assert 'encoding="base64"' in builder
    assert "ilias2moodle-image-matching-legend" in builder
    assert "matching_has_media" in builder

    assert "MATCHING_MEDIA_INTERACTION_CHANGE" in validator
    assert "IMAGE_MATCHING_TO_CLOZE" in validator
    assert "assert_native_matching_complete" in validator
    assert "matching_media_transform_count" in validator
