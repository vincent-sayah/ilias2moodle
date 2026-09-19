from __future__ import annotations

import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.wiki import parse_wikis
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


def enrich_document_wikis(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    """Attach parsed Wiki structures to wiki items."""

    wikis = parse_wikis(str(archive_path))
    by_object_id = {
        str(wiki.get("source", {}).get("object_id", "")): wiki
        for wiki in wikis
        if str(wiki.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0
    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "wiki":
            continue

        item.type = "wiki"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = by_object_id.get(object_id)
        if structure is None:
            item.metadata["wiki_parse_error"] = (
                f"Aucune structure Wiki trouvée pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = str(structure.get("title", "")) or item.title
        item.description = str(structure.get("description", ""))

        pages = structure.get("pages", [])
        media = structure.get("media", {})
        files = structure.get("files", {})
        links = structure.get("internal_links", [])
        unsupported = structure.get("unsupported_components", [])

        item.metadata.update(
            {
                "wiki_schema_version": str(structure.get("schema_version", "1.0")),
                "wiki_export_base": str(
                    structure.get("source", {}).get("export_base", "")
                ),
                "wiki_start_page_title": str(
                    structure.get("start_page", {}).get("title", "")
                ),
                "wiki_start_page_source_id": str(
                    structure.get("start_page", {}).get("source_id", "")
                ),
                "wiki_page_count": len(pages) if isinstance(pages, list) else 0,
                "wiki_media_count": len(media) if isinstance(media, dict) else 0,
                "wiki_file_count": len(files) if isinstance(files, dict) else 0,
                "wiki_internal_link_count": (
                    len(links) if isinstance(links, list) else 0
                ),
                "wiki_unsupported_count": (
                    len(unsupported) if isinstance(unsupported, list) else 0
                ),
                "wiki_history_policy": "current_pages_only",
                "wiki_history_migrated": False,
                "wiki_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(wikis),
        "matched": matched,
        "missing": missing,
    }


def extract_wiki_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
) -> dict[str, Any]:
    """Extract Wiki assets and persist one neutral structure per wiki."""

    output_dir.mkdir(parents=True, exist_ok=True)
    managed_root = output_dir / "wikis"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "wiki_structures": 0,
        "wiki_media_files": 0,
        "wiki_files": 0,
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
            if item.type != "wiki":
                continue

            structure = item.metadata.get("wiki_structure")
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_structure",
                        "source_path": str(item.metadata.get("wiki_export_base", "")),
                    }
                )
                continue

            wiki_root = PurePosixPath("wikis", item.source_id)

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
                            *wiki_root.parts,
                            "media",
                            str(media_id),
                            filename,
                        )
                        if copy_member(source_path, destination):
                            media_item["migration_path"] = destination.as_posix()
                            stats["wiki_media_files"] += 1
                        else:
                            missing.append(
                                {
                                    "source_id": item.source_id,
                                    "kind": "wiki_media",
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
                        *wiki_root.parts,
                        "files",
                        str(file_id),
                        filename,
                    )
                    if copy_member(source_path, destination):
                        file_object["migration_path"] = destination.as_posix()
                        stats["wiki_files"] += 1
                    else:
                        missing.append(
                            {
                                "source_id": item.source_id,
                                "kind": "wiki_file",
                                "source_path": source_path,
                            }
                        )

            structure_path = PurePosixPath(*wiki_root.parts, "structure.json")
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
            item.metadata.pop("wiki_structure", None)
            stats["wiki_structures"] += 1

    return {
        "managed_directory": "wikis",
        "extracted": stats,
        "missing": missing,
    }
