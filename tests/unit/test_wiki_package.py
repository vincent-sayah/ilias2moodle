from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem
from ilias2moodle.wiki_package import extract_wiki_assets


def test_wiki_package_extracts_structure_and_media(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    media_path = (
        "set_31/1800000000__0__wiki_900/components/ILIAS/MediaObjects/"
        "set_0/expDir_1/dsDir_1/wiki-image.png"
    )
    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(media_path, b"wiki-image")

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "900",
            "ref_id": "273",
            "export_base": "set_31/1800000000__0__wiki_900",
        },
        "title": "wiki test migration",
        "description": "",
        "start_page": {"title": "Accueil", "source_id": "10"},
        "pages": [
            {
                "source_id": "10",
                "title": "Accueil",
                "content": {
                    "status": "ok",
                    "blocks": [{"type": "media", "source_id": "901"}],
                    "unsupported_components": [],
                },
                "internal_links": [],
            }
        ],
        "internal_links": [],
        "history": {
            "source_export_contains_history": False,
            "migration_policy": "current_pages_only",
            "authors_migrated": False,
        },
        "media": {
            "901": {
                "source_id": "901",
                "title": "wiki-image.png",
                "items": [
                    {
                        "location": "wiki-image.png",
                        "mime_type": "image/png",
                        "archive_path": media_path,
                    }
                ],
            }
        },
        "files": {},
        "unsupported_components": [],
    }

    item = MigrationItem(
        source_id="273",
        type="wiki",
        title="wiki test migration",
        metadata={
            "ilias_type": "wiki",
            "obj_id": "900",
            "wiki_export_base": structure["source"]["export_base"],
            "wiki_structure": structure,
        },
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="cours test migration", items=[item])
    )

    output_dir = tmp_path / "package"
    result = extract_wiki_assets(document, archive_path, output_dir)

    assert result["managed_directory"] == "wikis"
    assert result["extracted"]["wiki_structures"] == 1
    assert result["extracted"]["wiki_media_files"] == 1
    assert result["extracted"]["wiki_files"] == 0
    assert result["missing"] == []

    expected_media = output_dir / "wikis/273/media/901/wiki-image.png"
    assert expected_media.read_bytes() == b"wiki-image"

    structure_path = output_dir / "wikis/273/structure.json"
    saved = json.loads(structure_path.read_text(encoding="utf-8"))
    saved_item = saved["media"]["901"]["items"][0]
    assert saved_item["migration_path"] == "wikis/273/media/901/wiki-image.png"

    assert item.metadata["migration_structure_path"] == "wikis/273/structure.json"
    assert item.metadata["migration_media_file_count"] == 1
    assert item.metadata["migration_embedded_file_count"] == 0
    assert "wiki_structure" not in item.metadata
