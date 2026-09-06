from pathlib import Path


def test_phase6_recomputes_quiz_sumgrades_after_slot_creation():
    source = Path(
        "moodle/local_iliasmigration/classes/phase6_executor.php"
    ).read_text(encoding="utf-8")

    add_call = "quiz_add_quiz_question($qid, $quiz, 0, $maxmark)"
    recompute_call = "->recompute_quiz_sumgrades();"

    assert add_call in source
    assert recompute_call in source
    assert source.index(add_call) < source.index(recompute_call)
    assert "quiz_delete_previews($quiz);" in source
    assert "\\mod_quiz\\quiz_settings::create($instanceid)" in source
