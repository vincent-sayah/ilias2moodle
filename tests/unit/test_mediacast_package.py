from __future__ import annotations

import hashlib
import json
import zipfile
from pathlib import Path

from ilias2moodle.mediacast_package import (
    extract_mediacast_assets,
    recover_mediacast_local_files,
)
from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


def test_mediacast_package_recovers_mp4_and_keeps_external_url(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_33/1789835852__0__mcst_809"
    preview_path = (
        f"{base}/components/ILIAS/MediaObjects/set_0/"
        "expDir_1/dsDir_1/mob_vpreview.png"
    )

    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(preview_path, b"preview")

    recovery_root = tmp_path / "recovery"
    mob_root = recovery_root / "mob_810"
    mob_root.mkdir(parents=True)
    media = b"fake-mp4-content"
    media_path = mob_root / "clip1.mp4"
    media_path.write_bytes(media)
    sha256 = hashlib.sha256(media).hexdigest()
    (mob_root / "manifest.json").write_text(
        json.dumps(
            {
                "mob_id": 810,
                "location": "clip1.mp4",
                "output_name": "clip1.mp4",
                "size": len(media),
                "sha256": sha256,
                "status": "OK_SIZE_UNAVAILABLE",
                "source": "ilias_mediaobject_api",
                "read_only": True,
            }
        ),
        encoding="utf-8",
    )

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "809",
            "ref_id": "276",
            "export_base": base,
        },
        "title": "mediacast test migration",
        "description": "",
        "entries": [
            {
                "source_id": "96",
                "position": 1,
                "title": "clip Vincent",
                "description": "description de clip1",
                "mob_id": "810",
                "source_kind": "local_file",
                "supported": True,
                "location": "clip1.mp4",
                "location_type": "LocalFile",
                "format": "video/mp4",
                "embedded": False,
                "archive_path": "",
                "needs_recovery": True,
                "preview_assets": [
                    {
                        "filename": "mob_vpreview.png",
                        "archive_path": preview_path,
                        "embedded": True,
                    }
                ],
            },
            {
                "source_id": "97",
                "position": 2,
                "title": "Kimo Sounds Rise Slow",
                "description": "",
                "mob_id": "811",
                "source_kind": "external_url",
                "supported": True,
                "location": "https://youtu.be/example",
                "location_type": "Reference",
                "format": "video/youtube",
                "embedded": False,
                "needs_recovery": False,
                "preview_assets": [],
            },
        ],
        "entry_count": 2,
        "local_file_count": 1,
        "external_url_count": 1,
        "unsupported_entry_count": 0,
        "missing_assets": [
            {
                "entry_id": "96",
                "mob_id": "810",
                "kind": "mediacast_local_media",
                "source_path": "clip1.mp4",
            }
        ],
        "target_strategy": {
            "moodle_activity": "mod_data",
        },
    }

    item = MigrationItem(
        source_id="276",
        type="mediacast",
        title="mediacast test migration",
        metadata={
            "ilias_type": "mcst",
            "obj_id": "809",
            "mediacast_export_base": base,
            "mediacast_structure": structure,
        },
    )
    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    recovery = recover_mediacast_local_files(
        document,
        recovery_root,
    )
    assert recovery["missing"] == []
    assert (
        recovery["recovered"]["local_media_files_recovered"]
        == 1
    )

    output = tmp_path / "package"
    result = extract_mediacast_assets(
        document,
        archive_path,
        output,
    )

    assert result["managed_directory"] == "mediacasts"
    assert result["missing"] == []
    assert result["extracted"]["mediacast_structures"] == 1
    assert result["extracted"]["mediacast_local_media_files"] == 1
    assert result["extracted"]["mediacast_preview_files"] == 1
    assert result["extracted"]["mediacast_external_urls"] == 1

    copied = output / "mediacasts/276/media/96/clip1.mp4"
    assert copied.read_bytes() == media

    saved = json.loads(
        (
            output / "mediacasts/276/structure.json"
        ).read_text(encoding="utf-8")
    )
    local = saved["entries"][0]
    external = saved["entries"][1]

    assert (
        local["migration_path"]
        == "mediacasts/276/media/96/clip1.mp4"
    )
    assert local["migration_size"] == len(media)
    assert local["migration_sha256"] == sha256
    assert (
        local["preview_assets"][0]["migration_path"]
        == "mediacasts/276/previews/96/mob_vpreview.png"
    )
    assert external["location"] == "https://youtu.be/example"
    assert "migration_path" not in external
    assert (
        item.metadata["migration_structure_path"]
        == "mediacasts/276/structure.json"
    )
    assert "mediacast_structure" not in item.metadata
