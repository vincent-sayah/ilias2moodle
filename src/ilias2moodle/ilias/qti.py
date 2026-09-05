from __future__ import annotations

import json
from collections.abc import Iterable
from pathlib import Path
from typing import Any
from xml.etree import ElementTree as ET

QUESTION_TYPE_MAP = {
    "assSingleChoice": "single_choice",
    "assMultipleChoice": "multiple_choice",
    "assNumeric": "numeric",
    "assMatchingQuestion": "matching",
    "assTextQuestion": "essay",
    "assTextSubset": "short_answer",
    "assClozeTest": "cloze",
    "assOrderingQuestion": "ordering",
}


def _local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def _text(element: ET.Element | None) -> str:
    if element is None:
        return ""
    return " ".join(
        part.strip()
        for part in element.itertext()
        if part and part.strip()
    )


def _descendants(element: ET.Element, name: str) -> list[ET.Element]:
    return [
        candidate
        for candidate in element.iter()
        if _local_name(candidate.tag) == name
    ]


def _first(element: ET.Element, name: str) -> ET.Element | None:
    for candidate in element.iter():
        if _local_name(candidate.tag) == name:
            return candidate
    return None


def _float(value: str, default: float = 0.0) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def _metadata(element: ET.Element, *, stop_at_items: bool = False) -> dict[str, str]:
    result: dict[str, str] = {}

    def walk(current: ET.Element) -> Iterable[ET.Element]:
        for child in current:
            if stop_at_items and _local_name(child.tag) == "item":
                continue
            yield child
            yield from walk(child)

    for field in walk(element):
        if _local_name(field.tag) != "qtimetadatafield":
            continue
        label = _first(field, "fieldlabel")
        entry = _first(field, "fieldentry")
        key = _text(label)
        if key:
            result[key] = _text(entry)
    return result


def _first_question_text(item: ET.Element) -> str:
    presentation = _first(item, "presentation")
    if presentation is None:
        return ""
    for mattext in _descendants(presentation, "mattext"):
        value = _text(mattext)
        if value:
            return value
    return ""


def _mattexts(item: ET.Element) -> list[dict[str, str]]:
    result: list[dict[str, str]] = []
    for element in _descendants(item, "mattext"):
        result.append(
            {
                "text_type": element.attrib.get("texttype", ""),
                "text": _text(element),
            }
        )
    return result


def _score_rule(condition: ET.Element) -> dict[str, Any]:
    conditionvar = _first(condition, "conditionvar")
    setvars = [
        {
            "varname": element.attrib.get("varname", ""),
            "action": element.attrib.get("action", ""),
            "value": _float(_text(element)),
            "raw_value": _text(element),
        }
        for element in _descendants(condition, "setvar")
    ]
    feedback = [
        element.attrib.get("linkrefid", "")
        for element in _descendants(condition, "displayfeedback")
        if element.attrib.get("linkrefid")
    ]
    return {
        "continue": condition.attrib.get("continue", ""),
        "condition_xml": (
            ET.tostring(conditionvar, encoding="unicode")
            if conditionvar is not None
            else ""
        ),
        "setvars": setvars,
        "feedback_refs": feedback,
    }


def _score_rules(item: ET.Element) -> list[dict[str, Any]]:
    return [
        _score_rule(condition)
        for condition in _descendants(item, "respcondition")
    ]


def _first_setvar_score(condition: ET.Element) -> float:
    setvar = _first(condition, "setvar")
    return _float(_text(setvar)) if setvar is not None else 0.0


def _answer_labels(response: ET.Element) -> list[dict[str, Any]]:
    answers: list[dict[str, Any]] = []
    for index, label in enumerate(_descendants(response, "response_label")):
        mattexts = [
            _text(mattext)
            for mattext in _descendants(label, "mattext")
            if _text(mattext)
        ]
        answers.append(
            {
                "ident": label.attrib.get("ident", ""),
                "index": index,
                "text": mattexts[0] if mattexts else "",
                "texts": mattexts,
                "attributes": dict(label.attrib),
            }
        )
    return answers


