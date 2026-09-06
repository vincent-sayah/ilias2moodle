from pathlib import Path


def test_phase6_identical_quiz_replay_skips_metadata_write_and_preserves_password():
    source = Path(
        "moodle/local_iliasmigration/classes/phase6_executor.php"
    ).read_text(encoding="utf-8")
    assert "$metadatachanged = (string) $quiz->name" in source
    assert "if ($metadatachanged) {" in source
    assert "$moduleinfo->quizpassword = (string) $quiz->password;" in source
    metadata_pos = source.index("$metadatachanged = (string) $quiz->name")
    update_pos = source.index("update_module($moduleinfo);", metadata_pos)
    guard_pos = source.index("if ($metadatachanged) {", metadata_pos)
    assert metadata_pos < guard_pos < update_pos
