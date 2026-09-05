from pathlib import Path


def test_ordering_xml_sets_shownumcorrect_before_feedback():
    source = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")
    start = source.index("private function render_ordering")
    end = source.index("/** Native equal-weight Matching", start)
    method = source[start:end]
    marker = "<shownumcorrect>1</shownumcorrect>"
    assert marker in method
    assert method.index(marker) < method.index("<correctfeedback")
