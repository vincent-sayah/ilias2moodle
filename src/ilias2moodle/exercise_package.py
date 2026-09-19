from __future__ import annotations

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
                    source_path = str(file_item.get("archive_path", ""))
                    if not source_path:
                        continue
                    rel = PurePosixPath(str(file_item.get("relative_path", "")))
                    if not rel.parts:
                        rel = PurePosixPath(str(file_item.get("filename", "")))
                    destination = PurePosixPath(
                        *exercise_root.parts,
                        "assignments",
                        assignment_id,
                        "instructions",
                        *rel.parts,
                    )
                    if copy_member(source_path, destination):
                        file_item["migration_path"] = destination.as_posix()
                        stats["exercise_instruction_files"] += 1
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
