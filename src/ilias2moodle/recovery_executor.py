from __future__ import annotations

import json
import re
import shutil
import subprocess
from pathlib import Path, PurePosixPath
from typing import Any

_UUID_RE = re.compile(
    r"^[0-9a-fA-F]{8}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{12}$"
)


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


def _positive_id(value: Any, label: str) -> str:
    candidate = str(value or "").strip()
    if not candidate.isdigit() or int(candidate) <= 0:
        raise ValueError(f"{label} invalide.")
    return candidate


def _safe_location(value: Any) -> str:
    candidate = str(value or "").strip()
    path = PurePosixPath(candidate)

    if (
        not candidate
        or path.is_absolute()
        or ".." in path.parts
        or "\\" in candidate
    ):
        raise ValueError(
            "Location MediaObject invalide ou non sûre."
        )

    return candidate


def _safe_filename(value: Any) -> str:
    candidate = str(value or "").strip()
    if (
        not candidate
        or PurePosixPath(candidate).name != candidate
        or "/" in candidate
        or "\\" in candidate
        or candidate in {".", ".."}
    ):
        raise ValueError("Nom de fichier recovery invalide.")
    return candidate


def _safe_expected_manifest(
    output: Path,
    value: Any,
) -> Path | None:
    candidate = str(value or "").strip()
    if not candidate:
        return None

    relative = PurePosixPath(candidate)
    if relative.is_absolute() or ".." in relative.parts:
        raise ValueError(
            "expected_manifest invalide ou non sûr."
        )

    return output.joinpath(*relative.parts)


def _build_command(
    request: dict[str, Any],
    project_root: Path,
    ilias_root: Path,
    client: str,
    output: Path,
    php: str,
) -> list[str]:
    request_type = str(request.get("type", "")).strip()

    if request_type == "exercise_irss_collection":
        collection_uuid = str(
            request.get("collection_uuid", "")
        ).strip()
        if not _UUID_RE.fullmatch(collection_uuid):
            raise ValueError(
                "UUID de collection IRSS invalide."
            )

        extractor = (
            project_root
            / "tools"
            / "ilias_irss_extract.php"
        )
        arguments = [
            f"--collection={collection_uuid}",
        ]

    elif request_type == "mediaobject_file":
        mob_id = _positive_id(
            request.get("mob_id"),
            "MediaObject mob_id",
        )
        location = _safe_location(
            request.get("location")
        )

        extractor = (
            project_root
            / "tools"
            / "ilias_mediaobject_extract.php"
        )
        arguments = [
            f"--mob-id={mob_id}",
            f"--location={location}",
        ]

    elif request_type == "forum_attachment":
        forum_obj_id = _positive_id(
            request.get("forum_obj_id"),
            "Forum obj_id",
        )
        post_id = _positive_id(
            request.get("post_id"),
            "Forum post_id",
        )
        filename = _safe_filename(
            request.get("filename")
        )

        extractor = (
            project_root
            / "tools"
            / "ilias_forum_attachment_extract.php"
        )
        arguments = [
            f"--forum-obj-id={forum_obj_id}",
            f"--post-id={post_id}",
            f"--filename={filename}",
        ]

    elif request_type == "wiki_content":
        wiki_ref_id = _positive_id(
            request.get("wiki_ref_id"),
            "Wiki ref_id",
        )
        course_ref_id = _positive_id(
            request.get("course_ref_id"),
            "Course ref_id",
        )

        extractor = (
            project_root
            / "tools"
            / "ilias_wiki_content_extract.php"
        )
        arguments = [
            f"--wiki-ref={wiki_ref_id}",
            f"--course-ref={course_ref_id}",
        ]

    else:
        raise ValueError(
            "Type de recovery non supporté par le worker "
            f"ILIAS local : {request_type}"
        )

    if not extractor.is_file():
        raise FileNotFoundError(
            f"Extracteur recovery introuvable : {extractor}"
        )

    return [
        php,
        str(extractor),
        *arguments,
        f"--ilias-root={ilias_root}",
        f"--client={client}",
        f"--output={output}",
    ]


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
        command = _build_command(
            request,
            project_root,
            ilias_root,
            client,
            output,
            php,
        )

        record: dict[str, Any] = {
            "request_index": index,
            "type": request_type,
            "source_ref_id": str(
                request.get("source_ref_id", "")
            ),
            "command": command,
            "dry_run": dry_run,
        }

        for key in (
            "collection_uuid",
            "mob_id",
            "location",
            "forum_obj_id",
            "post_id",
            "filename",
            "wiki_ref_id",
            "wiki_obj_id",
            "course_ref_id",
            "recovery_option",
        ):
            if key in request:
                record[key] = request[key]

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

        manifest_path = _safe_expected_manifest(
            output,
            request.get("expected_manifest", ""),
        )

        empty_collection_compat = False
        if (
            request_type == "exercise_irss_collection"
            and completed.returncode == 3
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
                    == str(
                        request.get(
                            "collection_uuid",
                            "",
                        )
                    )
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

            candidate = _safe_expected_manifest(
                output,
                request.get("expected_manifest", ""),
            )
            if candidate is None:
                continue

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
