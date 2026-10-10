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
    (tools / "ilias_irss_extract.php").write_text(
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