def _choice_question(item: ET.Element, multiple: bool) -> dict[str, Any]:
    response = _first(item, "response_lid")
    if response is None:
        return {"answers": [], "shuffle": False, "max_score": 0.0}

    answers = _answer_labels(response)
    by_ident = {answer["ident"]: answer for answer in answers}

    for answer in answers:
        answer["score_if_selected"] = 0.0
        answer["score_if_not_selected"] = 0.0

    for condition in _descendants(item, "respcondition"):
        conditionvar = _first(condition, "conditionvar")
        if conditionvar is None:
            continue
        score = _first_setvar_score(condition)

        direct_equal = next(
            (
                child
                for child in conditionvar
                if _local_name(child.tag) == "varequal"
            ),
            None,
        )
        if direct_equal is not None:
            ident = _text(direct_equal)
            if ident in by_ident:
                by_ident[ident]["score_if_selected"] = score
            continue

        not_element = next(
            (
                child
                for child in conditionvar
                if _local_name(child.tag) == "not"
            ),
            None,
        )
        if not_element is None:
            continue
        equal = _first(not_element, "varequal")
        if equal is not None:
            ident = _text(equal)
            if ident in by_ident:
                by_ident[ident]["score_if_not_selected"] = score

    if multiple:
        max_score = sum(
            max(
                float(answer["score_if_selected"]),
                float(answer["score_if_not_selected"]),
            )
            for answer in answers
        )
    else:
        max_score = max(
            (float(answer["score_if_selected"]) for answer in answers),
            default=0.0,
        )

    render = _first(response, "render_choice")
    return {
        "answers": answers,
        "shuffle": (
            render is not None
            and render.attrib.get("shuffle", "").lower() == "yes"
        ),
        "response_ident": response.attrib.get("ident", ""),
        "max_score": max_score,
    }


def _numeric_question(item: ET.Element) -> dict[str, Any]:
    response = _first(item, "response_num")
    lower: float | None = None
    upper: float | None = None
    score = 0.0

    for condition in _descendants(item, "respcondition"):
        conditionvar = _first(condition, "conditionvar")
        if conditionvar is None:
            continue
        gte = _first(conditionvar, "vargte")
        lte = _first(conditionvar, "varlte")
        if gte is not None:
            lower = _float(_text(gte))
        if lte is not None:
            upper = _float(_text(lte))
        if gte is not None or lte is not None:
            score = _first_setvar_score(condition)
            break

    return {
        "response_ident": response.attrib.get("ident", "") if response is not None else "",
        "num_type": response.attrib.get("numtype", "") if response is not None else "",
        "lower_bound": lower,
        "upper_bound": upper,
        "max_score": score,
    }


def _matching_question(item: ET.Element) -> dict[str, Any]:
    response = _first(item, "response_grp")
    labels = _answer_labels(response) if response is not None else []
    labels_by_ident = {label["ident"]: label for label in labels}
    pairs: list[dict[str, Any]] = []

    for condition in _descendants(item, "respcondition"):
        conditionvar = _first(condition, "conditionvar")
        subset = _first(conditionvar, "varsubset") if conditionvar is not None else None
        if subset is None:
            continue
        raw_pair = _text(subset)
        parts = [part.strip() for part in raw_pair.split(",", 1)]
        source_ident = parts[0] if parts else ""
        target_ident = parts[1] if len(parts) > 1 else ""
        pairs.append(
            {
                "source_ident": source_ident,
                "target_ident": target_ident,
                "source_text": labels_by_ident.get(source_ident, {}).get("text", ""),
                "target_text": labels_by_ident.get(target_ident, {}).get("text", ""),
                "points": _first_setvar_score(condition),
            }
        )

    return {
        "response_ident": response.attrib.get("ident", "") if response is not None else "",
        "labels": labels,
        "pairs": pairs,
        "max_score": sum(float(pair["points"]) for pair in pairs),
    }


def _essay_question(item: ET.Element) -> dict[str, Any]:
    response = _first(item, "response_str")
    max_score = 0.0
    for decvar in _descendants(item, "decvar"):
        if decvar.attrib.get("varname") == "WritingScore":
            max_score = _float(decvar.attrib.get("maxvalue", "0"))
            break
    return {
        "response_ident": response.attrib.get("ident", "") if response is not None else "",
        "manual_grading": True,
        "max_score": max_score,
    }


def _accepted_answers(
    item: ET.Element,
    response_ident: str,
) -> list[dict[str, Any]]:
    answers: list[dict[str, Any]] = []
    for condition in _descendants(item, "respcondition"):
        conditionvar = _first(condition, "conditionvar")
        if conditionvar is None:
            continue
        candidate = _first(conditionvar, "varequal")
        if candidate is None:
            candidate = _first(conditionvar, "varsubset")
        if candidate is None:
            continue
        if candidate.attrib.get("respident", "") != response_ident:
            continue
        answers.append(
            {
                "text": _text(candidate),
                "comparison": _local_name(candidate.tag),
                "points": _first_setvar_score(condition),
            }
        )
    return answers


