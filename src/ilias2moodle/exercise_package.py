from __future__ import annotations

import hashlib
import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.exercise import parse_exercises
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


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def recover_exercise_instruction_files(
    document: MigrationDocument,
    recovery_dir: str | Path,
) -> dict[str, Any]:
    recovery_root = Path(recovery_dir).resolve()

    stats = {
        "collections_recovered": 0,
        "instruction_files_recovered": 0,
    }
    missing: list[dict[str, str]] = []

    for item in _walk(document.course.items):
        if item.type != "exercise":
            continue

        structure = item.metadata.get("exercise_structure")
        if not isinstance(structure, dict):
            continue

        assignments = structure.get("assignments", [])
        recovered_assignment_ids: set[str] = set()

        for assignment in assignments if isinstance(assignments, list) else []:
            if not isinstance(assignment, dict):
                continue

            if (
                assignment.get("instruction_collection_kind")
                != "resource_collection_uuid"
            ):
                continue

            assignment_id = str(assignment.get("source_id", ""))
            collection_uuid = str(
                assignment.get("instruction_collection", "")
            )

            manifest_path = (
                recovery_root
                / collection_uuid
                / "manifest.json"
            )

            if not manifest_path.is_file():
                missing.append(
                    {
                        "source_id": item.source_id,
                        "assignment_id": assignment_id,
                        "kind": "exercise_irss_manifest",
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
                        "assignment_id": assignment_id,
                        "kind": "exercise_irss_manifest_invalid",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            if (
                str(manifest.get("collection_uuid", ""))
                != collection_uuid
            ):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "assignment_id": assignment_id,
                        "kind": "exercise_irss_collection_mismatch",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            manifest_files = manifest.get("files")
            if not isinstance(manifest_files, list):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "assignment_id": assignment_id,
                        "kind": "exercise_irss_manifest_files_invalid",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            try:
                resource_count = int(
                    manifest.get("resource_count", -1)
                )
            except (TypeError, ValueError):
                resource_count = -1

            if (
                resource_count < 0
                or resource_count != len(manifest_files)
            ):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "assignment_id": assignment_id,
                        "kind": "exercise_irss_resource_count_mismatch",
                        "source_path": str(manifest_path),
                    }
                )
                continue

            recovered_files: list[dict[str, Any]] = []
            collection_errors: list[dict[str, str]] = []

            for manifest_file in manifest_files:
                if not isinstance(manifest_file, dict):
                    collection_errors.append(
                        {
                            "source_id": item.source_id,
                            "assignment_id": assignment_id,
                            "kind": "exercise_irss_file_metadata_invalid",
                            "source_path": str(manifest_path),
                        }
                    )
                    continue

                if str(manifest_file.get("status", "")) != "OK":
                    collection_errors.append(
                        {
                            "source_id": item.source_id,
                            "assignment_id": assignment_id,
                            "kind": "exercise_irss_resource_not_ok",
                            "source_path": str(manifest_path),
                        }
                    )
                    continue

                output_name = str(
                    manifest_file.get("output_name", "")
                )

                relative = PurePosixPath(output_name)

                if (
                    not output_name
                    or relative.is_absolute()
                    or len(relative.parts) != 1
                    or ".." in relative.parts
                ):
                    collection_errors.append(
                        {
                            "source_id": item.source_id,
                            "assignment_id": assignment_id,
                            "kind": "exercise_irss_filename_unsafe",
                            "source_path": output_name,
                        }
                    )
                    continue

                recovered_path = (
                    manifest_path.parent / output_name
                )

                if recovered_path.is_symlink():
                    collection_errors.append(
                        {
                            "source_id": item.source_id,
                            "assignment_id": assignment_id,
                            "kind": "exercise_irss_symlink_rejected",
                            "source_path": str(recovered_path),
                        }
                    )
                    continue

                if not recovered_path.is_file():
                    collection_errors.append(
                        {
                            "source_id": item.source_id,
                            "assignment_id": assignment_id,
                            "kind": "exercise_irss_file_missing",
                            "source_path": str(recovered_path),
                        }
                    )
                    continue

                try:
                    expected_size = int(
                        manifest_file.get("written_size", -1)
                    )
                except (TypeError, ValueError):
                    expected_size = -1

                expected_sha256 = str(
                    manifest_file.get("sha256", "")
                ).lower()

                actual_size = recovered_path.stat().st_size
                actual_sha256 = _sha256_file(recovered_path)

                if (
                    expected_size < 0
                    or not expected_sha256
                    or actual_size != expected_size
                    or actual_sha256 != expected_sha256
                ):
                    collection_errors.append(
                        {
                            "source_id": item.source_id,
                            "assignment_id": assignment_id,
                            "kind": "exercise_irss_integrity_error",
                            "source_path": str(recovered_path),
                        }
                    )
                    continue

                recovered_files.append(
                    {
                        "filename": output_name,
                        "original_name": str(
                            manifest_file.get(
                                "original_name",
                                output_name,
                            )
                        ),
                        "relative_path": output_name,
                        "recovery_path": str(recovered_path),
                        "resource_id": str(
                            manifest_file.get("resource_id", "")
                        ),
                        "mime_type": str(
                            manifest_file.get("mime_type", "")
                        ),
                        "size": actual_size,
                        "sha256": actual_sha256,
                        "source": "ilias_irss",
                        "collection_uuid": collection_uuid,
                    }
                )

            if collection_errors:
                missing.extend(collection_errors)
                continue

            assignment["instruction_files"] = recovered_files
            assignment["instruction_files_recovered"] = True
            assignment["export_status"] = (
                "EXPORT_INCOMPLETE_RS_COLLECTION"
            )
            assignment["recovery_status"] = "RECOVERED"
            assignment["instruction_recovery"] = {
                "source": "ilias_irss",
                "collection_uuid": collection_uuid,
                "manifest": f"{collection_uuid}/manifest.json",
                "status": "RECOVERED",
            }

            constraints = [
                str(value)
                for value in assignment.get(
                    "migration_constraints",
                    [],
                )
                if str(value)
                != "instruction_collection_not_embedded"
            ]

            assignment["migration_constraints"] = constraints

            support = str(
                assignment.get("type", {}).get(
                    "migration_support",
                    "unsupported",
                )
            )

            assignment["automatic_ready"] = (
                not constraints and support == "supported"
            )

            recovered_assignment_ids.add(assignment_id)
            stats["collections_recovered"] += 1
            stats["instruction_files_recovered"] += len(
                recovered_files
            )

        if recovered_assignment_ids:
            structure["blocking_features"] = [
                feature
                for feature in structure.get(
                    "blocking_features",
                    [],
                )
                if not (
                    isinstance(feature, dict)
                    and str(
                        feature.get("assignment_id", "")
                    )
                    in recovered_assignment_ids
                    and feature.get("feature")
                    == "instruction_collection_not_embedded"
                )
            ]

            structure["export_issues"] = [
                feature
                for feature in structure.get(
                    "export_issues",
                    [],
                )
                if not (
                    isinstance(feature, dict)
                    and str(
                        feature.get("assignment_id", "")
                    )
                    in recovered_assignment_ids
                    and feature.get("feature")
                    == "instruction_collection_not_embedded"
                )
            ]

        item.metadata[
            "exercise_instruction_file_count"
        ] = sum(
            len(
                assignment.get(
                    "instruction_files",
                    [],
                )
            )
            for assignment in structure.get(
                "assignments",
                [],
            )
            if isinstance(assignment, dict)
        )

        blocking = structure.get(
            "blocking_features",
            [],
        )

        item.metadata[
            "exercise_blocking_feature_count"
        ] = (
            len(blocking)
            if isinstance(blocking, list)
            else 0
        )

    return {
        "recovered": stats,
        "missing": missing,
    }


