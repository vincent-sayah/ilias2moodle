from __future__ import annotations

import hashlib
import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.media_pool import parse_media_pools
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


def enrich_document_media_pools(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    """Attach parsed Media Pool structures to mep items."""

    pools = parse_media_pools(str(archive_path))
    by_object_id = {
        str(pool.get("source", {}).get("object_id", "")): pool
        for pool in pools
        if str(pool.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0

    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "mep":
            continue

        item.type = "media_pool"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = by_object_id.get(object_id)

        if structure is None:
            item.metadata["media_pool_parse_error"] = (
                f"Aucune structure Media Pool trouvée pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = str(structure.get("title", "")) or item.title
        item.description = str(structure.get("description", ""))

        counts = structure.get("counts", {})
        unsupported = structure.get("unsupported_components", [])

        item.metadata.update(
            {
                "media_pool_schema_version": str(
                    structure.get("schema_version", "1.0")
                ),
                "media_pool_export_base": str(
                    structure.get("source", {}).get("export_base", "")
                ),
                "media_pool_tree_node_count": int(
                    counts.get("tree_nodes", 0)
                ),
                "media_pool_record_count": int(
                    counts.get("content_records", 0)
                ),
                "media_pool_folder_count": int(
                    counts.get("folders", 0)
                ),
                "media_pool_direct_media_count": int(
                    counts.get("direct_media", 0)
                ),
                "media_pool_all_media_count": int(
                    counts.get("all_media", 0)
                ),
                "media_pool_page_item_count": int(
                    counts.get("page_items", 0)
                ),
                "media_pool_unsupported_count": (
                    len(unsupported)
                    if isinstance(unsupported, list)
                    else 0
                ),
                "media_pool_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(pools),
        "matched": matched,
        "missing": missing,
    }


def extract_media_pool_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
    mediaobject_recovery: str | Path | None = None,
) -> dict[str, Any]:
    """Extract original Media Pool assets and normalized structures."""

    output_dir.mkdir(parents=True, exist_ok=True)
    managed_root = output_dir / "media_pools"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "media_pool_structures": 0,
        "media_pool_records": 0,
        "media_pool_media_files": 0,
        "media_pool_media_files_recovered": 0,
    }
    missing: list[dict[str, str]] = []

    with zipfile.ZipFile(str(archive_path)) as archive:
        members = {
            name.lstrip("/"): name
            for name in archive.namelist()
        }

        def copy_member(
            source_path: str,
            destination_relative: PurePosixPath,
        ) -> tuple[bool, int, str]:
            canonical = _safe_archive_relative(
                source_path
            ).as_posix()
            actual_name = members.get(canonical)
            if actual_name is None:
                return False, 0, ""

            destination = output_dir.joinpath(
                *destination_relative.parts
            )
            destination.parent.mkdir(
                parents=True,
                exist_ok=True,
            )

            digest = hashlib.sha256()
            size = 0

            with archive.open(actual_name) as src:
                with destination.open("wb") as dst:
                    while True:
                        chunk = src.read(1024 * 1024)
                        if not chunk:
                            break
                        dst.write(chunk)
                        digest.update(chunk)
                        size += len(chunk)

            return True, size, digest.hexdigest()

        for item in _walk(document.course.items):
            if item.type != "media_pool":
                continue

            structure = item.metadata.get(
                "media_pool_structure"
            )
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "media_pool_structure",
                        "source_path": str(
                            item.metadata.get(
                                "media_pool_export_base",
                                "",
                            )
                        ),
                    }
                )
                continue

            pool_root = PurePosixPath(
                "media_pools",
                item.source_id,
            )
            pool_files = 0

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

                        source_path = str(
                            media_item.get(
                                "archive_path",
                                "",
                            )
                        )
                        if not source_path:
                            continue

                        filename = Path(
                            str(
                                media_item.get(
                                    "location",
                                    "",
                                )
                            )
                            or source_path
                        ).name
                        if not filename:
                            filename = f"media-{index}"

                        destination = PurePosixPath(
                            *pool_root.parts,
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
                            media_item[
                                "migration_path"
                            ] = destination.as_posix()
                            media_item[
                                "migration_size"
                            ] = size
                            media_item[
                                "migration_sha256"
                            ] = sha256
                            if recovered:
                                media_item["recovery_status"] = "RECOVERED"
                                stats[
                                    "media_pool_media_files_recovered"
                                ] += 1
                            stats[
                                "media_pool_media_files"
                            ] += 1
                            pool_files += 1
                        else:
                            missing.append(
                                {
                                    "source_id": item.source_id,
                                    "kind": recovery_error or "media_pool_media",
                                    "source_path": source_path,
                                }
                            )

            records = structure.get("records", [])
            stats["media_pool_records"] += (
                len(records)
                if isinstance(records, list)
                else 0
            )

            structure_path = PurePosixPath(
                *pool_root.parts,
                "structure.json",
            )
            destination = output_dir.joinpath(
                *structure_path.parts
            )
            destination.parent.mkdir(
                parents=True,
                exist_ok=True,
            )
            destination.write_text(
                json.dumps(
                    structure,
                    ensure_ascii=False,
                    indent=2,
                ),
                encoding="utf-8",
            )

            item.metadata[
                "migration_structure_path"
            ] = structure_path.as_posix()
            item.metadata[
                "migration_media_file_count"
            ] = pool_files
            item.metadata.pop(
                "media_pool_structure",
                None,
            )
            stats["media_pool_structures"] += 1

    return {
        "managed_directory": "media_pools",
        "extracted": stats,
        "missing": missing,
    }