def _short_answer_question(item: ET.Element) -> dict[str, Any]:
    response = _first(item, "response_str")
    response_ident = response.attrib.get("ident", "") if response is not None else ""
    accepted = _accepted_answers(item, response_ident)
    metadata = _metadata(item)
    max_score = max(
        [float(answer["points"]) for answer in accepted]
        + [_float(metadata.get("points", "0"))]
    )
    return {
        "response_ident": response_ident,
        "accepted_answers": accepted,
        "case_sensitive": metadata.get("textrating", "").lower() == "cs",
        "max_score": max_score,
    }


def _cloze_question(item: ET.Element) -> dict[str, Any]:
    gaps: list[dict[str, Any]] = []
    for response in _descendants(item, "response_str"):
        response_ident = response.attrib.get("ident", "")
        if not response_ident.startswith("gap_"):
            continue
        accepted = _accepted_answers(item, response_ident)
        gaps.append(
            {
                "response_ident": response_ident,
                "accepted_answers": accepted,
                "max_score": max(
                    (float(answer["points"]) for answer in accepted),
                    default=0.0,
                ),
            }
        )

    return {
        "text_fragments": _mattexts(item),
        "gaps": gaps,
        "max_score": sum(float(gap["max_score"]) for gap in gaps),
    }


def _ordering_question(item: ET.Element) -> dict[str, Any]:
    response = _first(item, "response_lid")
    answers = _answer_labels(response) if response is not None else []
    correct_by_index: dict[int, int] = {}
    points_by_index: dict[int, float] = {}

    for condition in _descendants(item, "respcondition"):
        conditionvar = _first(condition, "conditionvar")
        equal = _first(conditionvar, "varequal") if conditionvar is not None else None
        if equal is None or "index" not in equal.attrib:
            continue
        index = int(equal.attrib.get("index", "0") or 0)
        expected = int(_float(_text(equal)))
        correct_by_index[index] = expected
        points_by_index[index] = _first_setvar_score(condition)

    correct_order: list[dict[str, Any]] = []
    for position in sorted(correct_by_index):
        expected_index = correct_by_index[position]
        answer = answers[expected_index] if expected_index < len(answers) else None
        correct_order.append(
            {
                "position": position,
                "answer_index": expected_index,
                "answer_ident": answer["ident"] if answer else "",
                "text": answer["text"] if answer else "",
                "points": points_by_index.get(position, 0.0),
            }
        )

    render = _first(response, "render_choice") if response is not None else None
    return {
        "answers": answers,
        "correct_order": correct_order,
        "shuffle": (
            render is not None
            and render.attrib.get("shuffle", "").lower() == "yes"
        ),
        "max_score": sum(float(entry["points"]) for entry in correct_order),
    }


def _normalize_question(item: ET.Element, position: int) -> dict[str, Any]:
    metadata = _metadata(item)
    ilias_type = metadata.get("QUESTIONTYPE", "")
    normalized_type = QUESTION_TYPE_MAP.get(ilias_type, "unsupported")

    specific: dict[str, Any]
    if normalized_type == "single_choice":
        specific = _choice_question(item, multiple=False)
    elif normalized_type == "multiple_choice":
        specific = _choice_question(item, multiple=True)
    elif normalized_type == "numeric":
        specific = _numeric_question(item)
    elif normalized_type == "matching":
        specific = _matching_question(item)
    elif normalized_type == "essay":
        specific = _essay_question(item)
    elif normalized_type == "short_answer":
        specific = _short_answer_question(item)
    elif normalized_type == "cloze":
        specific = _cloze_question(item)
    elif normalized_type == "ordering":
        specific = _ordering_question(item)
    else:
        specific = {"max_score": 0.0}

    return {
        "position_in_qti": position,
        "source_ident": item.attrib.get("ident", ""),
        "external_id": metadata.get("externalId", ""),
        "ilias_type": ilias_type,
        "type": normalized_type,
        "title": item.attrib.get("title", ""),
        "question_text": _first_question_text(item),
        "metadata": metadata,
        "mattexts": _mattexts(item),
        "scoring_rules": _score_rules(item),
        "max_score": float(specific.get("max_score", 0.0)),
        "supported_for_neutral_model": normalized_type != "unsupported",
        **specific,
    }


