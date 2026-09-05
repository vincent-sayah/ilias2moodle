from pathlib import Path


def test_ordering_xml_sets_shownumcorrect_as_executable_statement():
    source = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")
    start = source.index("private function render_ordering")
    end = source.index("/** Native equal-weight Matching", start)
    method = source[start:end]
    lines = [line.strip() for line in method.splitlines()]
    statement = '$xml .= "    <shownumcorrect>1</shownumcorrect>\\n";'
    assert statement in lines
    assert all(not line.startswith("//") or statement not in line for line in lines)
    assert method.index("<shownumcorrect>1</shownumcorrect>") < method.index("<correctfeedback")
