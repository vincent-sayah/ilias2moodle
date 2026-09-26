from __future__ import annotations

import hashlib
import json
import zipfile
from pathlib import Path

from ilias2moodle.blog_package import extract_blog_assets
from ilias2moodle.forum_package import extract_forum_assets
from ilias2moodle.media_pool_package import extract_media_pool_assets
from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


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
    target = directory / location
    target.write_bytes(content)
    manifest = {
        "mob_id": int(mob_id),
        "location": location,
        "output_name": location,
        "size": len(content),
        "sha256": _sha256(content),
        "status": "OK_SIZE_UNAVAILABLE",
    }
    (directory / "manifest.json").write_text(
        json.dumps(manifest),
        encoding="utf-8",
    )


def _write_forum_recovery(
    root: Path,
    forum_obj_id: str,
    post_id: str,
    filename: str,
    content: bytes,
) -> None:
    directory = root / f"forum_{forum_obj_id}" / f"post_{post_id}"
    directory.mkdir(parents=True)
    target = directory / filename
    target.write_bytes(content)
    manifest = {
        "forum_obj_id": int(forum_obj_id),
        "post_id": int(post_id),
        "filename": filename,
        "output_name": filename,
        "size": len(content),
        "sha256": _sha256(content),
        "status": "OK",
    }
    (directory / "manifest.json").write_text(
        json.dumps(manifest),
        encoding="utf-8",
    )


def _empty_zip(path: Path) -> None:
    with zipfile.ZipFile(path, "w"):
        pass


def test_blog_mediaobject_recovery_is_used_when_zip_asset_is_missing(
    tmp_path: Path,
) -> None:
    archive = tmp_path / "course.zip"
    _empty_zip(archive)
    recovery = tmp_path / "media-recovery"
    _write_media_recovery(recovery, "817", "vid4.mp4", b"blog-video")

    structure = {
        "source": {"object_id": "812"},
        "postings": [],
        "media": {
            "817": {
                "items": [
                    {
                        "location": "vid4.mp4",
                        "archive_path": "missing/vid4.mp4",
                    }
                ]
            }
        },
        "files": {},
    }
    item = MigrationItem(
        source_id="277",
        type="blog",
        title="Blog",
        metadata={"blog_structure": structure},
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="Course", items=[item])
    )
    output = tmp_path / "package"

    result = extract_blog_assets(
        document,
        archive,
        output,
        mediaobject_recovery=recovery,
    )

    assert result["missing"] == []
    assert result["extracted"]["blog_media_files"] == 1
    assert result["extracted"]["blog_media_files_recovered"] == 1
    assert (
        output / "blogs/277/media/817/vid4.mp4"
    ).read_bytes() == b"blog-video"


def test_media_pool_mediaobject_recovery_is_used_when_zip_asset_is_missing(
    tmp_path: Path,
) -> None:
    archive = tmp_path / "course.zip"
    _empty_zip(archive)
    recovery = tmp_path / "media-recovery"
    _write_media_recovery(recovery, "819", "du2.png", b"pool-image")

    structure = {
        "source": {"object_id": "818"},
        "records": [],
        "media": {
            "819": {
                "items": [
                    {
                        "location": "du2.png",
                        "archive_path": "missing/du2.png",
                    }
                ]
            }
        },
    }
    item = MigrationItem(
        source_id="278",
        type="media_pool",
        title="Media Pool",
        metadata={"media_pool_structure": structure},
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="Course", items=[item])
    )
    output = tmp_path / "package"

    result = extract_media_pool_assets(
        document,
        archive,
        output,
        mediaobject_recovery=recovery,
    )

    assert result["missing"] == []
    assert result["extracted"]["media_pool_media_files"] == 1
    assert result["extracted"]["media_pool_media_files_recovered"] == 1
    assert (
        output / "media_pools/278/media/819/du2.png"
    ).read_bytes() == b"pool-image"


def test_forum_attachment_recovery_is_used_when_zip_asset_is_missing(
    tmp_path: Path,
) -> None:
    archive = tmp_path / "course.zip"
    _empty_zip(archive)
    recovery = tmp_path / "forum-recovery"
    _write_forum_recovery(
        recovery,
        "807",
        "18",
        "handout.pdf",
        b"forum-handout",
    )

    structure = {
        "source": {"object_id": "807"},
        "threads": [
            {
                "posts": [
                    {
                        "source_id": "18",
                        "attachments": [
                            {
                                "filename": "handout.pdf",
                                "archive_path": "missing/handout.pdf",
                            }
                        ],
                        "media_objects": [],
                    }
                ]
            }
        ],
    }
    item = MigrationItem(
        source_id="275",
        type="forum",
        title="Forum",
        metadata={"forum_structure": structure},
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="Course", items=[item])
    )
    output = tmp_path / "package"

    result = extract_forum_assets(
        document,
        archive,
        output,
        attachment_recovery=recovery,
    )

    assert result["missing"] == []
    assert result["extracted"]["forum_attachment_files"] == 1
    assert result["extracted"]["forum_attachment_files_recovered"] == 1
    assert (
        output / "forums/275/attachments/18/handout.pdf"
    ).read_bytes() == b"forum-handout"


def test_recovery_sha256_mismatch_is_rejected(tmp_path: Path) -> None:
    archive = tmp_path / "course.zip"
    _empty_zip(archive)
    recovery = tmp_path / "media-recovery"
    _write_media_recovery(recovery, "817", "vid4.mp4", b"good")

    manifest_path = recovery / "mob_817/manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    manifest["sha256"] = "0" * 64
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

    structure = {
        "source": {"object_id": "812"},
        "postings": [],
        "media": {
            "817": {
                "items": [
                    {
                        "location": "vid4.mp4",
                        "archive_path": "missing/vid4.mp4",
                    }
                ]
            }
        },
        "files": {},
    }
    item = MigrationItem(
        source_id="277",
        type="blog",
        title="Blog",
        metadata={"blog_structure": structure},
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="Course", items=[item])
    )

    result = extract_blog_assets(
        document,
        archive,
        tmp_path / "package",
        mediaobject_recovery=recovery,
    )

    assert len(result["missing"]) == 1
    assert result["missing"][0]["kind"] == (
        "mediaobject_recovery_integrity_error"
    )
