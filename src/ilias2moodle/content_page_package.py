from __future__ import annotations

import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.content_page import parse_content_pages
from ilias2moodle.model import MigrationDocument, MigrationItem


def _walk(items: Iterable[MigrationItem]) -> Iterable[MigrationItem]:
    for item in items:
        yield item
        yield from _walk(item.items)


def _safe_archive_relative(path: str) -> PurePosixPath:
    candidate = PurePosixPath(path.lstrip("/"))
    if not candidate.parts or ".." in candidate.parts:
        raise ValueError(f"Chemin d'archive non sûr : {path}")
    return candidate


def enrich_document_content_pages(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    """Attach parsed Content Page structures to copa items in a migration document."""

    pages = parse_content_pages(str(archive_path))
    pages_by_object_id = {
        str(page.get("source", {}).get("object_id", "")): page
        for page in pages
        if str(page.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0
    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "copa":
            continue

        item.type = "content_page"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = pages_by_object_id.get(object_id)
        if structure is None:
            item.metadata["content_page_parse_error"] = (
                f"Aucune structure Content Page trouvée pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = str(structure.get("title", "")) or item.title
        item.description = str(structure.get("description", ""))
        media = structure.get("media", {})
        files = structure.get("files", {})
        unsupported = structure.get("unsupported_components", [])
        item.metadata.update(
            {
                "content_page_schema_version": str(
                    structure.get("schema_version", "1.0")
                ),
                "content_page_export_base": str(
                    structure.get("source", {}).get("export_base", "")
                ),
                "content_page_active": str(structure.get("active", "")),
                "content_page_language": str(structure.get("language", "")),
                "content_page_block_count": len(structure.get("blocks", [])),
                "content_page_media_count": len(media) if isinstance(media, dict) else 0,
                "content_page_file_count": len(files) if isinstance(files, dict) else 0,
                "content_page_unsupported_count": (
                    len(unsupported) if isinstance(unsupported, list) else 0
                ),
                "content_page_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(pages),
        "matched": matched,
        "missing": missing,
    }


def extract_content_page_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
) -> dict[str, Any]:
    """Extract Content Page media/files and persist one normalized structure per page."""

    output_dir.mkdir(parents=True, exist_ok=True)
    managed_root = output_dir / "content_pages"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "content_page_structures": 0,
        "content_page_media_files": 0,
        "content_page_files": 0,
    }
    missing: list[dict[str, str]] = []

    with zipfile.ZipFile(str(archive_path)) as archive:
        members = {name.lstrip("/"): name for name in archive.namelist()}

        def copy_member(source_path: str, destination_relative: PurePosixPath) -> bool:
            canonical = _safe_archive_relative(source_path).as_posix()
            actual_name = members.get(canonical)
            if actual_name is None:
                return False
            destination = output_dir.joinpath(*destination_relative.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(actual_name) as src, destination.open("wb") as dst:
                shutil.copyfileobj(src, dst)
            return True

        for item in _walk(document.course.items):
            if item.type != "content_page":
                continue

            structure = item.metadata.get("content_page_structure")
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "content_page_structure",
                        "source_path": str(
                            item.metadata.get("content_page_export_base", "")
                        ),
                    }
                )
                continue

            page_root = PurePosixPath("content_pages", item.source_id)

            media = structure.get("media", {})
            if isinstance(media, dict):
                for media_id, media_object in media.items():
                    if not isinstance(media_object, dict):
                        continue
                    for index, media_item in enumerate(
                        media_object.get("items", []), start=1
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
                            *page_root.parts,
                            "media",
                            str(media_id),
                            filename,
                        )
                        if copy_member(source_path, destination):
                            media_item["migration_path"] = destination.as_posix()
                            stats["content_page_media_files"] += 1
                        else:
                            missing.append(
                                {
                                    "source_id": item.source_id,
                                    "kind": "content_page_media",
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
                        *page_root.parts,
                        "files",
                        str(file_id),
                        filename,
                    )
                    if copy_member(source_path, destination):
                        file_object["migration_path"] = destination.as_posix()
                        stats["content_page_files"] += 1
                    else:
                        missing.append(
                            {
                                "source_id": item.source_id,
                                "kind": "content_page_file",
                                "source_path": source_path,
                            }
                        )

            structure_path = PurePosixPath(*page_root.parts, "structure.json")
            destination = output_dir.joinpath(*structure_path.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_text(
                json.dumps(structure, ensure_ascii=False, indent=2),
                encoding="utf-8",
            )

            item.metadata["migration_structure_path"] = structure_path.as_posix()
            item.metadata["migration_media_file_count"] = sum(
                1
                for media_object in structure.get("media", {}).values()
                if isinstance(media_object, dict)
                for media_item in media_object.get("items", [])
                if isinstance(media_item, dict) and media_item.get("migration_path")
            )
            item.metadata["migration_embedded_file_count"] = sum(
                1
                for file_object in structure.get("files", {}).values()
                if isinstance(file_object, dict) and file_object.get("migration_path")
            )
            item.metadata.pop("content_page_structure", None)
            stats["content_page_structures"] += 1

    return {
        "managed_directory": "content_pages",
        "extracted": stats,
        "missing": missing,
    }
