#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path
from typing import Any

_SAFE_NAME = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$"
)


def _safe_name(value: Any) -> str:
    candidate = str(value or "").strip()
    if (
        not _SAFE_NAME.fullmatch(candidate)
        or ".." in candidate
    ):
        raise ValueError("unsafe package name")
    return candidate


def _inside(path: Path, root: Path) -> bool:
    return path == root or root in path.parents


def _load_object(path: Path) -> dict[str, Any]:
    data = json.loads(
        path.read_text(encoding="utf-8")
    )
    if not isinstance(data, dict):
        raise ValueError("JSON root must be an object")
    return data


def _identity_matches(
    path: Path,
    course_ref: str,
) -> bool:
    if (
        path.is_symlink()
        or not path.is_file()
    ):
        return False

    try:
        data = _load_object(path)
    except (
        OSError,
        ValueError,
        json.JSONDecodeError,
    ):
        return False

    return (
        str(data.get("schema_version", ""))
        == "1.0"
        and str(data.get("phase", ""))
        == "7.1"
        and str(
            data.get("source", {}).get(
                "lms",
                "",
            )
        )
        == "ILIAS"
        and str(
            data.get("course", {}).get(
                "ref_id",
                "",
            )
        )
        == course_ref
        and isinstance(
            data.get("users"),
            dict,
        )
        and isinstance(
            data.get("course", {}).get(
                "roles"
            ),
            dict,
        )
    )


def discover_identity_jobs(
    packages_root: Path,
    requests_root: Path,
    identities_root: Path,
) -> dict[str, Any]:
    packages_root = packages_root.resolve()
    requests_root = requests_root.resolve()
    identities_root = identities_root.resolve()

    for label, root in (
        ("packages", packages_root),
        ("identity requests", requests_root),
        ("identities", identities_root),
    ):
        if not root.is_dir():
            raise FileNotFoundError(
                f"{label} root does not exist: {root}"
            )

    jobs: list[dict[str, Any]] = []
    skipped: list[dict[str, str]] = []

    for marker in sorted(
        requests_root.glob("*.json")
    ):
        package_name = marker.stem

        try:
            if (
                marker.is_symlink()
                or not marker.is_file()
            ):
                raise ValueError(
                    "request marker is not a regular file"
                )

            package_name = _safe_name(
                package_name
            )
            request = _load_object(
                marker
            )

            if str(
                request.get(
                    "schema_version",
                    "",
                )
            ) != "1.0":
                raise ValueError(
                    "unsupported request schema"
                )

            if str(
                request.get(
                    "kind",
                    "",
                )
            ) != "phase7_identity":
                raise ValueError(
                    "unsupported request kind"
                )

            if _safe_name(
                request.get(
                    "package_name"
                )
            ) != package_name:
                raise ValueError(
                    "request package mismatch"
                )

            course_ref = str(
                request.get(
                    "course_ref",
                    "",
                )
            ).strip()

            if (
                not course_ref.isdigit()
                or int(course_ref) <= 0
            ):
                raise ValueError(
                    "invalid course_ref"
                )

            package_dir = (
                packages_root
                / package_name
            )

            if (
                package_dir.is_symlink()
                or not package_dir.is_dir()
            ):
                raise ValueError(
                    "package directory missing or unsafe"
                )

            package_real = (
                package_dir.resolve()
            )
            if not _inside(
                package_real,
                packages_root,
            ):
                raise ValueError(
                    "package escaped packages root"
                )

            migration = (
                package_real
                / "migration.json"
            )

            if (
                migration.is_symlink()
                or not migration.is_file()
            ):
                raise ValueError(
                    "migration.json missing or unsafe"
                )

            migrationsha = hashlib.sha256(
                migration.read_bytes()
            ).hexdigest()

            expectedsha = str(
                request.get(
                    "migration_sha256",
                    "",
                )
            ).lower()

            if (
                len(expectedsha) != 64
                or migrationsha
                != expectedsha
            ):
                raise ValueError(
                    "identity request is stale"
                )

            identity = (
                identities_root
                / f"{package_name}.json"
            )

            if _identity_matches(
                identity,
                course_ref,
            ):
                skipped.append({
                    "package_name": package_name,
                    "reason": "identity_already_present",
                })
                continue

            requestsha = hashlib.sha256(
                marker.read_bytes()
            ).hexdigest()

            jobs.append({
                "package_name": package_name,
                "course_ref": course_ref,
                "request_sha256": requestsha,
            })
        except (
            OSError,
            ValueError,
            TypeError,
            json.JSONDecodeError,
        ) as exc:
            skipped.append({
                "package_name": package_name,
                "reason": (
                    f"{exc.__class__.__name__}: "
                    f"{exc}"
                ),
            })

    return {
        "schema_version": "1.0",
        "job_count": len(jobs),
        "jobs": jobs,
        "skipped_count": len(skipped),
        "skipped": skipped,
    }


def main() -> int:
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--packages-root",
        required=True,
        type=Path,
    )
    parser.add_argument(
        "--requests-root",
        required=True,
        type=Path,
    )
    parser.add_argument(
        "--identities-root",
        required=True,
        type=Path,
    )
    args = parser.parse_args()

    try:
        result = discover_identity_jobs(
            args.packages_root,
            args.requests_root,
            args.identities_root,
        )
    except Exception as exc:
        print(
            json.dumps({
                "schema_version": "1.0",
                "error": (
                    f"{exc.__class__.__name__}: "
                    f"{exc}"
                ),
            })
        )
        return 1

    print(
        json.dumps(
            result,
            ensure_ascii=False,
            separators=(",", ":"),
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
