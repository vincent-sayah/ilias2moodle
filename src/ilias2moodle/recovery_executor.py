from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path
from typing import Any


def _load_plan(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise FileNotFoundError(
            f"Recovery plan introuvable : {path}"
        )

    plan = json.loads(path.read_text(encoding="utf-8"))

    if not isinstance(plan, dict):
        raise ValueError("Recovery plan JSON invalide.")

    if str(plan.get("schema_version", "")) != "1.0":
        raise ValueError(
            "Recovery plan schema_version non supportée."
        )

    requests = plan.get("requests")
    if not isinstance(requests, list):
        raise ValueError(
            "Recovery plan requests doit être une liste."
        )

    return plan


def execute_recovery_plan(
    plan_path: Path,
    output: Path,
    ilias_root: Path,
    client: str,
    project_root: Path,
    dry_run: bool = False,
) -> dict[str, Any]:
    """Execute supported read-only recovery requests on the ILIAS source.

    No remote transport is performed here. This worker is intentionally
    designed to run locally on the ILIAS source host.
    """

    plan_path = plan_path.resolve()
    project_root = project_root.resolve()
    ilias_root = ilias_root.resolve()
    output = output.resolve()

    plan = _load_plan(plan_path)

    if not ilias_root.is_dir():
        raise FileNotFoundError(
            f"Racine ILIAS introuvable : {ilias_root}"
        )

    if not project_root.is_dir():
        raise FileNotFoundError(
            f"Racine projet ILIAS2Moodle introuvable : "
            f"{project_root}"
        )

    if (
        not client
        or not all(
            char.isalnum() or char in "_.-"
            for char in client
        )
    ):
        raise ValueError("Client ILIAS invalide.")

    php = shutil.which("php")
    if php is None:
        raise FileNotFoundError(
            "Exécutable PHP introuvable dans PATH."
        )

    requests = plan["requests"]
    results: list[dict[str, Any]] = []
    failed = 0

    for index, request in enumerate(requests, start=1):
        if not isinstance(request, dict):
            raise ValueError(
                f"Recovery request #{index} invalide."
            )

        if request.get("read_only") is not True:
            raise ValueError(
                f"Recovery request #{index} non read-only refusée."
            )

        if (
            str(request.get("execution_target", ""))
            != "ILIAS_SOURCE"
        ):
            raise ValueError(
                f"Recovery request #{index} cible inattendue."
            )

        request_type = str(request.get("type", ""))

        if request_type != "exercise_irss_collection":
            raise ValueError(
                "Type de recovery non supporté par le worker "
                f"ILIAS local : {request_type}"
            )

        collection_uuid = str(
            request.get("collection_uuid", "")
        )

        extractor = (
            project_root
            / "tools"
            / "ilias_irss_extract.php"
        )

        if not extractor.is_file():
            raise FileNotFoundError(
                f"Extracteur IRSS introuvable : {extractor}"
            )

        command = [
            php,
            str(extractor),
            f"--collection={collection_uuid}",
            f"--ilias-root={ilias_root}",
            f"--client={client}",
            f"--output={output}",
        ]

        record: dict[str, Any] = {
            "request_index": index,
            "type": request_type,
            "source_ref_id": str(
                request.get("source_ref_id", "")
            ),
            "collection_uuid": collection_uuid,
            "command": command,
            "dry_run": dry_run,
        }

        if dry_run:
            record.update(
                {
                    "status": "DRY_RUN",
                    "exit_code": None,
                    "stdout": "",
                    "stderr": "",
                }
            )
            results.append(record)
            continue

        output.mkdir(parents=True, exist_ok=True)

        completed = subprocess.run(
            command,
            cwd=project_root,
            check=False,
            capture_output=True,
            text=True,
        )

        expected_manifest = str(
            request.get("expected_manifest", "")
        ).strip()
        manifest_path = (
            output / expected_manifest
            if expected_manifest
            else None
        )

        empty_collection_compat = False
        if (
            completed.returncode == 3
            and "RESULTAT    : COLLECTION_VIDE"
            in completed.stdout
            and manifest_path is not None
            and manifest_path.is_file()
        ):
            try:
                manifest_data = json.loads(
                    manifest_path.read_text(
                        encoding="utf-8"
                    )
                )
                empty_collection_compat = (
                    str(
                        manifest_data.get(
                            "collection_uuid",
                            "",
                        )
                    )
                    == collection_uuid
                    and int(
                        manifest_data.get(
                            "resource_count",
                            -1,
                        )
                    )
                    == 0
                    and isinstance(
                        manifest_data.get("files"),
                        list,
                    )
                )
            except (
                OSError,
                ValueError,
                TypeError,
                json.JSONDecodeError,
            ):
                empty_collection_compat = False

        status = "FAILED"
        if completed.returncode == 0:
            status = "SUCCESS"
        elif empty_collection_compat:
            status = "SUCCESS_EMPTY_COMPAT"

        record.update(
            {
                "exit_code": completed.returncode,
                "stdout": completed.stdout,
                "stderr": completed.stderr,
                "status": status,
            }
        )

        if status == "FAILED":
            failed += 1

        results.append(record)

    manifests = []
    if not dry_run:
        for request in requests:
            if not isinstance(request, dict):
                continue
            expected = str(
                request.get("expected_manifest", "")
            ).strip()
            if not expected:
                continue
            candidate = output / expected
            manifests.append(
                {
                    "path": str(candidate),
                    "exists": candidate.is_file(),
                }
            )

    success = failed == 0 and all(
        item.get("exists", True)
        for item in manifests
    )

    return {
        "mode": "recover_source",
        "plan": str(plan_path),
        "output": str(output),
        "ilias_root": str(ilias_root),
        "client": client,
        "dry_run": dry_run,
        "request_count": len(requests),
        "failed_count": failed,
        "success": success,
        "results": results,
        "manifests": manifests,
    }
