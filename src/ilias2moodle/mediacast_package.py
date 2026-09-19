from __future__ import annotations

import hashlib
import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.mediacast import parse_mediacasts
from ilias2moodle.model import MigrationDocument, MigrationItem


def _walk(
    items: Iterable[MigrationItem],
) -> Iterable[MigrationItem]:
    for item in items:
        yield item
        yield from _walk(item.items)


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _safe_archive_relative(path: str) -> PurePosixPath:
    candidate = PurePosixPath(path.lstrip("/"))
    if not candidate.parts or ".." in candidate.parts:
        raise ValueError(f"Chemin d'archive non sûr : {path}")
    return candidate


def enrich_document_mediacasts(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    mediacasts = parse_mediacasts(str(archive_path))
    by_object_id = {
        str(value.get("source", {}).get("object_id", "")): value
        for value in mediacasts
        if str(value.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0

    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "mcst":
            continue

        item.type = "mediacast"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = by_object_id.get(object_id)

        if structure is None:
            item.metadata["mediacast_parse_error"] = (
                "Aucune structure MediaCast trouvée "
                f"pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = str(structure.get("title", "")) or item.title
        item.description = str(structure.get("description", ""))

        item.metadata.update(
            {
                "mediacast_schema_version": str(
                    structure.get("schema_version", "1.0")
                ),
                "mediacast_export_base": str(
                    structure.get("source", {}).get(
                        "export_base", ""
                    )
                ),
                "mediacast_entry_count": int(
                    structure.get("entry_count", 0)
                ),
                "mediacast_local_file_count": int(
                    structure.get("local_file_count", 0)
                ),
                "mediacast_external_url_count": int(
                    structure.get("external_url_count", 0)
                ),
                "mediacast_unsupported_entry_count": int(
                    structure.get("unsupported_entry_count", 0)
                ),
                "mediacast_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(mediacasts),
        "matched": matched,
        "missing": missing,
    }


def recover_mediacast_local_files(
    document: MigrationDocument,
    recovery_dir: str | Path,
) -> dict[str, Any]:
    recovery_root = Path(recovery_dir).resolve()
    recovered = 0
    missing: list[dict[str, str]] = []

    for item in _walk(document.course.items):
        if item.type != "mediacast":
            continue

        structure = item.metadata.get("mediacast_structure")
        if not isinstance(structure, dict):
            continue

        for entry in structure.get("entries", []):
            if not isinstance(entry, dict):
                continue
            if entry.get("source_kind") != "local_file":
                continue
            if bool(entry.get("embedded")):
                continue

            mob_id = str(entry.get("mob_id", ""))
            location = str(entry.get("location", ""))
            entry_id = str(entry.get("source_id", ""))

            manifest_path = (
                recovery_root / f"mob_{mob_id}" / "manifest.json"
            )

            if not manifest_path.is_file():
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_manifest",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            try:
                manifest = json.loads(
                    manifest_path.read_text(encoding="utf-8")
                )
            except (OSError, json.JSONDecodeError):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_manifest_invalid",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            if str(manifest.get("mob_id", "")) != mob_id:
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_mob_mismatch",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            if str(manifest.get("location", "")) != location:
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_location_mismatch",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            status = str(manifest.get("status", ""))
            if status not in {"OK", "OK_SIZE_UNAVAILABLE"}:
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_not_ok",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            output_name = str(manifest.get("output_name", ""))
            relative = PurePosixPath(output_name)
            if (
                not output_name
                or relative.is_absolute()
                or len(relative.parts) != 1
                or ".." in relative.parts
            ):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_filename_unsafe",
                        "source_path": output_name,
                    }
                )
                continue

            recovered_path = manifest_path.parent / output_name

            if recovered_path.is_symlink() or not recovered_path.is_file():
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_file_missing",
                        "source_path": str(recovered_path),
                    }
                )
                continue

            try:
                expected_size = int(manifest.get("size", -1))
            except (TypeError, ValueError):
                expected_size = -1

            expected_sha256 = str(
                manifest.get("sha256", "")
            ).lower()
            actual_size = recovered_path.stat().st_size
            actual_sha256 = _sha256_file(recovered_path)

            copy_result_raw = manifest.get("stream_copy_result")
            try:
                copy_result = int(copy_result_raw)
            except (TypeError, ValueError):
                copy_result = -1

            stream_size_valid = (
                status != "OK_SIZE_UNAVAILABLE"
                or copy_result == actual_size
            )

            if (
                expected_size <= 0
                or not expected_sha256
                or actual_size != expected_size
                or actual_sha256 != expected_sha256
                or not stream_size_valid
            ):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "entry_id": entry_id,
                        "mob_id": mob_id,
                        "kind": "mediacast_media_integrity_error",
                        "source_path": str(recovered_path),
                    }
                )
                continue

            entry["recovery_path"] = str(recovered_path)
            entry["recovery_status"] = "RECOVERED"
            entry["recovered_size"] = actual_size
            entry["recovered_sha256"] = actual_sha256
            entry["needs_recovery"] = False
            recovered += 1

        structure["missing_assets"] = [
            value
            for value in structure.get("missing_assets", [])
            if not (
                isinstance(value, dict)
                and any(
                    isinstance(entry, dict)
                    and entry.get("recovery_status") == "RECOVERED"
                    and str(value.get("entry_id", ""))
                    == str(entry.get("source_id", ""))
                    for entry in structure.get("entries", [])
                )
            )
        ]

    return {
        "recovered": {
            "local_media_files_recovered": recovered,
        },
        "missing": missing,
    }


