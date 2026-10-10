from __future__ import annotations

import re
from collections.abc import Iterable
from typing import Any

_UUID_RE = re.compile(
    r"^[0-9a-fA-F]{8}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{12}$"
)


def build_recovery_plan(
    missing: Iterable[dict[str, Any]],
) -> dict[str, Any]:
    """Build a machine-readable plan for recoverable source dependencies.

    The plan does not execute remote commands. It describes exactly which
    read-only source extraction is required so an orchestrator can execute it
    through an explicitly configured worker later.
    """

    requests: list[dict[str, Any]] = []
    unresolved: list[dict[str, Any]] = []
    seen: set[tuple[str, str]] = set()

    for item in missing:
        kind = str(item.get("kind", "")).strip()
        source_ref_id = str(item.get("source_id", "")).strip()
        source_path = str(item.get("source_path", "")).strip()

        if kind == "exercise_instruction_collection_unresolved":
            collection_uuid = source_path

            if not _UUID_RE.fullmatch(collection_uuid):
                unresolved.append(
                    {
                        "kind": kind,
                        "source_ref_id": source_ref_id,
                        "reason": "invalid_irss_collection_uuid",
                        "source_path": source_path,
                    }
                )
                continue

            identity = ("exercise_irss_collection", collection_uuid)
            if identity in seen:
                continue
            seen.add(identity)

            request: dict[str, Any] = {
                "type": "exercise_irss_collection",
                "status": "REQUIRED",
                "source_ref_id": source_ref_id,
                "collection_uuid": collection_uuid,
                "source_system": "ILIAS",
                "execution_target": "ILIAS_SOURCE",
                "read_only": True,
                "extractor": "tools/ilias_irss_extract.php",
                "extractor_args": [
                    f"--collection={collection_uuid}",
                ],
                "extractor_command_template": (
                    "php tools/ilias_irss_extract.php "
                    f"--collection={collection_uuid} "
                    "--ilias-root=<ILIAS_ROOT> "
                    "--client=<ILIAS_CLIENT_ID> "
                    "--output=<RECOVERY_ROOT>"
                ),
                "recovery_option": "--exercise-irss-recovery",
                "expected_manifest": (
                    f"{collection_uuid}/manifest.json"
                ),
            }

            assignment_id = str(
                item.get("assignment_id", "")
            ).strip()
            if assignment_id:
                request["assignment_id"] = assignment_id

            requests.append(request)
            continue

        unresolved.append(
            {
                "kind": kind,
                "source_ref_id": source_ref_id,
                "reason": "no_automatic_recovery_contract",
                "source_path": source_path,
            }
        )

    return {
        "schema_version": "1.0",
        "recovery_required": bool(requests),
        "request_count": len(requests),
        "requests": requests,
        "unresolved_count": len(unresolved),
        "unresolved": unresolved,
    }
