from __future__ import annotations

from ilias2moodle.ilias.qti import parse_test_qti


def test_matching_uses_ilias_match_group_to_restore_source_target_direction() -> None:
    qti = """<questestinterop><assessment><section>
    <item ident="q1" title="capital">
      <itemmetadata><qtimetadata>
        <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel>
        <fieldentry>assMatchingQuestion</fieldentry></qtimetadatafield>
      </qtimetadata></itemmetadata>
      <presentation><response_grp ident="MQ" rcardinality="Multiple">
        <response_label ident="96824" match_max="1" match_group="44101,32556,86009">
          <material><mattext>france</mattext></material>
        </response_label>
        <response_label ident="5450" match_max="1" match_group="44101,32556,86009">
          <material><mattext>italie</mattext></material>
        </response_label>
        <response_label ident="7773" match_max="1" match_group="44101,32556,86009">
          <material><mattext>espagne</mattext></material>
        </response_label>
        <response_label ident="44101"><material><mattext>paris</mattext></material></response_label>
        <response_label ident="32556"><material><mattext>rome</mattext></material></response_label>
        <response_label ident="86009"><material><mattext>madrid</mattext></material></response_label>
      </response_grp></presentation>
      <resprocessing>
        <respcondition><conditionvar><varsubset respident="MQ">44101,96824</varsubset></conditionvar>
          <setvar action="Add">4</setvar></respcondition>
        <respcondition><conditionvar><varsubset respident="MQ">32556,5450</varsubset></conditionvar>
          <setvar action="Add">2</setvar></respcondition>
        <respcondition><conditionvar><varsubset respident="MQ">86009,7773</varsubset></conditionvar>
          <setvar action="Add">5</setvar></respcondition>
      </resprocessing>
    </item>
    </section></assessment></questestinterop>"""

    questions, _ = parse_test_qti(qti)
    pairs = questions["questions"][0]["pairs"]

    assert [(pair["source_text"], pair["target_text"]) for pair in pairs] == [
        ("france", "paris"),
        ("italie", "rome"),
        ("espagne", "madrid"),
    ]
    assert questions["questions"][0]["max_score"] == 11


def test_ordering_prefers_declared_ilias_points_over_float_sum() -> None:
    qti = """<questestinterop><assessment><section>
    <item ident="q1" title="vertical">
      <itemmetadata><qtimetadata>
        <qtimetadatafield><fieldlabel>QUESTIONTYPE</fieldlabel>
        <fieldentry>assOrderingQuestion</fieldentry></qtimetadatafield>
        <qtimetadatafield><fieldlabel>points</fieldlabel><fieldentry>5</fieldentry></qtimetadatafield>
      </qtimetadata></itemmetadata>
      <presentation><response_lid ident="OQT" rcardinality="Ordered"><render_choice shuffle="Yes">
        <response_label ident="a"><material><mattext>1</mattext></material></response_label>
        <response_label ident="b"><material><mattext>2</mattext></material></response_label>
        <response_label ident="c"><material><mattext>3</mattext></material></response_label>
      </render_choice></response_lid></presentation>
      <resprocessing>
        <respcondition><conditionvar><varequal respident="OQT" index="0">0</varequal></conditionvar>
          <setvar action="Add">1.6666666666667</setvar></respcondition>
        <respcondition><conditionvar><varequal respident="OQT" index="1">1</varequal></conditionvar>
          <setvar action="Add">1.6666666666667</setvar></respcondition>
        <respcondition><conditionvar><varequal respident="OQT" index="2">2</varequal></conditionvar>
          <setvar action="Add">1.6666666666667</setvar></respcondition>
      </resprocessing>
    </item>
    </section></assessment></questestinterop>"""

    questions, quiz = parse_test_qti(qti)

    assert questions["questions"][0]["max_score"] == 5
    assert quiz["total_max_score"] == 5
