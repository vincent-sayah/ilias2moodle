from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.exercise_package import (
    extract_exercise_assets,
    recover_exercise_instruction_files,
)
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



def test_exercise_package_recovers_irss_instruction_files(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "course.zip"

    with zipfile.ZipFile(archive_path, "w"):
        pass

    uuid = "91b53716-8ec4-45ad-ada7-0f714c76901f"
    payload = b"irss-document"

    recovery_root = tmp_path / "irss"
    collection_root = recovery_root / uuid
    collection_root.mkdir(parents=True)

    filename = "consigne.docx"
    recovered_file = collection_root / filename
    recovered_file.write_bytes(payload)

    import hashlib

    sha256 = hashlib.sha256(payload).hexdigest()

    (collection_root / "manifest.json").write_text(
        json.dumps(
            {
                "collection_uuid": uuid,
                "client_id": "ilias10",
                "files": [
                    {
                        "resource_id": (
                            "35117503-5f86-411f-9e7d-579d06061c19"
                        ),
                        "original_name": filename,
                        "output_name": filename,
                        "mime_type": (
                            "application/vnd.openxmlformats-"
                            "officedocument.wordprocessingml.document"
                        ),
                        "expected_size": len(payload),
                        "written_size": len(payload),
                        "sha256": sha256,
                        "status": "OK",
                    }
                ],
                "resource_count": 1,
            }
        ),
        encoding="utf-8",
    )

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "806",
            "ref_id": "274",
            "export_base": (
                "set_31/1789807355__0__exc_806"
            ),
        },
        "title": "Exercice POC",
        "description": "",
        "assignments": [
            {
                "source_id": "1",
                "title": "tache1",
                "instruction_collection": uuid,
                "instruction_collection_kind": (
                    "resource_collection_uuid"
                ),
                "instruction_files": [],
                "instruction_files_embedded": False,
                "type": {
                    "key": "file_upload",
                    "migration_support": "supported",
                },
                "automatic_ready": False,
                "migration_constraints": [
                    "instruction_collection_not_embedded"
                ],
                "phase7_dependencies": [],
            }
        ],
        "blocking_features": [
            {
                "assignment_id": "1",
                "feature": (
                    "instruction_collection_not_embedded"
                ),
            }
        ],
        "export_issues": [
            {
                "assignment_id": "1",
                "feature": (
                    "instruction_collection_not_embedded"
                ),
            }
        ],
    }

    item = MigrationItem(
        source_id="274",
        type="exercise",
        title="Exercice POC",
        metadata={
            "ilias_type": "exc",
            "obj_id": "806",
            "exercise_structure": structure,
            "exercise_export_base": (
                structure["source"]["export_base"]
            ),
        },
    )

    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    recovery = recover_exercise_instruction_files(
        document,
        recovery_root,
    )

    assert recovery["missing"] == []
    assert (
        recovery["recovered"]["collections_recovered"]
        == 1
    )
    assert (
        recovery["recovered"][
            "instruction_files_recovered"
        ]
        == 1
    )

    assignment = structure["assignments"][0]

    assert assignment["recovery_status"] == "RECOVERED"
    assert assignment["automatic_ready"] is True
    assert assignment["migration_constraints"] == []
    assert len(assignment["instruction_files"]) == 1
    assert (
        assignment["instruction_files"][0]["sha256"]
        == sha256
    )

    assert structure["blocking_features"] == []
    assert structure["export_issues"] == []

    output = tmp_path / "package"

    result = extract_exercise_assets(
        document,
        archive_path,
        output,
    )

    target = (
        output
        / "exercises/274/assignments/1/instructions"
        / filename
    )

    assert target.read_bytes() == payload

    assert (
        result["extracted"][
            "exercise_irss_instruction_files"
        ]
        == 1
    )

    saved = json.loads(
        (
            output
            / "exercises/274/structure.json"
        ).read_text(encoding="utf-8")
    )

    saved_file = (
        saved["assignments"][0]
        ["instruction_files"][0]
    )

    assert "recovery_path" not in saved_file
    assert saved_file["source"] == "ilias_irss"


