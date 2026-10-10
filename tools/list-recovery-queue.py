#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from typing import Any

_SAFE_NAME = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$"
)
_SHA256 = re.compile(r"^[0-9a-f]{64}$")
_MAX_PLAN_BYTES = 2_097_152


def _inside(path: Path, root: Path) -> bool:
    return path == root or root in path.parents


def _safe_name(value: Any) -> str:
    candidate = str(value or "").strip()
    if (
        not _SAFE_NAME.fullmatch(candidate)
        or ".." in candidate
    ):
        raise ValueError("unsafe package name")
    return candidate


def _load_json(path: Path) -> dict[str, Any]:
    data = json.loads(
        path.read_text(encoding="utf-8")
    )
    if not isinstance(data, dict):
        raise ValueError("JSON root must be an object")
    return data


def discover_jobs(
    packages_root: Path,
    bundles_root: Path,
    requests_root: Path,
) -> dict[str, Any]:
    packages_root = packages_root.resolve()
    bundles_root = bundles_root.resolve()
    requests_root = requests_root.resolve()

    for label, root in (
        ("packages", packages_root),
        ("bundles", bundles_root),
        ("requests", requests_root),
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
        try:
            if marker.is_symlink() or not marker.is_file():
                raise ValueError(
                    "request marker is not a regular file"
                )

            package_name = _safe_name(
                marker.stem
            )
            request = _load_json(marker)

            if str(
                request.get("schema_version", "")
            ) != "1.0":
                raise ValueError(
                    "unsupported request schema"
                )

            if _safe_name(
                request.get("package_name")
            ) != package_name:
                raise ValueError(
                    "request package mismatch"
                )

            queued_sha = str(
                request.get("plan_sha256", "")
            ).lower()
            if not _SHA256.fullmatch(
                queued_sha
            ):
                raise ValueError(
                    "invalid queued plan sha256"
                )

            package_dir = (
                packages_root / package_name
            )
            if (
                package_dir.is_symlink()
                or not package_dir.is_dir()
            ):
                raise ValueError(
                    "package directory missing or unsafe"
                )

            package_real = package_dir.resolve()
            if not _inside(
                package_real,
                packages_root,
            ):
                raise ValueError(
                    "package escaped packages root"
                )

            plan = (
                package_real
                / "recovery-plan.json"
            )
            if (
                plan.is_symlink()
                or not plan.is_file()
            ):
                raise ValueError(
                    "recovery-plan.json missing or unsafe"
                )

            size = plan.stat().st_size
            if (
                size <= 0
                or size > _MAX_PLAN_BYTES
            ):
                raise ValueError(
                    "recovery plan size is invalid"
                )

            payload = plan.read_bytes()
            digest = hashlib.sha256(
                payload
            ).hexdigest()

            if digest != queued_sha:
                raise ValueError(
                    "queued recovery plan is stale"
                )

            plan_data = json.loads(
                payload.decode("utf-8")
            )
            if not isinstance(
                plan_data,
                dict,
            ):
                raise ValueError(
                    "recovery plan root is invalid"
                )

            if str(
                plan_data.get(
                    "schema_version",
                    "",
                )
            ) != "1.0":
                raise ValueError(
                    "unsupported recovery plan schema"
                )

            requests = plan_data.get(
                "requests"
            )
            if not isinstance(
                requests,
                list,
            ) or not requests:
                raise ValueError(
                    "recovery plan has no requests"
                )

            if not bool(
                plan_data.get(
                    "recovery_required"
                )
            ):
                raise ValueError(
                    "recovery plan is no longer required"
                )

            if int(
                plan_data.get(
                    "unresolved_count",
                    0,
                )
            ) != 0:
                raise ValueError(
                    "recovery plan still has unresolved dependencies"
                )

            bundle_name = (
                package_name
                + "_recovery.tar.gz"
            )
            bundle = (
                bundles_root / bundle_name
            )

            if bundle.exists():
                skipped.append({
                    "package_name": package_name,
                    "reason": "bundle_already_present",
                })
                continue

            jobs.append({
                "package_name": package_name,
                "bundle_name": bundle_name,
                "plan_sha256": digest,
                "plan_size": size,
                "request_count": len(
                    requests
                ),
            })
        except (
            OSError,
            ValueError,
            TypeError,
            json.JSONDecodeError,
        ) as exc:
            skipped.append({
                "package_name": marker.stem,
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
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--packages-root",
        required=True,
        type=Path,
    )
    parser.add_argument(
        "--bundles-root",
        required=True,
        type=Path,
    )
    parser.add_argument(
        "--requests-root",
        required=True,
        type=Path,
    )
    args = parser.parse_args()

    try:
        result = discover_jobs(
            args.packages_root,
            args.bundles_root,
            args.requests_root,
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
