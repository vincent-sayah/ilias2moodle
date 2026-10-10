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


def test_build_recovery_plan_for_supported_source_assets() -> None:
    plan = build_recovery_plan(
        [
            {
                "source_id": "338",
                "entry_id": "1",
                "mob_id": "976",
                "location": "clip.mp4",
                "kind": "mediacast_local_media",
                "source_path": "clip.mp4",
            },
            {
                "source_id": "332",
                "mob_id": "980",
                "location": "blog.png",
                "kind": "blog_media",
                "source_path": "missing/blog.png",
            },
            {
                "source_id": "341",
                "mob_id": "985",
                "location": "pool.mp4",
                "kind": "media_pool_media",
                "source_path": "missing/pool.mp4",
            },
            {
                "source_id": "275",
                "forum_obj_id": "807",
                "post_id": "18",
                "filename": "handout.pdf",
                "kind": "forum_attachment",
                "source_path": "missing/handout.pdf",
            },
            {
                "source_id": "339",
                "wiki_obj_id": "1000",
                "course_ref_id": "282",
                "kind": "wiki_structure",
                "source_path": "",
            },
        ]
    )

    assert plan["request_count"] == 5
    assert plan["unresolved_count"] == 0

    mediacast = plan["requests"][0]
    assert mediacast["type"] == "mediaobject_file"
    assert mediacast["mob_id"] == "976"
    assert mediacast["location"] == "clip.mp4"
    assert (
        mediacast["recovery_option"]
        == "--mediacast-media-recovery"
    )

    blog = plan["requests"][1]
    assert blog["type"] == "mediaobject_file"
    assert blog["mob_id"] == "980"
    assert blog["recovery_option"] == "--mediaobject-recovery"

    pool = plan["requests"][2]
    assert pool["type"] == "mediaobject_file"
    assert pool["mob_id"] == "985"
    assert pool["recovery_option"] == "--mediaobject-recovery"

    forum = plan["requests"][3]
    assert forum["type"] == "forum_attachment"
    assert forum["forum_obj_id"] == "807"
    assert forum["post_id"] == "18"
    assert forum["filename"] == "handout.pdf"
    assert (
        forum["expected_manifest"]
        == "forum_807/post_18/manifest.json"
    )

    wiki = plan["requests"][4]
    assert wiki["type"] == "wiki_content"
    assert wiki["wiki_ref_id"] == "339"
    assert wiki["wiki_obj_id"] == "1000"
    assert wiki["course_ref_id"] == "282"
    assert wiki["expected_manifest"] == "wiki_1000/manifest.json"


def test_build_recovery_plan_rejects_unsafe_asset_identity() -> None:
    plan = build_recovery_plan(
        [
            {
                "source_id": "332",
                "mob_id": "980",
                "location": "../unsafe.png",
                "kind": "blog_media",
                "source_path": "../unsafe.png",
            },
            {
                "source_id": "275",
                "forum_obj_id": "807",
                "post_id": "18",
                "filename": "../handout.pdf",
                "kind": "forum_attachment",
                "source_path": "../handout.pdf",
            },
        ]
    )

    assert plan["request_count"] == 0
    assert plan["unresolved_count"] == 2
    assert {
        value["reason"]
        for value in plan["unresolved"]
    } == {
        "invalid_mediaobject_identity",
        "invalid_forum_attachment_identity",
    }
