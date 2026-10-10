from __future__ import annotations

from ilias2moodle.recovery_plan import build_recovery_plan


def test_build_recovery_plan_for_exercise_irss() -> None:
    plan = build_recovery_plan(
        [
            {
                "source_id": "356",
                "assignment_id": "5",
                "kind": (
                    "exercise_instruction_collection_unresolved"
                ),
                "source_path": (
                    "496f99f9-f8c3-48ab-9714-a6a54886877e"
                ),
            },
            {
                "source_id": "356",
                "assignment_id": "6",
                "kind": (
                    "exercise_instruction_collection_unresolved"
                ),
                "source_path": (
                    "5cf327b1-4b20-42f7-8ee0-7c96c449d210"
                ),
            },
        ]
    )

    assert plan["schema_version"] == "1.0"
    assert plan["recovery_required"] is True
    assert plan["request_count"] == 2
    assert plan["unresolved_count"] == 0

    first = plan["requests"][0]
    assert first["type"] == "exercise_irss_collection"
    assert first["source_ref_id"] == "356"
    assert first["assignment_id"] == "5"
    assert (
        first["collection_uuid"]
        == "496f99f9-f8c3-48ab-9714-a6a54886877e"
    )
    assert first["read_only"] is True
    assert first["execution_target"] == "ILIAS_SOURCE"
    assert first["extractor"] == "tools/ilias_irss_extract.php"
    assert first["extractor_args"] == [
        "--collection=496f99f9-f8c3-48ab-9714-a6a54886877e"
    ]
    assert (
        first["recovery_option"]
        == "--exercise-irss-recovery"
    )
    assert first["expected_manifest"].endswith(
        "/manifest.json"
    )


def test_build_recovery_plan_deduplicates_collection() -> None:
    uuid = "496f99f9-f8c3-48ab-9714-a6a54886877e"

    plan = build_recovery_plan(
        [
            {
                "source_id": "356",
                "assignment_id": "5",
                "kind": (
                    "exercise_instruction_collection_unresolved"
                ),
                "source_path": uuid,
            },
            {
                "source_id": "356",
                "assignment_id": "7",
                "kind": (
                    "exercise_instruction_collection_unresolved"
                ),
                "source_path": uuid,
            },
        ]
    )

    assert plan["request_count"] == 1


def test_build_recovery_plan_rejects_invalid_irss_uuid() -> None:
    plan = build_recovery_plan(
        [
            {
                "source_id": "356",
                "kind": (
                    "exercise_instruction_collection_unresolved"
                ),
                "source_path": "../unsafe",
            }
        ]
    )

    assert plan["recovery_required"] is False
    assert plan["request_count"] == 0
    assert plan["unresolved_count"] == 1
    assert (
        plan["unresolved"][0]["reason"]
        == "invalid_irss_collection_uuid"
    )


def test_build_recovery_plan_keeps_unknown_missing_explicit() -> None:
    plan = build_recovery_plan(
        [
            {
                "source_id": "999",
                "kind": "future_recovery_kind",
                "source_path": "value",
            }
        ]
    )

    assert plan["recovery_required"] is False
    assert plan["request_count"] == 0
    assert plan["unresolved_count"] == 1
    assert (
        plan["unresolved"][0]["reason"]
        == "no_automatic_recovery_contract"
    )
