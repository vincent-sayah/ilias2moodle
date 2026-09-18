from __future__ import annotations

import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.glossary import parse_glossaries
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


def enrich_document_glossaries(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    """Attach parsed Glossary structures to ``glo`` items in a migration document."""

    glossaries = parse_glossaries(str(archive_path))
    glossaries_by_object_id = {
        str(glossary.get("source", {}).get("object_id", "")): glossary
        for glossary in glossaries
        if str(glossary.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0
    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "glo":
            continue

        item.type = "glossary"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = glossaries_by_object_id.get(object_id)
        if structure is None:
            item.metadata["glossary_parse_error"] = (
                f"Aucune structure Glossaire trouvée pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = str(structure.get("title", "")) or item.title
        item.description = str(structure.get("description", ""))

        terms = structure.get("terms", [])
        media = structure.get("media", {})
        files = structure.get("files", {})
        unsupported = structure.get("unsupported_components", [])
        taxonomy = structure.get("taxonomy", {})

        item.metadata.update(
            {
                "glossary_schema_version": str(structure.get("schema_version", "1.0")),
                "glossary_export_base": str(
                    structure.get("source", {}).get("export_base", "")
                ),
                "glossary_term_count": len(terms) if isinstance(terms, list) else 0,
                "glossary_media_count": len(media) if isinstance(media, dict) else 0,
                "glossary_file_count": len(files) if isinstance(files, dict) else 0,
                "glossary_unsupported_count": (
                    len(unsupported) if isinstance(unsupported, list) else 0
                ),
                "glossary_taxonomy_enabled": bool(
                    taxonomy.get("enabled", False)
                    if isinstance(taxonomy, dict)
                    else False
                ),
                "glossary_taxonomy_export_present": bool(
                    taxonomy.get("export_component_present", False)
                    if isinstance(taxonomy, dict)
                    else False
                ),
                "glossary_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(glossaries),
        "matched": matched,
        "missing": missing,
    }


def extract_glossary_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
) -> dict[str, Any]:
    """Extract Glossary assets and persist one neutral structure per glossary."""

    output_dir.mkdir(parents=True, exist_ok=True)
    managed_root = output_dir / "glossaries"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "glossary_structures": 0,
        "glossary_media_files": 0,
        "glossary_files": 0,
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
            if item.type != "glossary":
                continue

            structure = item.metadata.get("glossary_structure")
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "glossary_structure",
                        "source_path": str(item.metadata.get("glossary_export_base", "")),
                    }
                )
                continue

            glossary_root = PurePosixPath("glossaries", item.source_id)

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
                            *glossary_root.parts,
                            "media",
                            str(media_id),
                            filename,
                        )
                        if copy_member(source_path, destination):
                            media_item["migration_path"] = destination.as_posix()
                            stats["glossary_media_files"] += 1
                        else:
                            missing.append(
                                {
                                    "source_id": item.source_id,
                                    "kind": "glossary_media",
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
                        *glossary_root.parts,
                        "files",
                        str(file_id),
                        filename,
                    )
                    if copy_member(source_path, destination):
                        file_object["migration_path"] = destination.as_posix()
                        stats["glossary_files"] += 1
                    else:
                        missing.append(
                            {
                                "source_id": item.source_id,
                                "kind": "glossary_file",
                                "source_path": source_path,
                            }
                        )

            structure_path = PurePosixPath(*glossary_root.parts, "structure.json")
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
            item.metadata.pop("glossary_structure", None)
            stats["glossary_structures"] += 1

    return {
        "managed_directory": "glossaries",
        "extracted": stats,
        "missing": missing,
    }
