from __future__ import annotations

import re
from collections.abc import Iterable
from pathlib import PurePosixPath
from typing import Any

_UUID_RE = re.compile(
    r"^[0-9a-fA-F]{8}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{4}-"
    r"[0-9a-fA-F]{12}$"
)


def _positive_id(value: Any) -> str:
    candidate = str(value or "").strip()
    if not candidate.isdigit() or int(candidate) <= 0:
        return ""
    return candidate


def _safe_location(value: Any) -> str:
    candidate = str(value or "").strip()
    if not candidate:
        return ""

    path = PurePosixPath(candidate)
    if path.is_absolute() or ".." in path.parts or "\\" in candidate:
        return ""

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
        return ""
    return candidate


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
    seen: set[tuple[str, ...]] = set()

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

        if kind in {
            "mediacast_local_media",
            "blog_media",
            "media_pool_media",
        }:
            mob_id = _positive_id(item.get("mob_id"))
            location = _safe_location(
                item.get("location") or source_path
            )

            recovery_option = (
                "--mediacast-media-recovery"
                if kind == "mediacast_local_media"
                else "--mediaobject-recovery"
            )

            if not mob_id or not location:
                unresolved.append(
                    {
                        "kind": kind,
                        "source_ref_id": source_ref_id,
                        "reason": "invalid_mediaobject_identity",
                        "source_path": source_path,
                    }
                )
                continue

            identity = (
                "mediaobject_file",
                mob_id,
                location,
                recovery_option,
            )
            if identity in seen:
                continue
            seen.add(identity)

            request = {
                "type": "mediaobject_file",
                "status": "REQUIRED",
                "source_ref_id": source_ref_id,
                "mob_id": mob_id,
                "location": location,
                "source_system": "ILIAS",
                "execution_target": "ILIAS_SOURCE",
                "read_only": True,
                "extractor": "tools/ilias_mediaobject_extract.php",
                "extractor_args": [
                    f"--mob-id={mob_id}",
                    f"--location={location}",
                ],
                "extractor_command_template": (
                    "php tools/ilias_mediaobject_extract.php "
                    f"--mob-id={mob_id} "
                    f"--location={location} "
                    "--ilias-root=<ILIAS_ROOT> "
                    "--client=<ILIAS_CLIENT_ID> "
                    "--output=<RECOVERY_ROOT>"
                ),
                "recovery_option": recovery_option,
                "expected_manifest": (
                    f"mob_{mob_id}/manifest.json"
                ),
            }

            entry_id = str(item.get("entry_id", "")).strip()
            if entry_id:
                request["entry_id"] = entry_id

            requests.append(request)
            continue

        if kind == "forum_attachment":
            forum_obj_id = _positive_id(
                item.get("forum_obj_id")
            )
            post_id = _positive_id(item.get("post_id"))
            filename = _safe_filename(
                item.get("filename")
                or PurePosixPath(source_path).name
            )

            if not forum_obj_id or not post_id or not filename:
                unresolved.append(
                    {
                        "kind": kind,
                        "source_ref_id": source_ref_id,
                        "reason": "invalid_forum_attachment_identity",
                        "source_path": source_path,
                    }
                )
                continue

            identity = (
                "forum_attachment",
                forum_obj_id,
                post_id,
                filename,
            )
            if identity in seen:
                continue
            seen.add(identity)

            requests.append(
                {
                    "type": "forum_attachment",
                    "status": "REQUIRED",
                    "source_ref_id": source_ref_id,
                    "forum_obj_id": forum_obj_id,
                    "post_id": post_id,
                    "filename": filename,
                    "source_system": "ILIAS",
                    "execution_target": "ILIAS_SOURCE",
                    "read_only": True,
                    "extractor": (
                        "tools/ilias_forum_attachment_extract.php"
                    ),
                    "extractor_args": [
                        f"--forum-obj-id={forum_obj_id}",
                        f"--post-id={post_id}",
                        f"--filename={filename}",
                    ],
                    "extractor_command_template": (
                        "php tools/ilias_forum_attachment_extract.php "
                        f"--forum-obj-id={forum_obj_id} "
                        f"--post-id={post_id} "
                        f"--filename={filename} "
                        "--ilias-root=<ILIAS_ROOT> "
                        "--client=<ILIAS_CLIENT_ID> "
                        "--output=<RECOVERY_ROOT>"
                    ),
                    "recovery_option": (
                        "--forum-attachment-recovery"
                    ),
                    "expected_manifest": (
                        f"forum_{forum_obj_id}/"
                        f"post_{post_id}/manifest.json"
                    ),
                }
            )
            continue

        if kind == "wiki_structure":
            wiki_ref_id = _positive_id(source_ref_id)
            wiki_obj_id = _positive_id(
                item.get("wiki_obj_id")
            )
            course_ref_id = _positive_id(
                item.get("course_ref_id")
            )

            if (
                not wiki_ref_id
                or not wiki_obj_id
                or not course_ref_id
            ):
                unresolved.append(
                    {
                        "kind": kind,
                        "source_ref_id": source_ref_id,
                        "reason": "invalid_wiki_identity",
                        "source_path": source_path,
                    }
                )
                continue

            identity = (
                "wiki_content",
                wiki_ref_id,
                course_ref_id,
            )
            if identity in seen:
                continue
            seen.add(identity)

            requests.append(
                {
                    "type": "wiki_content",
                    "status": "REQUIRED",
                    "source_ref_id": source_ref_id,
                    "wiki_ref_id": wiki_ref_id,
                    "wiki_obj_id": wiki_obj_id,
                    "course_ref_id": course_ref_id,
                    "source_system": "ILIAS",
                    "execution_target": "ILIAS_SOURCE",
                    "read_only": True,
                    "extractor": (
                        "tools/ilias_wiki_content_extract.php"
                    ),
                    "extractor_args": [
                        f"--wiki-ref={wiki_ref_id}",
                        f"--course-ref={course_ref_id}",
                    ],
                    "extractor_command_template": (
                        "php tools/ilias_wiki_content_extract.php "
                        f"--wiki-ref={wiki_ref_id} "
                        f"--course-ref={course_ref_id} "
                        "--ilias-root=<ILIAS_ROOT> "
                        "--client=<ILIAS_CLIENT_ID> "
                        "--output=<RECOVERY_ROOT>"
                    ),
                    "recovery_option": "--wiki-content-recovery",
                    "expected_manifest": (
                        f"wiki_{wiki_obj_id}/manifest.json"
                    ),
                }
            )
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