def enrich_document_exercises(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    exercises = parse_exercises(str(archive_path))
    by_object_id = {
        str(exercise.get("source", {}).get("object_id", "")): exercise
        for exercise in exercises
        if str(exercise.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0
    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "exc":
            continue

        item.type = "exercise"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = by_object_id.get(object_id)
        if structure is None:
            item.metadata["exercise_parse_error"] = (
                f"Aucune structure Exercise trouvée pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = str(structure.get("title", "")) or item.title
        item.description = str(structure.get("description", ""))

        assignments = structure.get("assignments", [])
        blocking = structure.get("blocking_features", [])
        instruction_files = sum(
            len(a.get("instruction_files", []))
            for a in assignments
            if isinstance(a, dict)
        )
        item.metadata.update(
            {
                "exercise_schema_version": str(structure.get("schema_version", "1.0")),
                "exercise_export_base": str(
                    structure.get("source", {}).get("export_base", "")
                ),
                "exercise_assignment_count": len(assignments),
                "exercise_instruction_file_count": instruction_files,
                "exercise_blocking_feature_count": (
                    len(blocking) if isinstance(blocking, list) else 0
                ),
                "exercise_phase7_user_data_deferred": True,
                "exercise_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(exercises),
        "matched": matched,
        "missing": missing,
    }


def extract_exercise_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
) -> dict[str, Any]:
    output_dir.mkdir(parents=True, exist_ok=True)
    managed_root = output_dir / "exercises"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "exercise_structures": 0,
        "exercise_instruction_files": 0,
        "exercise_irss_instruction_files": 0,
    }
    missing: list[dict[str, str]] = []

    with zipfile.ZipFile(str(archive_path)) as archive:
        members = {name.lstrip("/"): name for name in archive.namelist()}

        def copy_member(source_path: str, destination_relative: PurePosixPath) -> bool:
            canonical = _safe_archive_relative(source_path).as_posix()
            actual = members.get(canonical)
            if actual is None:
                return False
            destination = output_dir.joinpath(*destination_relative.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(actual) as src, destination.open("wb") as dst:
                shutil.copyfileobj(src, dst)
            return True

        for item in _walk(document.course.items):
            if item.type != "exercise":
                continue

            structure = item.metadata.get("exercise_structure")
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "exercise_structure",
                        "source_path": str(item.metadata.get("exercise_export_base", "")),
                    }
                )
                continue

            exercise_root = PurePosixPath("exercises", item.source_id)
            assignments = structure.get("assignments", [])
            for assignment in assignments if isinstance(assignments, list) else []:
                if not isinstance(assignment, dict):
                    continue
                assignment_id = str(assignment.get("source_id", ""))
                for file_item in assignment.get("instruction_files", []):
                    if not isinstance(file_item, dict):
                        continue
                    archive_source = str(
                        file_item.get("archive_path", "")
                    )
                    recovery_source = str(
                        file_item.get("recovery_path", "")
                    )

                    if not archive_source and not recovery_source:
                        continue

                    rel = PurePosixPath(
                        str(file_item.get("relative_path", ""))
                    )
                    if not rel.parts:
                        rel = PurePosixPath(
                            str(file_item.get("filename", ""))
                        )

                    destination = PurePosixPath(
                        *exercise_root.parts,
                        "assignments",
                        assignment_id,
                        "instructions",
                        *rel.parts,
                    )

                    copied = False
                    source_path = (
                        recovery_source or archive_source
                    )

                    if recovery_source:
                        recovered_file = Path(
                            recovery_source
                        )
                        if recovered_file.is_file():
                            destination_path = (
                                output_dir.joinpath(
                                    *destination.parts
                                )
                            )
                            destination_path.parent.mkdir(
                                parents=True,
                                exist_ok=True,
                            )
                            shutil.copyfile(
                                recovered_file,
                                destination_path,
                            )
                            copied = True
                    else:
                        copied = copy_member(
                            archive_source,
                            destination,
                        )

                    if copied:
                        file_item[
                            "migration_path"
                        ] = destination.as_posix()

                        stats[
                            "exercise_instruction_files"
                        ] += 1

                        if recovery_source:
                            stats[
                                "exercise_irss_instruction_files"
                            ] += 1
                            file_item.pop(
                                "recovery_path",
                                None,
                            )
                    else:
                        missing.append(
                            {
                                "source_id": item.source_id,
                                "assignment_id": assignment_id,
                                "kind": "exercise_instruction_file",
                                "source_path": source_path,
                            }
                        )

            structure_path = PurePosixPath(*exercise_root.parts, "structure.json")
            destination = output_dir.joinpath(*structure_path.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_text(
                json.dumps(structure, ensure_ascii=False, indent=2),
                encoding="utf-8",
            )

            item.metadata["migration_structure_path"] = structure_path.as_posix()
            item.metadata["migration_instruction_file_count"] = sum(
                1
                for assignment in structure.get("assignments", [])
                if isinstance(assignment, dict)
                for file_item in assignment.get("instruction_files", [])
                if isinstance(file_item, dict) and file_item.get("migration_path")
            )
            item.metadata.pop("exercise_structure", None)
            stats["exercise_structures"] += 1

    return {
        "managed_directory": "exercises",
        "extracted": stats,
        "missing": missing,
    }
