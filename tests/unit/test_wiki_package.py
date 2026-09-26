from __future__ import annotations

import hashlib
import json
import zipfile
from pathlib import Path

from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem
from ilias2moodle.wiki_package import (
    extract_wiki_assets,
    recover_missing_wiki_structures,
)


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



def _sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _write_media_recovery(
    root: Path,
    mob_id: str,
    location: str,
    content: bytes,
) -> None:
    directory = root / f"mob_{mob_id}"
    directory.mkdir(parents=True)
    (directory / location).write_bytes(content)
    (directory / "manifest.json").write_text(
        json.dumps(
            {
                "mob_id": int(mob_id),
                "location": location,
                "output_name": location,
                "size": len(content),
                "sha256": _sha256(content),
                "status": "OK_SIZE_UNAVAILABLE",
            }
        ),
        encoding="utf-8",
    )


def _write_wiki_page(
    directory: Path,
    page_id: str,
    title: str,
    xml: bytes,
) -> dict[str, object]:
    pages = directory / "pages"
    pages.mkdir(parents=True, exist_ok=True)
    path = pages / f"page_{page_id}.xml"
    path.write_bytes(xml)
    return {
        "source_page_id": page_id,
        "title": title,
        "language": "-",
        "created": "2026-09-20 08:21:24",
        "create_user_id": "6",
        "last_change": "2026-09-20 08:24:56",
        "last_change_user_id": "6",
        "xml_file": f"pages/page_{page_id}.xml",
        "size": len(xml),
        "sha256": _sha256(xml),
        "media_object_ids": [],
        "file_object_ids": [],
    }


def test_missing_wiki_is_recovered_from_current_pages_and_media(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "course.zip"
    with zipfile.ZipFile(archive_path, "w"):
        pass

    media_recovery = tmp_path / "media-recovery"
    _write_media_recovery(
        media_recovery,
        "820",
        "vid5.mp4",
        b"wiki-video",
    )
    _write_media_recovery(
        media_recovery,
        "822",
        "femme_noire_robot.png",
        b"wiki-image",
    )

    wiki_recovery = tmp_path / "wiki-recovery"
    wiki_root = wiki_recovery / "wiki_823"

    page14 = (
        b'<PageObject Active="1" Language="-"><PageContent>'
        b'<Paragraph>Accueil</Paragraph>'
        b'<MediaObject><MediaAlias OriginId="il__mob_820">'
        b'<MediaAliasItem Purpose="Standard"/></MediaAlias></MediaObject>'
        b'<MediaObject><MediaAlias OriginId="il__mob_822">'
        b'<MediaAliasItem Purpose="Standard"/></MediaAlias></MediaObject>'
        b'</PageContent></PageObject>'
    )
    page15 = (
        b'<PageObject Active="1" Language="-"><PageContent>'
        b'<Paragraph>Page 1</Paragraph>'
        b'</PageContent></PageObject>'
    )

    pages = [
        _write_wiki_page(wiki_root, "14", "accueil", page14),
        _write_wiki_page(wiki_root, "15", "page1", page15),
    ]
    (wiki_root / "manifest.json").write_text(
        json.dumps(
            {
                "schema_version": "1.0",
                "course": {
                    "object_id": "504",
                    "ref_id": "128",
                    "title": "cours test migration",
                },
                "wiki": {
                    "object_id": "823",
                    "ref_id": "279",
                    "title": "wiki test media",
                    "start_page": {
                        "title": "accueil",
                        "source_id": "14",
                    },
                },
                "history_policy": {
                    "migration_policy": "current_pages_only",
                    "source_export_contains_history": False,
                    "authors_migrated": False,
                },
                "pages": pages,
            }
        ),
        encoding="utf-8",
    )

    item = MigrationItem(
        source_id="279",
        type="wiki",
        title="wiki test media",
        metadata={
            "ilias_type": "wiki",
            "obj_id": "823",
            "wiki_parse_error": "missing from native ZIP",
        },
    )
    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    recovery = recover_missing_wiki_structures(
        document,
        archive_path,
        wiki_recovery,
        mediaobject_recovery=media_recovery,
    )

    assert recovery["missing"] == []
    assert recovery["recovered"]["wiki_structures_recovered"] == 1
    assert recovery["recovered"]["wiki_pages_recovered"] == 2
    assert "wiki_parse_error" not in item.metadata

    structure = item.metadata["wiki_structure"]
    assert structure["start_page"] == {
        "title": "accueil",
        "source_id": "14",
    }
    assert [page["source_id"] for page in structure["pages"]] == ["14", "15"]
    assert set(structure["media"]) == {"820", "822"}
    assert structure["unsupported_components"] == []

    output_dir = tmp_path / "package"
    extracted = extract_wiki_assets(
        document,
        archive_path,
        output_dir,
        mediaobject_recovery=media_recovery,
    )

    assert extracted["missing"] == []
    assert extracted["extracted"]["wiki_structures"] == 1
    assert extracted["extracted"]["wiki_media_files"] == 2
    assert extracted["extracted"]["wiki_media_files_recovered"] == 2

    assert (
        output_dir / "wikis/279/media/820/vid5.mp4"
    ).read_bytes() == b"wiki-video"
    assert (
        output_dir / "wikis/279/media/822/femme_noire_robot.png"
    ).read_bytes() == b"wiki-image"

    saved = json.loads(
        (output_dir / "wikis/279/structure.json").read_text(
            encoding="utf-8"
        )
    )
    assert len(saved["pages"]) == 2
    assert saved["media"]["820"]["items"][0]["migration_path"] == (
        "wikis/279/media/820/vid5.mp4"
    )
    assert saved["media"]["822"]["items"][0]["migration_path"] == (
        "wikis/279/media/822/femme_noire_robot.png"
    )
