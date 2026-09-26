from __future__ import annotations

import hashlib
import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.blog import parse_blogs
from ilias2moodle.model import MigrationDocument, MigrationItem
from ilias2moodle.recovery_assets import load_mediaobject_recovery


def _walk(items: Iterable[MigrationItem]) -> Iterable[MigrationItem]:
    for item in items:
        yield item
        yield from _walk(item.items)


def _safe_archive_relative(path: str) -> PurePosixPath:
    candidate = PurePosixPath(path.lstrip("/"))
    if not candidate.parts or ".." in candidate.parts:
        raise ValueError(f"Chemin d'archive non sûr : {path}")
    return candidate


def enrich_document_blogs(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    """Attach parsed Blog structures to blog items."""

    blogs = parse_blogs(str(archive_path))
    by_object_id = {
        str(blog.get("source", {}).get("object_id", "")): blog
        for blog in blogs
        if str(blog.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0

    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "blog":
            continue

        item.type = "blog"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = by_object_id.get(object_id)
        if structure is None:
            item.metadata["blog_parse_error"] = (
                f"Aucune structure Blog trouvée pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = str(structure.get("title", "")) or item.title
        item.description = str(structure.get("description", ""))

        postings = structure.get("postings", [])
        media = structure.get("media", {})
        files = structure.get("files", {})
        unsupported = structure.get("unsupported_components", [])

        keyword_count = 0
        author_ids: set[str] = set()
        if isinstance(postings, list):
            for posting in postings:
                if not isinstance(posting, dict):
                    continue
                keywords = posting.get("keywords", [])
                if isinstance(keywords, list):
                    keyword_count += len(keywords)
                author = str(posting.get("author_source", ""))
                if author:
                    author_ids.add(author)

        item.metadata.update(
            {
                "blog_schema_version": str(structure.get("schema_version", "1.0")),
                "blog_export_base": str(
                    structure.get("source", {}).get("export_base", "")
                ),
                "blog_posting_count": len(postings) if isinstance(postings, list) else 0,
                "blog_media_count": len(media) if isinstance(media, dict) else 0,
                "blog_file_count": len(files) if isinstance(files, dict) else 0,
                "blog_keyword_count": keyword_count,
                "blog_source_author_count": len(author_ids),
                "blog_unsupported_count": (
                    len(unsupported) if isinstance(unsupported, list) else 0
                ),
                "blog_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(blogs),
        "matched": matched,
        "missing": missing,
    }


def extract_blog_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
    mediaobject_recovery: str | Path | None = None,
) -> dict[str, Any]:
    """Extract Blog media/files and persist one normalized structure per Blog."""

    output_dir.mkdir(parents=True, exist_ok=True)
    managed_root = output_dir / "blogs"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "blog_structures": 0,
        "blog_postings": 0,
        "blog_media_files": 0,
        "blog_media_files_recovered": 0,
        "blog_files": 0,
    }
    missing: list[dict[str, str]] = []

    with zipfile.ZipFile(str(archive_path)) as archive:
        members = {name.lstrip("/"): name for name in archive.namelist()}

        def copy_member(
            source_path: str,
            destination_relative: PurePosixPath,
        ) -> tuple[bool, int, str]:
            canonical = _safe_archive_relative(source_path).as_posix()
            actual_name = members.get(canonical)
            if actual_name is None:
                return False, 0, ""

            destination = output_dir.joinpath(*destination_relative.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            digest = hashlib.sha256()
            size = 0

            with archive.open(actual_name) as src, destination.open("wb") as dst:
                while True:
                    chunk = src.read(1024 * 1024)
                    if not chunk:
                        break
                    dst.write(chunk)
                    digest.update(chunk)
                    size += len(chunk)

            return True, size, digest.hexdigest()

        for item in _walk(document.course.items):
            if item.type != "blog":
                continue

            structure = item.metadata.get("blog_structure")
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "blog_structure",
                        "source_path": str(item.metadata.get("blog_export_base", "")),
                    }
                )
                continue

            blog_root = PurePosixPath("blogs", item.source_id)

            blog_media_files = 0
            blog_files = 0

            media = structure.get("media", {})
            if isinstance(media, dict):
                for media_id, media_object in media.items():
                    if not isinstance(media_object, dict):
                        continue

                    for index, media_item in enumerate(
                        media_object.get("items", []),
                        start=1,
                    ):
                        if not isinstance(media_item, dict):
                            continue

                        source_path = str(media_item.get("archive_path", ""))
                        if not source_path:
                            continue

                        filename = Path(
                            str(media_item.get("location", "")) or source_path
                        ).name
                        if not filename:
                            filename = f"media-{index}"

                        destination = PurePosixPath(
                            *blog_root.parts,
                            "media",
                            str(media_id),
                            filename,
                        )
                        copied, size, sha256 = copy_member(
                            source_path,
                            destination,
                        )
                        recovered = False
                        recovery_error = None
                        if not copied and mediaobject_recovery is not None:
                            recovery, recovery_error = load_mediaobject_recovery(
                                mediaobject_recovery,
                                str(media_id),
                                str(media_item.get("location", "")),
                            )
                            if recovery is not None:
                                destination_path = output_dir.joinpath(
                                    *destination.parts
                                )
                                destination_path.parent.mkdir(
                                    parents=True,
                                    exist_ok=True,
                                )
                                shutil.copy2(recovery["path"], destination_path)
                                copied = True
                                recovered = True
                                size = int(recovery["size"])
                                sha256 = str(recovery["sha256"])

                        if copied:
                            media_item["migration_path"] = destination.as_posix()
                            media_item["migration_size"] = size
                            media_item["migration_sha256"] = sha256
                            if recovered:
                                media_item["recovery_status"] = "RECOVERED"
                                stats["blog_media_files_recovered"] += 1
                            stats["blog_media_files"] += 1
                            blog_media_files += 1
                        else:
                            missing.append(
                                {
                                    "source_id": item.source_id,
                                    "kind": recovery_error or "blog_media",
                                    "source_path": source_path,
                                }
                            )

            files = structure.get("files", {})
            if isinstance(files, dict):
                for file_id, file_object in files.items():
                    if not isinstance(file_object, dict):
                        continue

                    source_path = str(file_object.get("archive_path", ""))
                    if not source_path:
                        continue

                    filename = Path(
                        str(file_object.get("filename", "")) or source_path
                    ).name
                    destination = PurePosixPath(
                        *blog_root.parts,
                        "files",
                        str(file_id),
                        filename,
                    )
                    copied, size, sha256 = copy_member(
                        source_path,
                        destination,
                    )
                    if copied:
                        file_object["migration_path"] = destination.as_posix()
                        file_object["migration_size"] = size
                        file_object["migration_sha256"] = sha256
                        stats["blog_files"] += 1
                        blog_files += 1
                    else:
                        missing.append(
                            {
                                "source_id": item.source_id,
                                "kind": "blog_file",
                                "source_path": source_path,
                            }
                        )

            postings = structure.get("postings", [])
            stats["blog_postings"] += (
                len(postings) if isinstance(postings, list) else 0
            )

            structure_path = PurePosixPath(
                *blog_root.parts,
                "structure.json",
            )
            destination = output_dir.joinpath(*structure_path.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_text(
                json.dumps(structure, ensure_ascii=False, indent=2),
                encoding="utf-8",
            )

            item.metadata["migration_structure_path"] = structure_path.as_posix()
            item.metadata["migration_media_file_count"] = blog_media_files
            item.metadata["migration_embedded_file_count"] = blog_files
            item.metadata.pop("blog_structure", None)
            stats["blog_structures"] += 1

    return {
        "managed_directory": "blogs",
        "extracted": stats,
        "missing": missing,
    }
