from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.model import MigrationItem
from ilias2moodle.package_builder import MigrationPackageBuilder

QTI = """<questestinterop><assessment ident="test_713" title="test"><section>
<item ident="q1" title="maths1">
  <itemmetadata><qtimetadata>
    <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel>
      <fieldentry>assSingleChoice</fieldentry></qtimetadatafield>
    <qtimetadatafield><fieldlabel>externalId</fieldlabel>
      <fieldentry>il_0_qst_19</fieldentry></qtimetadatafield>
  </qtimetadata></itemmetadata>
  <presentation>
    <material><mattext texttype="text/xhtml">&lt;p&gt;1+1&lt;/p&gt;</mattext></material>
    <response_lid ident="MCSR" rcardinality="Single"><render_choice shuffle="Yes">
      <response_label ident="0"><material><mattext>1</mattext></material></response_label>
      <response_label ident="1"><material><mattext>2</mattext></material></response_label>
    </render_choice></response_lid>
  </presentation>
  <resprocessing>
    <respcondition><conditionvar><varequal respident="MCSR">0</varequal></conditionvar>
      <setvar action="Add">0</setvar></respcondition>
    <respcondition><conditionvar><varequal respident="MCSR">1</varequal></conditionvar>
      <setvar action="Add">1</setvar></respcondition>
  </resprocessing>
</item>
</section></assessment></questestinterop>"""

STRUCTURE = """<ContentObject Type="Test">
<PageObject><PageContent><Question QRef="q1"/></PageContent></PageObject>
</ContentObject>"""


def test_extract_test_automatically_writes_phase6_json(tmp_path: Path) -> None:
    archive_path = tmp_path / "source.zip"
    output_dir = tmp_path / "out"
    qti_path = "set_7/test/1788628522__0__qti_713.xml"
    structure_path = "set_7/test/1788628522__0__tst_713.xml"

    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(qti_path, QTI)
        archive.writestr(structure_path, STRUCTURE)

    item = MigrationItem(
        source_id="236",
        type="test",
        title="test",
        metadata={
            "obj_id": "713",
            "qti_path": qti_path,
            "test_structure_path": structure_path,
        },
    )
    builder = MigrationPackageBuilder(archive_path, output_dir)

    with zipfile.ZipFile(archive_path) as archive:
        builder._members = {name.lstrip("/"): name for name in archive.namelist()}
        builder._extract_test(archive, item)

    test_dir = output_dir / "tests" / "236"
    assert (test_dir / "questions.xml").is_file()
    assert (test_dir / "test-structure.xml").is_file()
    assert (test_dir / "questions.json").is_file()
    assert (test_dir / "quiz.json").is_file()

    questions = json.loads((test_dir / "questions.json").read_text(encoding="utf-8"))
    quiz = json.loads((test_dir / "quiz.json").read_text(encoding="utf-8"))

    assert questions["question_count"] == 1
    assert questions["type_counts"] == {"single_choice": 1}
    assert questions["unsupported_count"] == 0
    assert quiz["ordered_question_count"] == 1
    assert quiz["unresolved_question_refs"] == []
    assert quiz["total_max_score"] == 1

    assert item.metadata["migration_questions_path"] == "tests/236/questions.json"
    assert item.metadata["migration_quiz_path"] == "tests/236/quiz.json"
    assert item.metadata["normalized_question_count"] == 1
    assert item.metadata["normalized_unsupported_count"] == 0
    assert item.metadata["normalized_total_max_score"] == 1

    assert builder.extracted["test_files"] == 2
    assert builder.extracted["test_normalizations"] == 1
    assert builder.extracted["normalized_questions"] == 1
