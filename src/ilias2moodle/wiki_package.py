from __future__ import annotations

import hashlib
import json
import mimetypes
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any
from xml.etree import ElementTree as ET

from ilias2moodle.ilias.content_page import (
    ContentPageParser,
    _first_descendant,
    _local_name,
)
from ilias2moodle.ilias.wiki import (
    _collect_internal_links,
    _rewrite_wiki_markup_links,
    parse_wikis,
)
from ilias2moodle.model import MigrationDocument, MigrationItem
from ilias2moodle.recovery_assets import (
    load_mediaobject_recovery,
    load_mediaobject_recovery_by_id,
)


def _walk(items: Iterable[MigrationItem]) -> Iterable[MigrationItem]:
    for item in items:
        yield item
        yield from _walk(item.items)


def _safe_archive_relative(path: str) -> PurePosixPath:
    candidate = PurePosixPath(path.lstrip("/"))
    if not candidate.parts or ".." in candidate.parts:
        raise ValueError(f"Chemin d'archive non sûr : {path}")
    return candidate


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _attach_wiki_structure(item: MigrationItem, structure: dict[str, Any]) -> None:
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
            "wiki_internal_link_count": len(links) if isinstance(links, list) else 0,
            "wiki_unsupported_count": (
                len(unsupported) if isinstance(unsupported, list) else 0
            ),
            "wiki_history_policy": "current_pages_only",
            "wiki_history_migrated": False,
            "wiki_structure": structure,
        }
    )
    item.metadata.pop("wiki_parse_error", None)


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

        _attach_wiki_structure(item, structure)
        matched += 1

    return {
        "detected": len(wikis),
        "matched": matched,
        "missing": missing,
    }


def _safe_recovery_file(root: Path, relative: str) -> Path | None:
    candidate_rel = PurePosixPath(relative)
    if (
        not relative
        or candidate_rel.is_absolute()
        or ".." in candidate_rel.parts
    ):
        return None

    candidate = root.joinpath(*candidate_rel.parts)
    if candidate.is_symlink() or not candidate.is_file():
        return None

    try:
        candidate.resolve().relative_to(root.resolve())
    except ValueError:
        return None
    return candidate


def _collect_block_asset_ids(
    value: Any,
    media_ids: set[str],
    file_ids: set[str],
) -> None:
    if isinstance(value, dict):
        if value.get("type") == "media":
            source_id = str(value.get("source_id", ""))
            if source_id:
                media_ids.add(source_id)
        if value.get("type") == "file_list":
            for entry in value.get("files", []):
                if isinstance(entry, dict):
                    source_id = str(entry.get("source_id", ""))
                    if source_id:
                        file_ids.add(source_id)
        for nested in value.values():
            if isinstance(nested, (dict, list)):
                _collect_block_asset_ids(nested, media_ids, file_ids)
    elif isinstance(value, list):
        for nested in value:
            _collect_block_asset_ids(nested, media_ids, file_ids)


