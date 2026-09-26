from __future__ import annotations

import hashlib
import json
from pathlib import Path, PurePosixPath
from typing import Any


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _safe_output_file(manifest_path: Path, output_name: str) -> Path | None:
    relative = PurePosixPath(output_name)
    if (
        not output_name
        or relative.is_absolute()
        or len(relative.parts) != 1
        or ".." in relative.parts
    ):
        return None
    candidate = manifest_path.parent / output_name
    if candidate.is_symlink() or not candidate.is_file():
        return None
    return candidate


def load_mediaobject_recovery(
    recovery_dir: str | Path,
    mob_id: str,
    location: str,
) -> tuple[dict[str, Any] | None, str | None]:
    root = Path(recovery_dir).resolve()
    manifest_path = root / f"mob_{mob_id}" / "manifest.json"
    if not manifest_path.is_file():
        return None, "mediaobject_recovery_manifest_missing"

    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None, "mediaobject_recovery_manifest_invalid"

    if str(manifest.get("mob_id", "")) != str(mob_id):
        return None, "mediaobject_recovery_mob_mismatch"
    if str(manifest.get("location", "")) != str(location):
        return None, "mediaobject_recovery_location_mismatch"

    status = str(manifest.get("status", ""))
    if status not in {"OK", "OK_SIZE_UNAVAILABLE"}:
        return None, "mediaobject_recovery_not_ok"

    output_name = str(manifest.get("output_name", ""))
    recovered_path = _safe_output_file(manifest_path, output_name)
    if recovered_path is None:
        return None, "mediaobject_recovery_file_missing_or_unsafe"

    actual_size = recovered_path.stat().st_size
    actual_sha256 = _sha256_file(recovered_path)
    try:
        manifest_size = int(manifest.get("size", -1))
    except (TypeError, ValueError):
        manifest_size = -1

    expected_sha256 = str(manifest.get("sha256", "")).lower()
    if (
        manifest_size <= 0
        or actual_size != manifest_size
        or not expected_sha256
        or actual_sha256 != expected_sha256
    ):
        return None, "mediaobject_recovery_integrity_error"

    return (
        {
            "path": recovered_path,
            "size": actual_size,
            "sha256": actual_sha256,
            "manifest": manifest_path,
        },
        None,
    )


def load_mediaobject_recovery_by_id(
    recovery_dir: str | Path,
    mob_id: str,
) -> tuple[dict[str, Any] | None, str | None]:
    root = Path(recovery_dir).resolve()
    manifest_path = root / f"mob_{mob_id}" / "manifest.json"
    if not manifest_path.is_file():
        return None, "mediaobject_recovery_manifest_missing"

    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None, "mediaobject_recovery_manifest_invalid"

    if str(manifest.get("mob_id", "")) != str(mob_id):
        return None, "mediaobject_recovery_mob_mismatch"

    location = str(manifest.get("location", ""))
    if not location:
        return None, "mediaobject_recovery_location_missing"

    recovery, error = load_mediaobject_recovery(
        recovery_dir,
        mob_id,
        location,
    )
    if recovery is None:
        return None, error

    recovery["location"] = location
    recovery["output_name"] = str(manifest.get("output_name", ""))
    recovery["manifest_data"] = manifest
    return recovery, None


def load_forum_attachment_recovery(
    recovery_dir: str | Path,
    forum_obj_id: str,
    post_id: str,
    filename: str,
) -> tuple[dict[str, Any] | None, str | None]:
    root = Path(recovery_dir).resolve()
    manifest_path = (
        root
        / f"forum_{forum_obj_id}"
        / f"post_{post_id}"
        / "manifest.json"
    )
    if not manifest_path.is_file():
        return None, "forum_recovery_manifest_missing"

    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None, "forum_recovery_manifest_invalid"

    if str(manifest.get("forum_obj_id", "")) != str(forum_obj_id):
        return None, "forum_recovery_forum_mismatch"
    if str(manifest.get("post_id", "")) != str(post_id):
        return None, "forum_recovery_post_mismatch"
    if str(manifest.get("filename", "")) != str(filename):
        return None, "forum_recovery_filename_mismatch"

    status = str(manifest.get("status", ""))
    if status not in {"OK", "OK_SIZE_UNAVAILABLE"}:
        return None, "forum_recovery_not_ok"

    output_name = str(manifest.get("output_name", ""))
    recovered_path = _safe_output_file(manifest_path, output_name)
    if recovered_path is None:
        return None, "forum_recovery_file_missing_or_unsafe"

    actual_size = recovered_path.stat().st_size
    actual_sha256 = _sha256_file(recovered_path)
    try:
        manifest_size = int(manifest.get("size", -1))
    except (TypeError, ValueError):
        manifest_size = -1

    expected_sha256 = str(manifest.get("sha256", "")).lower()
    if (
        manifest_size <= 0
        or actual_size != manifest_size
        or not expected_sha256
        or actual_sha256 != expected_sha256
    ):
        return None, "forum_recovery_integrity_error"

    return (
        {
            "path": recovered_path,
            "size": actual_size,
            "sha256": actual_sha256,
            "manifest": manifest_path,
        },
        None,
    )