def test_exercise_irss_missing_manifest_keeps_assignment_blocked(
    tmp_path: Path,
) -> None:
    uuid = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "806",
            "ref_id": "274",
            "export_base": "set_31/example__0__exc_806",
        },
        "title": "Exercise IRSS",
        "description": "",
        "assignments": [
            {
                "source_id": "1",
                "title": "Tâche sans manifest",
                "instruction_collection": uuid,
                "instruction_collection_kind": (
                    "resource_collection_uuid"
                ),
                "instruction_files": [],
                "instruction_files_embedded": False,
                "type": {
                    "key": "file_upload",
                    "migration_support": "supported",
                },
                "automatic_ready": False,
                "migration_constraints": [
                    "instruction_collection_not_embedded"
                ],
                "phase7_dependencies": [],
            }
        ],
        "blocking_features": [
            {
                "assignment_id": "1",
                "feature": (
                    "instruction_collection_not_embedded"
                ),
            }
        ],
        "export_issues": [
            {
                "assignment_id": "1",
                "feature": (
                    "instruction_collection_not_embedded"
                ),
            }
        ],
    }

    item = MigrationItem(
        source_id="274",
        type="exercise",
        title="Exercise IRSS",
        metadata={
            "ilias_type": "exc",
            "obj_id": "806",
            "exercise_structure": structure,
        },
    )

    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    recovery_root = tmp_path / "irss"
    recovery_root.mkdir()

    result = recover_exercise_instruction_files(
        document,
        recovery_root,
    )

    assert result["recovered"]["collections_recovered"] == 0
    assert result["recovered"]["instruction_files_recovered"] == 0

    assert len(result["missing"]) == 1
    assert (
        result["missing"][0]["kind"]
        == "exercise_irss_manifest"
    )

    assignment = structure["assignments"][0]

    assert assignment["automatic_ready"] is False
    assert assignment["instruction_files"] == []
    assert assignment["migration_constraints"] == [
        "instruction_collection_not_embedded"
    ]

    assert len(structure["blocking_features"]) == 1
    assert len(structure["export_issues"]) == 1


def test_exercise_irss_invalid_sha256_keeps_assignment_blocked(
    tmp_path: Path,
) -> None:
    import hashlib

    uuid = "11111111-2222-3333-4444-555555555555"

    recovery_root = tmp_path / "irss"
    collection_root = recovery_root / uuid
    collection_root.mkdir(parents=True)

    payload = b"document-original"
    filename = "consigne.pdf"

    recovered_file = collection_root / filename
    recovered_file.write_bytes(payload)

    # SHA volontairement faux.
    wrong_sha256 = hashlib.sha256(
        b"autre-contenu"
    ).hexdigest()

    (collection_root / "manifest.json").write_text(
        json.dumps(
            {
                "collection_uuid": uuid,
                "client_id": "ilias10",
                "files": [
                    {
                        "resource_id": (
                            "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"
                        ),
                        "original_name": filename,
                        "output_name": filename,
                        "mime_type": "application/pdf",
                        "expected_size": len(payload),
                        "written_size": len(payload),
                        "sha256": wrong_sha256,
                        "status": "OK",
                    }
                ],
                "resource_count": 1,
            }
        ),
        encoding="utf-8",
    )

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "806",
            "ref_id": "274",
            "export_base": "set_31/example__0__exc_806",
        },
        "title": "Exercise IRSS",
        "description": "",
        "assignments": [
            {
                "source_id": "1",
                "title": "Tâche SHA invalide",
                "instruction_collection": uuid,
                "instruction_collection_kind": (
                    "resource_collection_uuid"
                ),
                "instruction_files": [],
                "instruction_files_embedded": False,
                "type": {
                    "key": "file_upload",
                    "migration_support": "supported",
                },
                "automatic_ready": False,
                "migration_constraints": [
                    "instruction_collection_not_embedded"
                ],
                "phase7_dependencies": [],
            }
        ],
        "blocking_features": [
            {
                "assignment_id": "1",
                "feature": (
                    "instruction_collection_not_embedded"
                ),
            }
        ],
        "export_issues": [
            {
                "assignment_id": "1",
                "feature": (
                    "instruction_collection_not_embedded"
                ),
            }
        ],
    }

    item = MigrationItem(
        source_id="274",
        type="exercise",
        title="Exercise IRSS",
        metadata={
            "ilias_type": "exc",
            "obj_id": "806",
            "exercise_structure": structure,
        },
    )

    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    result = recover_exercise_instruction_files(
        document,
        recovery_root,
    )

    assert result["recovered"]["collections_recovered"] == 0
    assert result["recovered"]["instruction_files_recovered"] == 0

    assert len(result["missing"]) == 1
    assert (
        result["missing"][0]["kind"]
        == "exercise_irss_integrity_error"
    )

    assignment = structure["assignments"][0]

    assert assignment["automatic_ready"] is False
    assert assignment["instruction_files"] == []
    assert assignment["migration_constraints"] == [
        "instruction_collection_not_embedded"
    ]

    assert len(structure["blocking_features"]) == 1
    assert len(structure["export_issues"]) == 1