def recover_missing_wiki_structures(
    document: MigrationDocument,
    archive_path: str | Path,
    recovery_dir: str | Path,
    mediaobject_recovery: str | Path | None = None,
) -> dict[str, Any]:
    """Recover Wikis absent from the native ZIP from read-only current-page XML."""

    recovery_root = Path(recovery_dir).resolve()
    recovered_structures = 0
    recovered_pages = 0
    missing: list[dict[str, str]] = []

    with zipfile.ZipFile(str(archive_path)) as archive:
        page_parser = ContentPageParser(archive, "")

        for item in _walk(document.course.items):
            if item.type != "wiki":
                continue
            if isinstance(item.metadata.get("wiki_structure"), dict):
                continue

            object_id = str(item.metadata.get("obj_id", ""))
            wiki_root = recovery_root / f"wiki_{object_id}"
            manifest_path = wiki_root / "manifest.json"
            if not manifest_path.is_file():
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_manifest_missing",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            try:
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_manifest_invalid",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            wiki_manifest = manifest.get("wiki", {})
            course_manifest = manifest.get("course", {})
            if (
                str(wiki_manifest.get("object_id", "")) != object_id
                or str(wiki_manifest.get("ref_id", "")) != item.source_id
                or str(course_manifest.get("ref_id", ""))
                != str(document.course.source_id)
            ):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_identity_mismatch",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            history = manifest.get("history_policy", {})
            if (
                str(history.get("migration_policy", "")) != "current_pages_only"
                or bool(history.get("source_export_contains_history"))
                or bool(history.get("authors_migrated"))
            ):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_history_policy_invalid",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            page_entries = manifest.get("pages", [])
            if not isinstance(page_entries, list) or not page_entries:
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_pages_missing",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            title_to_id = {
                str(page.get("title", "")): str(page.get("source_page_id", ""))
                for page in page_entries
                if isinstance(page, dict)
                and str(page.get("title", ""))
                and str(page.get("source_page_id", ""))
            }

            raw_pages: list[dict[str, Any]] = []
            referenced_media: set[str] = set()
            referenced_files: set[str] = set()
            recovery_failed = False

            for page_entry in page_entries:
                if not isinstance(page_entry, dict):
                    recovery_failed = True
                    break

                page_id = str(page_entry.get("source_page_id", ""))
                title = str(page_entry.get("title", ""))
                relative = str(page_entry.get("xml_file", ""))
                page_file = _safe_recovery_file(wiki_root, relative)
                if not page_id or not title or page_file is None:
                    recovery_failed = True
                    break

                try:
                    expected_size = int(page_entry.get("size", -1))
                except (TypeError, ValueError):
                    expected_size = -1
                expected_sha = str(page_entry.get("sha256", "")).lower()
                if (
                    expected_size <= 0
                    or page_file.stat().st_size != expected_size
                    or not expected_sha
                    or _sha256_file(page_file) != expected_sha
                ):
                    recovery_failed = True
                    break

                try:
                    root = ET.fromstring(page_file.read_text(encoding="utf-8"))
                except (OSError, UnicodeDecodeError, ET.ParseError):
                    recovery_failed = True
                    break

                page_object = (
                    root
                    if _local_name(root.tag) == "PageObject"
                    else _first_descendant(root, "PageObject")
                )
                if page_object is None:
                    recovery_failed = True
                    break

                blocks = page_parser._parse_page_children(page_object, {}, {})
                _collect_block_asset_ids(
                    blocks,
                    referenced_media,
                    referenced_files,
                )
                raw_pages.append(
                    {
                        "entry": page_entry,
                        "page_object": page_object,
                    }
                )

            if recovery_failed:
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_page_integrity_error",
                        "source_path": str(wiki_root),
                    }
                )
                continue

            if referenced_files:
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_file_objects_unsupported",
                        "source_path": ",".join(sorted(referenced_files)),
                    }
                )
                continue

            media: dict[str, dict[str, Any]] = {}
            media_error = False
            for media_id in sorted(referenced_media, key=lambda value: int(value)):
                if mediaobject_recovery is None:
                    missing.append(
                        {
                            "source_id": item.source_id,
                            "kind": "wiki_media_recovery_required",
                            "source_path": media_id,
                        }
                    )
                    media_error = True
                    continue

                recovery, error = load_mediaobject_recovery_by_id(
                    mediaobject_recovery,
                    media_id,
                )
                if recovery is None:
                    missing.append(
                        {
                            "source_id": item.source_id,
                            "kind": error or "wiki_media_recovery_missing",
                            "source_path": media_id,
                        }
                    )
                    media_error = True
                    continue

                location = str(recovery["location"])
                mime_type = mimetypes.guess_type(location)[0] or ""
                media[media_id] = {
                    "source_id": media_id,
                    "title": location,
                    "description": "",
                    "media_container": "",
                    "items": [
                        {
                            "id": "",
                            "purpose": "Standard",
                            "location": location,
                            "location_type": "LocalFile",
                            "mime_type": mime_type,
                            "width": "",
                            "height": "",
                            "horizontal_align": "",
                            "caption": "",
                            "text_representation": "",
                            "archive_path": "",
                            "recovery_status": "RECOVERY_SOURCE",
                        }
                    ],
                }

            if media_error:
                continue

            pages: list[dict[str, Any]] = []
            unsupported: list[dict[str, str]] = []
            all_links: list[dict[str, str]] = []

            for raw_page in raw_pages:
                page_entry = raw_page["entry"]
                page_object = raw_page["page_object"]
                page_id = str(page_entry.get("source_page_id", ""))
                blocks = page_parser._parse_page_children(
                    page_object,
                    media,
                    {},
                )
                blocks = _rewrite_wiki_markup_links(blocks, title_to_id)
                page_unsupported = page_parser._collect_unsupported(blocks)
                links = _collect_internal_links(blocks, page_id)

                pages.append(
                    {
                        "source_id": page_id,
                        "wiki_id": object_id,
                        "title": str(page_entry.get("title", "")),
                        "blocked": "",
                        "rating": "",
                        "template_new_pages": "",
                        "template_add_to_page": "",
                        "language": str(page_entry.get("language", "")),
                        "import_id": "",
                        "important_page": None,
                        "content": {
                            "status": "ok",
                            "export_id": f"wpg:{page_id}",
                            "active": page_object.attrib.get("Active", ""),
                            "language": page_object.attrib.get(
                                "Language",
                                str(page_entry.get("language", "")),
                            ),
                            "blocks": blocks,
                            "unsupported_components": page_unsupported,
                        },
                        "internal_links": links,
                        "recovery_metadata": {
                            "created": page_entry.get("created"),
                            "create_user_id": page_entry.get("create_user_id"),
                            "last_change": page_entry.get("last_change"),
                            "last_change_user_id": page_entry.get(
                                "last_change_user_id"
                            ),
                        },
                    }
                )
                for component in page_unsupported:
                    unsupported.append(
                        {
                            "page_id": page_id,
                            "element": str(component.get("element", "")),
                        }
                    )
                all_links.extend(links)

            start_page = wiki_manifest.get("start_page", {})
            start_title = str(start_page.get("title", ""))
            start_id = str(start_page.get("source_id", ""))
            if (
                not start_title
                or not start_id
                or not any(
                    page["source_id"] == start_id
                    and page["title"] == start_title
                    for page in pages
                )
            ):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "wiki_recovery_start_page_invalid",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            structure = {
                "schema_version": "1.0",
                "source": {
                    "lms": "ILIAS",
                    "object_id": object_id,
                    "ref_id": item.source_id,
                    "export_base": "",
                    "recovery": "current_page_content",
                },
                "title": str(wiki_manifest.get("title", "")) or item.title,
                "description": "",
                "start_page": {
                    "title": start_title,
                    "source_id": start_id,
                },
                "settings": {
                    "short": "",
                    "introduction": "",
                    "rating": "",
                    "public_notes": "",
                    "page_toc": "",
                    "rating_side": "",
                    "rating_new": "",
                    "rating_ext": "",
                    "rating_overall": "",
                    "link_md_values": "",
                    "empty_page_template": "",
                },
                "pages": pages,
                "important_pages": [],
                "internal_links": all_links,
                "media": media,
                "files": {},
                "history": {
                    "source_export_contains_history": False,
                    "migration_policy": "current_pages_only",
                    "authors_migrated": False,
                    "note": (
                        "Le Wiki était absent de l'export natif ; les pages "
                        "courantes ont été récupérées en lecture seule."
                    ),
                },
                "export_components": [],
                "unsupported_components": unsupported,
                "recovery": {
                    "manifest": str(manifest_path),
                    "page_count": len(pages),
                    "media_object_ids": sorted(
                        referenced_media,
                        key=lambda value: int(value),
                    ),
                },
            }

            _attach_wiki_structure(item, structure)
            recovered_structures += 1
            recovered_pages += len(pages)

    return {
        "recovered": {
            "wiki_structures_recovered": recovered_structures,
            "wiki_pages_recovered": recovered_pages,
        },
        "missing": missing,
    }


