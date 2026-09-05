from pathlib import Path


def test_quiz_add_quiz_question_only_treats_false_as_failure():
    source = Path(
        "moodle/local_iliasmigration/classes/phase6_executor.php"
    ).read_text(encoding="utf-8")
    expected = (
        "if (quiz_add_quiz_question($qid, $quiz, 0, $maxmark) === false)"
    )
    assert expected in source
    assert "if (!quiz_add_quiz_question($qid, $quiz, 0, $maxmark))" not in source
