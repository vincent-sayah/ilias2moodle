from __future__ import annotations

from ilias2moodle.ilias.qti import parse_test_qti

QTI = """<?xml version="1.0"?>
<questestinterop>
  <assessment ident="test_713" title="test">
    <section>
      <item ident="q_matching" title="capital">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assMatchingQuestion</fieldentry></qtimetadatafield>
          <qtimetadatafield><fieldlabel>externalId</fieldlabel><fieldentry>ext_matching</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation>
          <material><mattext texttype="text/xhtml">&lt;p&gt;trouver les capital&lt;/p&gt;</mattext></material>
          <response_grp ident="MQ" rcardinality="Multiple">
            <response_label ident="s1"><material><mattext>france</mattext></material></response_label>
            <response_label ident="s2"><material><mattext>italie</mattext></material></response_label>
            <response_label ident="t1"><material><mattext>paris</mattext></material></response_label>
            <response_label ident="t2"><material><mattext>rome</mattext></material></response_label>
          </response_grp>
        </presentation>
        <resprocessing>
          <respcondition><conditionvar><varsubset respident="MQ">s1,t1</varsubset></conditionvar><setvar action="Add">4</setvar></respcondition>
          <respcondition><conditionvar><varsubset respident="MQ">s2,t2</varsubset></conditionvar><setvar action="Add">2</setvar></respcondition>
        </resprocessing>
      </item>

      <item ident="q_single" title="maths1">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assSingleChoice</fieldentry></qtimetadatafield>
          <qtimetadatafield><fieldlabel>externalId</fieldlabel><fieldentry>ext_single</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation>
          <material><mattext texttype="text/xhtml">&lt;p&gt;1+1&lt;/p&gt;</mattext></material>
          <response_lid ident="MCSR" rcardinality="Single"><render_choice shuffle="Yes">
            <response_label ident="0"><material><mattext>1</mattext></material></response_label>
            <response_label ident="1"><material><mattext>2</mattext></material></response_label>
          </render_choice></response_lid>
        </presentation>
        <resprocessing>
          <respcondition><conditionvar><varequal respident="MCSR">0</varequal></conditionvar><setvar action="Add">0</setvar></respcondition>
          <respcondition><conditionvar><varequal respident="MCSR">1</varequal></conditionvar><setvar action="Add">1</setvar></respcondition>
        </resprocessing>
      </item>

      <item ident="q_multi" title="pairs">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assMultipleChoice</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation>
          <material><mattext>&lt;p&gt;pairs&lt;/p&gt;</mattext></material>
          <response_lid ident="MCMR" rcardinality="Multiple"><render_choice shuffle="Yes">
            <response_label ident="0"><material><mattext>1</mattext></material></response_label>
            <response_label ident="1"><material><mattext>2</mattext></material></response_label>
          </render_choice></response_lid>
        </presentation>
        <resprocessing>
          <respcondition><conditionvar><varequal respident="MCMR">0</varequal></conditionvar><setvar action="Add">0</setvar></respcondition>
          <respcondition><conditionvar><not><varequal respident="MCMR">0</varequal></not></conditionvar><setvar action="Add">1</setvar></respcondition>
          <respcondition><conditionvar><varequal respident="MCMR">1</varequal></conditionvar><setvar action="Add">1</setvar></respcondition>
          <respcondition><conditionvar><not><varequal respident="MCMR">1</varequal></not></conditionvar><setvar action="Add">0</setvar></respcondition>
        </resprocessing>
      </item>

      <item ident="q_numeric" title="maths11">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assNumeric</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation>
          <material><mattext>&lt;p&gt;10/2=&lt;/p&gt;</mattext></material>
          <response_num ident="NUM" rcardinality="Single" numtype="Decimal"><render_fib fibtype="Decimal"/></response_num>
        </presentation>
        <resprocessing>
          <respcondition><conditionvar><vargte respident="NUM">4</vargte><varlte respident="NUM">6</varlte></conditionvar><setvar action="Add">3</setvar></respcondition>
        </resprocessing>
      </item>

      <item ident="q_essay" title="redac">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assTextQuestion</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation><material><mattext>&lt;p&gt;donne un proverbe&lt;/p&gt;</mattext></material><response_str ident="TEXT" rcardinality="Ordered"><render_fib fibtype="String"/></response_str></presentation>
        <resprocessing><outcomes><decvar varname="WritingScore" vartype="Integer" minvalue="0" maxvalue="5"/></outcomes></resprocessing>
      </item>

      <item ident="q_short" title="reponse courte">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assTextSubset</fieldentry></qtimetadatafield>
          <qtimetadatafield><fieldlabel>points</fieldlabel><fieldentry>3</fieldentry></qtimetadatafield>
          <qtimetadatafield><fieldlabel>textrating</fieldlabel><fieldentry>ci</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation><material><mattext>&lt;p&gt;capital de france&lt;/p&gt;</mattext></material><response_str ident="TEXTSUBSET_1" rcardinality="Single"><render_fib fibtype="String"/></response_str></presentation>
        <resprocessing><respcondition><conditionvar><varsubset respident="TEXTSUBSET_1">paris</varsubset></conditionvar><setvar varname="matches" action="Add">3</setvar></respcondition></resprocessing>
      </item>

      <item ident="q_cloze" title="texte a trou">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assClozeTest</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation>
          <material><mattext>&lt;p&gt;completer&lt;/p&gt;</mattext></material>
          <material><mattext>&lt;p&gt;la capitale est </mattext></material>
          <response_str ident="gap_0" rcardinality="Single"><render_fib fibtype="String"/></response_str>
          <material><mattext>&lt;/p&gt;</mattext></material>
        </presentation>
        <resprocessing><respcondition><conditionvar><varequal respident="gap_0">paris</varequal></conditionvar><setvar action="Add">5</setvar></respcondition></resprocessing>
      </item>

      <item ident="q_order" title="vertical">
        <itemmetadata><qtimetadata>
          <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel><fieldentry>assOrderingQuestion</fieldentry></qtimetadatafield>
          <qtimetadatafield><fieldlabel>points</fieldlabel><fieldentry>5</fieldentry></qtimetadatafield>
        </qtimetadata></itemmetadata>
        <presentation>
          <material><mattext>&lt;p&gt;classer&lt;/p&gt;</mattext></material>
          <response_lid ident="OQT" rcardinality="Ordered"><render_choice shuffle="Yes">
            <response_label ident="a"><material><mattext>1</mattext></material></response_label>
            <response_label ident="b"><material><mattext>2</mattext></material></response_label>
            <response_label ident="c"><material><mattext>3</mattext></material></response_label>
          </render_choice></response_lid>
        </presentation>
        <resprocessing>
          <respcondition><conditionvar><varequal respident="OQT" index="0">0</varequal></conditionvar><setvar action="Add">1.6666666666667</setvar></respcondition>
          <respcondition><conditionvar><varequal respident="OQT" index="1">1</varequal></conditionvar><setvar action="Add">1.6666666666667</setvar></respcondition>
          <respcondition><conditionvar><varequal respident="OQT" index="2">2</varequal></conditionvar><setvar action="Add">1.6666666666667</setvar></respcondition>
        </resprocessing>
      </item>
    </section>
  </assessment>
</questestinterop>
"""