def extract_wiki_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
    mediaobject_recovery: str | Path | None = None,
) -> dict[str, Any]:
    """Extract Wiki assets and persist one neutral structure per wiki."""

    output_dir.mkdir(parents=True, exist_ok=True)
    managed_root = output_dir / "wikis"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "wiki_structures": 0,
        "wiki_media_files": 0,
        "wiki_media_files_recovered": 0,
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
                        location = str(media_item.get("location", ""))
                        filename = Path(location or source_path).name
                        if not filename:
                            filename = f"media-{index}"

                        destination = PurePosixPath(
                            *wiki_root.parts,
                            "media",
                            str(media_id),
                            filename,
                        )

                        copied = False
                        recovered = False
                        recovery_error = None
                        if source_path:
                            copied = copy_member(source_path, destination)

                        if not copied and mediaobject_recovery is not None:
                            recovery, recovery_error = load_mediaobject_recovery(
                                mediaobject_recovery,
                                str(media_id),
                                location,
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

                        if copied:
                            media_item["migration_path"] = destination.as_posix()
                            if recovered:
                                media_item["recovery_status"] = "RECOVERED"
                                stats["wiki_media_files_recovered"] += 1
                            stats["wiki_media_files"] += 1
                        else:
                            missing.append(
                                {
                                    "source_id": item.source_id,
                                    "kind": recovery_error or "wiki_media",
                                    "source_path": source_path or str(media_id),
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