def extract_mediacast_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
) -> dict[str, Any]:
    output_dir.mkdir(parents=True, exist_ok=True)

    managed_root = output_dir / "mediacasts"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "mediacast_structures": 0,
        "mediacast_local_media_files": 0,
        "mediacast_preview_files": 0,
        "mediacast_external_urls": 0,
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
        ) -> bool:
            canonical = _safe_archive_relative(
                source_path
            ).as_posix()
            actual_name = members.get(canonical)
            if actual_name is None:
                return False

            destination = output_dir.joinpath(
                *destination_relative.parts
            )
            destination.parent.mkdir(
                parents=True,
                exist_ok=True,
            )
            with archive.open(actual_name) as src:
                with destination.open("wb") as dst:
                    shutil.copyfileobj(src, dst)
            return True

        for item in _walk(document.course.items):
            if item.type != "mediacast":
                continue

            structure = item.metadata.get("mediacast_structure")
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "mediacast_structure",
                        "source_path": str(
                            item.metadata.get(
                                "mediacast_export_base",
                                "",
                            )
                        ),
                    }
                )
                continue

            root = PurePosixPath(
                "mediacasts",
                item.source_id,
            )

            for entry in structure.get("entries", []):
                if not isinstance(entry, dict):
                    continue

                entry_id = str(
                    entry.get("source_id", "")
                ) or "unknown"

                for index, preview in enumerate(
                    entry.get("preview_assets", []),
                    start=1,
                ):
                    if not isinstance(preview, dict):
                        continue
                    source_path = str(
                        preview.get("archive_path", "")
                    )
                    if not source_path:
                        continue
                    filename = (
                        Path(
                            str(
                                preview.get(
                                    "filename",
                                    "",
                                )
                            )
                            or source_path
                        ).name
                        or f"preview-{index}"
                    )
                    destination = PurePosixPath(
                        *root.parts,
                        "previews",
                        entry_id,
                        filename,
                    )
                    if copy_member(source_path, destination):
                        preview["migration_path"] = (
                            destination.as_posix()
                        )
                        stats["mediacast_preview_files"] += 1

                if entry.get("source_kind") == "external_url":
                    stats["mediacast_external_urls"] += 1
                    continue

                if entry.get("source_kind") != "local_file":
                    continue

                filename = (
                    Path(
                        str(entry.get("location", ""))
                    ).name
                    or f"media-{entry_id}"
                )
                destination = PurePosixPath(
                    *root.parts,
                    "media",
                    entry_id,
                    filename,
                )
                destination_path = output_dir.joinpath(
                    *destination.parts
                )
                destination_path.parent.mkdir(
                    parents=True,
                    exist_ok=True,
                )

                copied = False
                if bool(entry.get("embedded")):
                    source_path = str(
                        entry.get("archive_path", "")
                    )
                    if source_path:
                        copied = copy_member(
                            source_path,
                            destination,
                        )
                elif entry.get("recovery_status") == "RECOVERED":
                    recovery_path = Path(
                        str(entry.get("recovery_path", ""))
                    )
                    if recovery_path.is_file():
                        shutil.copy2(
                            recovery_path,
                            destination_path,
                        )
                        copied = True

                if copied:
                    entry["migration_path"] = (
                        destination.as_posix()
                    )
                    entry["migration_size"] = (
                        destination_path.stat().st_size
                    )
                    entry["migration_sha256"] = (
                        _sha256_file(destination_path)
                    )
                    stats["mediacast_local_media_files"] += 1
                else:
                    missing.append(
                        {
                            "source_id": item.source_id,
                            "entry_id": entry_id,
                            "mob_id": str(
                                entry.get("mob_id", "")
                            ),
                            "kind": "mediacast_local_media",
                            "source_path": str(
                                entry.get("location", "")
                            ),
                        }
                    )

            structure_path = PurePosixPath(
                *root.parts,
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

            item.metadata["migration_structure_path"] = (
                structure_path.as_posix()
            )
            item.metadata[
                "migration_mediacast_local_media_file_count"
            ] = sum(
                1
                for entry in structure.get("entries", [])
                if isinstance(entry, dict)
                and entry.get("migration_path")
            )
            item.metadata[
                "migration_mediacast_external_url_count"
            ] = sum(
                1
                for entry in structure.get("entries", [])
                if isinstance(entry, dict)
                and entry.get("source_kind") == "external_url"
            )
            item.metadata.pop("mediacast_structure", None)
            stats["mediacast_structures"] += 1

    return {
        "managed_directory": "mediacasts",
        "extracted": stats,
        "missing": missing,
    }
