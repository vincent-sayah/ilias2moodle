from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.forum_package import extract_forum_assets
from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


def test_forum_package_extracts_structure_attachments_and_media(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_32/1800000000__0__frm_807"
    attachment_path = (
        f"{base}/components/ILIAS/Forum/set_0/"
        "expDir_1/handout.pdf"
    )
    media_path = (
        f"{base}/components/ILIAS/Forum/set_0/"
        "expDir_1/objects/il_0_mob_901/image.png"
    )

    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(
            attachment_path,
            b"forum-attachment",
        )
        archive.writestr(
            media_path,
            b"forum-image",
        )

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "807",
            "ref_id": "275",
            "export_base": base,
        },
        "title": "test migration forum",
        "description": "POC Forum",
        "threads": [
            {
                "source_id": "5",
                "subject": "Sujet 1",
                "posts": [
                    {
                        "source_id": "14",
                        "parent_source_id": "13",
                        "attachments": [
                            {
                                "filename": "handout.pdf",
                                "archive_path": attachment_path,
                            }
                        ],
                        "media_objects": [
                            {
                                "filename": "image.png",
                                "archive_path": media_path,
                            }
                        ],
                    }
                ],
            }
        ],
        "thread_count": 1,
        "post_count": 1,
        "attachment_count": 1,
        "media_object_count": 1,
        "source_author_ids": ["6"],
        "user_data_policy": {
            "authors_resolved_to_moodle": False,
            "target_phase": "7",
        },
    }

    item = MigrationItem(
        source_id="275",
        type="forum",
        title="test migration forum",
        metadata={
            "ilias_type": "frm",
            "obj_id": "807",
            "forum_export_base": base,
            "forum_structure": structure,
        },
    )

    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    output_dir = tmp_path / "package"
    result = extract_forum_assets(
        document,
        archive_path,
        output_dir,
    )

    assert result["managed_directory"] == "forums"
    assert result["extracted"]["forum_structures"] == 1
    assert result["extracted"]["forum_attachment_files"] == 1
    assert result["extracted"]["forum_media_files"] == 1
    assert result["missing"] == []

    attachment = (
        output_dir
        / "forums/275/attachments/14/handout.pdf"
    )
    media = (
        output_dir
        / "forums/275/media/14/image.png"
    )
    assert attachment.read_bytes() == b"forum-attachment"
    assert media.read_bytes() == b"forum-image"

    structure_path = (
        output_dir / "forums/275/structure.json"
    )
    saved = json.loads(
        structure_path.read_text(encoding="utf-8")
    )
    post = saved["threads"][0]["posts"][0]

    assert (
        post["attachments"][0]["migration_path"]
        == "forums/275/attachments/14/handout.pdf"
    )
    assert (
        post["media_objects"][0]["migration_path"]
        == "forums/275/media/14/image.png"
    )

    assert (
        item.metadata["migration_structure_path"]
        == "forums/275/structure.json"
    )
    assert (
        item.metadata[
            "migration_forum_attachment_file_count"
        ]
        == 1
    )
    assert (
        item.metadata[
            "migration_forum_media_file_count"
        ]
        == 1
    )
    assert "forum_structure" not in item.metadata
