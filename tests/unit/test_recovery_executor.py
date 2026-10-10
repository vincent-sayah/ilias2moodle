from __future__ import annotations

import json
import subprocess
from pathlib import Path

import pytest

from ilias2moodle.recovery_executor import execute_recovery_plan

UUID = "496f99f9-f8c3-48ab-9714-a6a54886877e"


def _write_plan(path: Path) -> None:
    path.write_text(
        json.dumps(
            {
                "schema_version": "1.0",
                "recovery_required": True,
                "request_count": 1,
                "requests": [
                    {
                        "type": "exercise_irss_collection",
                        "status": "REQUIRED",
                        "source_ref_id": "356",
                        "assignment_id": "5",
                        "collection_uuid": UUID,
                        "source_system": "ILIAS",
                        "execution_target": "ILIAS_SOURCE",
                        "read_only": True,
                        "extractor": (
                            "tools/ilias_irss_extract.php"
                        ),
                        "extractor_args": [
                            f"--collection={UUID}"
                        ],
                        "expected_manifest": (
                            f"{UUID}/manifest.json"
                        ),
                    }
                ],
                "unresolved_count": 0,
                "unresolved": [],
            }
        ),
        encoding="utf-8",
    )


def _project_root(tmp_path: Path) -> Path:
    project = tmp_path / "project"
    tools = project / "tools"
    tools.mkdir(parents=True)

    for filename in (
        "ilias_irss_extract.php",
        "ilias_mediaobject_extract.php",
        "ilias_forum_attachment_extract.php",
        "ilias_wiki_content_extract.php",
    ):
        (tools / filename).write_text(
            "<?php\n",
            encoding="utf-8",
        )

    return project


def test_recovery_executor_dry_run(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    plan = tmp_path / "recovery-plan.json"
    _write_plan(plan)

    project = _project_root(tmp_path)
    ilias = tmp_path / "ilias"
    ilias.mkdir()
    output = tmp_path / "recovery"

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.shutil.which",
        lambda name: "/usr/bin/php" if name == "php" else None,
    )

    result = execute_recovery_plan(
        plan,
        output,
        ilias,
        "ilias10",
        project,
        dry_run=True,
    )

    assert result["mode"] == "recover_source"
    assert result["success"] is True
    assert result["request_count"] == 1
    assert result["failed_count"] == 0
    assert result["results"][0]["status"] == "DRY_RUN"
    assert f"--collection={UUID}" in result["results"][0]["command"]


def test_recovery_executor_runs_irss_extractor(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    plan = tmp_path / "recovery-plan.json"
    _write_plan(plan)

    project = _project_root(tmp_path)
    ilias = tmp_path / "ilias"
    ilias.mkdir()
    output = tmp_path / "recovery"

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.shutil.which",
        lambda name: "/usr/bin/php" if name == "php" else None,
    )

    def fake_run(
        command: list[str],
        **kwargs: object,
    ):
        output_arg = next(
            value
            for value in command
            if value.startswith("--output=")
        )
        output_root = Path(
            output_arg.split("=", 1)[1]
        )
        collection = output_root / UUID
        collection.mkdir(parents=True)
        (collection / "manifest.json").write_text(
            json.dumps(
                {
                    "collection_uuid": UUID,
                    "resource_count": 0,
                    "files": [],
                }
            ),
            encoding="utf-8",
        )

        return subprocess.CompletedProcess(
            args=command,
            returncode=0,
            stdout="RESULTAT    : EXTRACTION_OK\n",
            stderr="",
        )

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.subprocess.run",
        fake_run,
    )

    result = execute_recovery_plan(
        plan,
        output,
        ilias,
        "ilias10",
        project,
    )

    assert result["success"] is True
    assert result["failed_count"] == 0
    assert result["results"][0]["status"] == "SUCCESS"
    assert result["manifests"] == [
        {
            "path": str(output.resolve() / UUID / "manifest.json"),
            "exists": True,
        }
    ]


def test_recovery_executor_accepts_legacy_empty_collection_exit(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    plan = tmp_path / "recovery-plan.json"
    _write_plan(plan)

    project = _project_root(tmp_path)
    ilias = tmp_path / "ilias"
    ilias.mkdir()
    output = tmp_path / "recovery"

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.shutil.which",
        lambda name: "/usr/bin/php" if name == "php" else None,
    )

    def fake_run(
        command: list[str],
        **kwargs: object,
    ):
        output_arg = next(
            value
            for value in command
            if value.startswith("--output=")
        )
        output_root = Path(
            output_arg.split("=", 1)[1]
        )
        collection = output_root / UUID
        collection.mkdir(parents=True)
        (collection / "manifest.json").write_text(
            json.dumps(
                {
                    "collection_uuid": UUID,
                    "resource_count": 0,
                    "files": [],
                }
            ),
            encoding="utf-8",
        )

        return subprocess.CompletedProcess(
            args=command,
            returncode=3,
            stdout="RESULTAT    : COLLECTION_VIDE\n",
            stderr="",
        )

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.subprocess.run",
        fake_run,
    )

    result = execute_recovery_plan(
        plan,
        output,
        ilias,
        "ilias10",
        project,
    )

    assert result["success"] is True
    assert result["failed_count"] == 0
    assert (
        result["results"][0]["status"]
        == "SUCCESS_EMPTY_COMPAT"
    )
    assert result["results"][0]["exit_code"] == 3