def parse_test_qti(
    qti: bytes | str,
    structure: bytes | str | None = None,
    *,
    source_ref_id: str = "",
    source_obj_id: str = "",
    title: str = "",
) -> tuple[dict[str, Any], dict[str, Any]]:
    qti_root = ET.fromstring(qti)
    item_elements = [
        element
        for element in qti_root.iter()
        if _local_name(element.tag) == "item"
    ]
    questions = [
        _normalize_question(item, position)
        for position, item in enumerate(item_elements, start=1)
    ]
    question_by_ident = {
        question["source_ident"]: question
        for question in questions
        if question["source_ident"]
    }

    assessment = next(
        (
            element
            for element in qti_root.iter()
            if _local_name(element.tag) == "assessment"
        ),
        None,
    )
    assessment_metadata = (
        _metadata(assessment, stop_at_items=True)
        if assessment is not None
        else {}
    )
    assessment_controls = [
        dict(element.attrib)
        for element in (
            _descendants(assessment, "assessmentcontrol")
            if assessment is not None
            else []
        )
    ]

    order: list[str] = []
    if structure is not None:
        structure_root = ET.fromstring(structure)
        order = [
            element.attrib.get("QRef", "")
            for element in structure_root.iter()
            if _local_name(element.tag) == "Question"
            and element.attrib.get("QRef")
        ]
    if not order:
        order = [question["source_ident"] for question in questions]

    ordered_questions: list[dict[str, Any]] = []
    unresolved_refs: list[str] = []
    for position, question_ref in enumerate(order, start=1):
        question = question_by_ident.get(question_ref)
        if question is None:
            unresolved_refs.append(question_ref)
            continue
        ordered_questions.append(
            {
                "position": position,
                "source_ident": question_ref,
                "external_id": question["external_id"],
                "title": question["title"],
                "type": question["type"],
                "max_score": question["max_score"],
            }
        )

    type_counts: dict[str, int] = {}
    ilias_type_counts: dict[str, int] = {}
    for question in questions:
        type_counts[question["type"]] = type_counts.get(question["type"], 0) + 1
        ilias_type_counts[question["ilias_type"]] = (
            ilias_type_counts.get(question["ilias_type"], 0) + 1
        )

    questions_document = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "test_ref_id": source_ref_id,
            "test_obj_id": source_obj_id,
        },
        "question_count": len(questions),
        "type_counts": type_counts,
        "ilias_type_counts": ilias_type_counts,
        "unsupported_count": sum(
            1
            for question in questions
            if not question["supported_for_neutral_model"]
        ),
        "questions": questions,
    }

    quiz_document = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "test_ref_id": source_ref_id,
            "test_obj_id": source_obj_id,
        },
        "title": title or (assessment.attrib.get("title", "") if assessment is not None else ""),
        "assessment_ident": (
            assessment.attrib.get("ident", "")
            if assessment is not None
            else ""
        ),
        "assessment_metadata": assessment_metadata,
        "assessment_controls": assessment_controls,
        "question_count": len(questions),
        "ordered_question_count": len(ordered_questions),
        "question_order": ordered_questions,
        "unresolved_question_refs": unresolved_refs,
        "total_max_score": sum(
            float(entry["max_score"])
            for entry in ordered_questions
        ),
    }

    return questions_document, quiz_document


def write_test_normalization(
    qti_path: Path,
    structure_path: Path | None,
    output_dir: Path,
    *,
    source_ref_id: str = "",
    source_obj_id: str = "",
    title: str = "",
) -> dict[str, Any]:
    qti = qti_path.read_bytes()
    structure = structure_path.read_bytes() if structure_path is not None else None
    questions, quiz = parse_test_qti(
        qti,
        structure,
        source_ref_id=source_ref_id,
        source_obj_id=source_obj_id,
        title=title,
    )

    output_dir.mkdir(parents=True, exist_ok=True)
    questions_path = output_dir / "questions.json"
    quiz_path = output_dir / "quiz.json"
    questions_path.write_text(
        json.dumps(questions, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    quiz_path.write_text(
        json.dumps(quiz, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    return {
        "questions_path": str(questions_path),
        "quiz_path": str(quiz_path),
        "question_count": questions["question_count"],
        "type_counts": questions["type_counts"],
        "unsupported_count": questions["unsupported_count"],
        "ordered_question_count": quiz["ordered_question_count"],
        "unresolved_question_refs": quiz["unresolved_question_refs"],
        "total_max_score": quiz["total_max_score"],
    }
