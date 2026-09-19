from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.exercise_package import extract_exercise_assets
from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


def test_exercise_package_extracts_instruction_files(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    source = (
        "set_32/1800000000__0__exc_910/components/ILIAS/Exercise/"
        "set_0/dsDir_1/consigne.pdf"
    )
    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(source, b"exercise-file")

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "910",
            "ref_id": "274",
            "export_base": "set_32/1800000000__0__exc_910",
        },
        "title": "Exercice POC",
        "description": "",
        "assignments": [
            {
                "source_id": "21",
                "title": "Dépôt fichier",
                "instruction_files": [
                    {
                        "filename": "consigne.pdf",
                        "relative_path": "consigne.pdf",
                        "archive_path": source,
                    }
                ],
            }
        ],
        "blocking_features": [],
    }

    item = MigrationItem(
        source_id="274",
        type="exercise",
        title="Exercice POC",
        metadata={
            "ilias_type": "exc",
            "obj_id": "910",
            "exercise_structure": structure,
            "exercise_export_base": structure["source"]["export_base"],
        },
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="cours test migration", items=[item])
    )

    output = tmp_path / "package"
    result = extract_exercise_assets(document, archive_path, output)

    assert result["managed_directory"] == "exercises"
    assert result["extracted"]["exercise_structures"] == 1
    assert result["extracted"]["exercise_instruction_files"] == 1
    assert result["missing"] == []

    target = output / "exercises/274/assignments/21/instructions/consigne.pdf"
    assert target.read_bytes() == b"exercise-file"

    saved = json.loads(
        (output / "exercises/274/structure.json").read_text(encoding="utf-8")
    )
    assert (
        saved["assignments"][0]["instruction_files"][0]["migration_path"]
        == "exercises/274/assignments/21/instructions/consigne.pdf"
    )
    assert item.metadata["migration_structure_path"] == "exercises/274/structure.json"
    assert item.metadata["migration_instruction_file_count"] == 1
    assert "exercise_structure" not in item.metadata