def test_recovery_executor_refuses_non_read_only(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    plan = tmp_path / "recovery-plan.json"
    _write_plan(plan)

    data = json.loads(plan.read_text(encoding="utf-8"))
    data["requests"][0]["read_only"] = False
    plan.write_text(json.dumps(data), encoding="utf-8")

    project = _project_root(tmp_path)
    ilias = tmp_path / "ilias"
    ilias.mkdir()

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.shutil.which",
        lambda name: "/usr/bin/php",
    )

    with pytest.raises(
        ValueError,
        match="non read-only",
    ):
        execute_recovery_plan(
            plan,
            tmp_path / "out",
            ilias,
            "ilias10",
            project,
        )


def test_recovery_executor_refuses_unknown_type(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    plan = tmp_path / "recovery-plan.json"
    _write_plan(plan)

    data = json.loads(plan.read_text(encoding="utf-8"))
    data["requests"][0]["type"] = "unknown"
    plan.write_text(json.dumps(data), encoding="utf-8")

    project = _project_root(tmp_path)
    ilias = tmp_path / "ilias"
    ilias.mkdir()

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.shutil.which",
        lambda name: "/usr/bin/php",
    )

    with pytest.raises(
        ValueError,
        match="non supporté",
    ):
        execute_recovery_plan(
            plan,
            tmp_path / "out",
            ilias,
            "ilias10",
            project,
        )


def test_recovery_executor_dry_runs_extended_contracts(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    plan = tmp_path / "recovery-plan.json"
    plan.write_text(
        json.dumps(
            {
                "schema_version": "1.0",
                "recovery_required": True,
                "request_count": 3,
                "requests": [
                    {
                        "type": "mediaobject_file",
                        "status": "REQUIRED",
                        "source_ref_id": "332",
                        "mob_id": "980",
                        "location": "blog.png",
                        "execution_target": "ILIAS_SOURCE",
                        "read_only": True,
                        "recovery_option": "--mediaobject-recovery",
                        "expected_manifest": "mob_980/manifest.json",
                    },
                    {
                        "type": "forum_attachment",
                        "status": "REQUIRED",
                        "source_ref_id": "275",
                        "forum_obj_id": "807",
                        "post_id": "18",
                        "filename": "handout.pdf",
                        "execution_target": "ILIAS_SOURCE",
                        "read_only": True,
                        "recovery_option": (
                            "--forum-attachment-recovery"
                        ),
                        "expected_manifest": (
                            "forum_807/post_18/manifest.json"
                        ),
                    },
                    {
                        "type": "wiki_content",
                        "status": "REQUIRED",
                        "source_ref_id": "339",
                        "wiki_ref_id": "339",
                        "wiki_obj_id": "1000",
                        "course_ref_id": "282",
                        "execution_target": "ILIAS_SOURCE",
                        "read_only": True,
                        "recovery_option": "--wiki-content-recovery",
                        "expected_manifest": "wiki_1000/manifest.json",
                    },
                ],
                "unresolved_count": 0,
                "unresolved": [],
            }
        ),
        encoding="utf-8",
    )

    project = _project_root(tmp_path)
    ilias = tmp_path / "ilias"
    ilias.mkdir()

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.shutil.which",
        lambda name: "/usr/bin/php" if name == "php" else None,
    )

    result = execute_recovery_plan(
        plan,
        tmp_path / "recovery",
        ilias,
        "ilias10",
        project,
        dry_run=True,
    )

    assert result["success"] is True
    assert result["request_count"] == 3
    assert [entry["type"] for entry in result["results"]] == [
        "mediaobject_file",
        "forum_attachment",
        "wiki_content",
    ]

    media_command = result["results"][0]["command"]
    assert "--mob-id=980" in media_command
    assert "--location=blog.png" in media_command

    forum_command = result["results"][1]["command"]
    assert "--forum-obj-id=807" in forum_command
    assert "--post-id=18" in forum_command
    assert "--filename=handout.pdf" in forum_command

    wiki_command = result["results"][2]["command"]
    assert "--wiki-ref=339" in wiki_command
    assert "--course-ref=282" in wiki_command


def test_recovery_executor_refuses_unsafe_expected_manifest(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    plan = tmp_path / "recovery-plan.json"
    _write_plan(plan)

    data = json.loads(plan.read_text(encoding="utf-8"))
    data["requests"][0]["expected_manifest"] = "../outside.json"
    plan.write_text(json.dumps(data), encoding="utf-8")

    project = _project_root(tmp_path)
    ilias = tmp_path / "ilias"
    ilias.mkdir()

    monkeypatch.setattr(
        "ilias2moodle.recovery_executor.shutil.which",
        lambda name: "/usr/bin/php",
    )

    with pytest.raises(
        ValueError,
        match="expected_manifest invalide",
    ):
        execute_recovery_plan(
            plan,
            tmp_path / "out",
            ilias,
            "ilias10",
            project,
        )