STRUCTURE = """<ContentObject Type="Test">
  <PageObject><PageContent><Question QRef="q_numeric"/></PageContent></PageObject>
  <PageObject><PageContent><Question QRef="q_single"/></PageContent></PageObject>
  <PageObject><PageContent><Question QRef="q_matching"/></PageContent></PageObject>
  <PageObject><PageContent><Question QRef="q_multi"/></PageContent></PageObject>
  <PageObject><PageContent><Question QRef="q_essay"/></PageContent></PageObject>
  <PageObject><PageContent><Question QRef="q_short"/></PageContent></PageObject>
  <PageObject><PageContent><Question QRef="q_cloze"/></PageContent></PageObject>
  <PageObject><PageContent><Question QRef="q_order"/></PageContent></PageObject>
</ContentObject>"""


def test_parse_phase6_representative_question_types() -> None:
    questions, quiz = parse_test_qti(
        QTI,
        STRUCTURE,
        source_ref_id="236",
        source_obj_id="713",
        title="test",
    )

    assert questions["question_count"] == 8
    assert questions["unsupported_count"] == 0
    assert questions["type_counts"] == {
        "matching": 1,
        "single_choice": 1,
        "multiple_choice": 1,
        "numeric": 1,
        "essay": 1,
        "short_answer": 1,
        "cloze": 1,
        "ordering": 1,
    }

    by_ident = {
        question["source_ident"]: question
        for question in questions["questions"]
    }

    assert by_ident["q_matching"]["max_score"] == 6
    assert by_ident["q_matching"]["pairs"][0]["source_text"] == "france"
    assert by_ident["q_matching"]["pairs"][0]["target_text"] == "paris"

    assert by_ident["q_single"]["answers"][1]["score_if_selected"] == 1
    assert by_ident["q_single"]["max_score"] == 1

    assert by_ident["q_multi"]["answers"][0]["score_if_not_selected"] == 1
    assert by_ident["q_multi"]["answers"][1]["score_if_selected"] == 1
    assert by_ident["q_multi"]["max_score"] == 2

    assert by_ident["q_numeric"]["lower_bound"] == 4
    assert by_ident["q_numeric"]["upper_bound"] == 6
    assert by_ident["q_numeric"]["max_score"] == 3

    assert by_ident["q_essay"]["manual_grading"] is True
    assert by_ident["q_essay"]["max_score"] == 5

    assert by_ident["q_short"]["accepted_answers"][0]["text"] == "paris"
    assert by_ident["q_short"]["case_sensitive"] is False
    assert by_ident["q_short"]["max_score"] == 3

    assert by_ident["q_cloze"]["gaps"][0]["accepted_answers"][0]["text"] == "paris"
    assert by_ident["q_cloze"]["max_score"] == 5

    assert [entry["text"] for entry in by_ident["q_order"]["correct_order"]] == [
        "1",
        "2",
        "3",
    ]
    assert round(by_ident["q_order"]["max_score"], 6) == 5

    assert quiz["ordered_question_count"] == 8
    assert quiz["question_order"][0]["source_ident"] == "q_numeric"
    assert quiz["question_order"][1]["source_ident"] == "q_single"
    assert quiz["unresolved_question_refs"] == []
    assert quiz["title"] == "test"