def test_exercise_irss_resource_count_mismatch_is_blocked(
    tmp_path: Path,
) -> None:
    uuid = "22222222-3333-4444-5555-666666666666"

    recovery_root = tmp_path / "irss"
    collection_root = recovery_root / uuid
    collection_root.mkdir(parents=True)

    (collection_root / "manifest.json").write_text(
        json.dumps(
            {
                "collection_uuid": uuid,
                "client_id": "ilias10",
                "files": [],
                "resource_count": 1,
            }
        ),
        encoding="utf-8",
    )

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "806",
            "ref_id": "274",
            "export_base": "set_31/example__0__exc_806",
        },
        "title": "Exercise IRSS",
        "description": "",
        "assignments": [
            {
                "source_id": "1",
                "title": "Collection incomplète",
                "instruction_collection": uuid,
                "instruction_collection_kind": (
                    "resource_collection_uuid"
                ),
                "instruction_files": [],
                "instruction_files_embedded": False,
                "type": {
                    "key": "file_upload",
                    "migration_support": "supported",
                },
                "automatic_ready": False,
                "migration_constraints": [
                    "instruction_collection_not_embedded"
                ],
                "phase7_dependencies": [],
            }
        ],
        "blocking_features": [
            {
                "assignment_id": "1",
                "feature": "instruction_collection_not_embedded",
            }
        ],
        "export_issues": [
            {
                "assignment_id": "1",
                "feature": "instruction_collection_not_embedded",
            }
        ],
    }

    item = MigrationItem(
        source_id="274",
        type="exercise",
        title="Exercise IRSS",
        metadata={
            "ilias_type": "exc",
            "obj_id": "806",
            "exercise_structure": structure,
        },
    )

    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    result = recover_exercise_instruction_files(
        document,
        recovery_root,
    )

    assert result["recovered"]["collections_recovered"] == 0
    assert len(result["missing"]) == 1
    assert (
        result["missing"][0]["kind"]
        == "exercise_irss_resource_count_mismatch"
    )

    assignment = structure["assignments"][0]
    assert assignment["automatic_ready"] is False
    assert assignment["migration_constraints"] == [
        "instruction_collection_not_embedded"
    ]


def test_exercise_irss_symlink_is_rejected(
    tmp_path: Path,
) -> None:
    import hashlib

    uuid = "33333333-4444-5555-6666-777777777777"

    recovery_root = tmp_path / "irss"
    collection_root = recovery_root / uuid
    collection_root.mkdir(parents=True)

    payload = b"external-document"

    external = tmp_path / "outside.pdf"
    external.write_bytes(payload)

    filename = "consigne.pdf"
    (collection_root / filename).symlink_to(external)

    sha256 = hashlib.sha256(payload).hexdigest()

    (collection_root / "manifest.json").write_text(
        json.dumps(
            {
                "collection_uuid": uuid,
                "client_id": "ilias10",
                "files": [
                    {
                        "resource_id": (
                            "bbbbbbbb-cccc-dddd-eeee-ffffffffffff"
                        ),
                        "original_name": filename,
                        "output_name": filename,
                        "mime_type": "application/pdf",
                        "expected_size": len(payload),
                        "written_size": len(payload),
                        "sha256": sha256,
                        "status": "OK",
                    }
                ],
                "resource_count": 1,
            }
        ),
        encoding="utf-8",
    )

    structure = {
        "schema_version": "1.0",
        "source": {
            "lms": "ILIAS",
            "object_id": "806",
            "ref_id": "274",
            "export_base": "set_31/example__0__exc_806",
        },
        "title": "Exercise IRSS",
        "description": "",
        "assignments": [
            {
                "source_id": "1",
                "title": "Lien symbolique",
                "instruction_collection": uuid,
                "instruction_collection_kind": (
                    "resource_collection_uuid"
                ),
                "instruction_files": [],
                "instruction_files_embedded": False,
                "type": {
                    "key": "file_upload",
                    "migration_support": "supported",
                },
                "automatic_ready": False,
                "migration_constraints": [
                    "instruction_collection_not_embedded"
                ],
                "phase7_dependencies": [],
            }
        ],
        "blocking_features": [
            {
                "assignment_id": "1",
                "feature": "instruction_collection_not_embedded",
            }
        ],
        "export_issues": [
            {
                "assignment_id": "1",
                "feature": "instruction_collection_not_embedded",
            }
        ],
    }

    item = MigrationItem(
        source_id="274",
        type="exercise",
        title="Exercise IRSS",
        metadata={
            "ilias_type": "exc",
            "obj_id": "806",
            "exercise_structure": structure,
        },
    )

    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[item],
        )
    )

    result = recover_exercise_instruction_files(
        document,
        recovery_root,
    )

    assert result["recovered"]["collections_recovered"] == 0
    assert len(result["missing"]) == 1
    assert (
        result["missing"][0]["kind"]
        == "exercise_irss_symlink_rejected"
    )

    assignment = structure["assignments"][0]
    assert assignment["automatic_ready"] is False
    assert assignment["migration_constraints"] == [
        "instruction_collection_not_embedded"
    ]
