from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.glossary_package import extract_glossary_assets
from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


def test_glossary_package_extracts_structure_and_media(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    media_path = (
        "set_29/1789580035__0__glo_797/components/ILIAS/MediaObjects/"
        "set_0/expDir_1/dsDir_1/chat.jpg"
    )
    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(media_path, b"chat")

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "797",
            "ref_id": "270",
            "export_base": "set_29/1789580035__0__glo_797",
        },
        "title": "glossaire test migration",
        "description": "",
        "settings": {"show_taxonomy": "0"},
        "taxonomy": {"enabled": False, "export_component_present": False},
        "terms": [
            {
                "source_id": "6",
                "term": "Chat",
                "language": "fr",
                "definition": {
                    "status": "ok",
                    "blocks": [
                        {
                            "type": "media",
                            "source_id": "798",
                        }
                    ],
                    "unsupported_components": [],
                },
            }
        ],
        "media": {
            "798": {
                "source_id": "798",
                "title": "chat.jpg",
                "items": [
                    {
                        "location": "chat.jpg",
                        "mime_type": "image/jpeg",
                        "archive_path": media_path,
                    }
                ],
            }
        },
        "files": {},
        "unsupported_components": [],
    }

    item = MigrationItem(
        source_id="270",
        type="glossary",
        title="glossaire test migration",
        metadata={
            "ilias_type": "glo",
            "obj_id": "797",
            "glossary_export_base": structure["source"]["export_base"],
            "glossary_structure": structure,
        },
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="cours test migration", items=[item])
    )

    output_dir = tmp_path / "package"
    result = extract_glossary_assets(document, archive_path, output_dir)

    assert result["managed_directory"] == "glossaries"
    assert result["extracted"]["glossary_structures"] == 1
    assert result["extracted"]["glossary_media_files"] == 1
    assert result["extracted"]["glossary_files"] == 0
    assert result["missing"] == []

    expected_media = output_dir / "glossaries/270/media/798/chat.jpg"
    assert expected_media.read_bytes() == b"chat"

    structure_path = output_dir / "glossaries/270/structure.json"
    saved = json.loads(structure_path.read_text(encoding="utf-8"))
    saved_item = saved["media"]["798"]["items"][0]
    assert saved_item["migration_path"] == "glossaries/270/media/798/chat.jpg"

    assert item.metadata["migration_structure_path"] == "glossaries/270/structure.json"
    assert item.metadata["migration_media_file_count"] == 1
    assert item.metadata["migration_embedded_file_count"] == 0
    assert "glossary_structure" not in item.metadata
