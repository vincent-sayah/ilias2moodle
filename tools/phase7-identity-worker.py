#!/usr/bin/env python3
from __future__ import annotations

import argparse
import fcntl
import json
import shutil
import subprocess
import sys
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "src"

if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))

from ilias2moodle.recovery_transport import (  # noqa: E402
    list_pending_identity_jobs,
    publish_identity_document,
)


def _now() -> str:
    return datetime.now(UTC).isoformat()


def _write_json_atomic(
    path: Path,
    data: dict[str, Any],
) -> None:
    path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )
    tmp = path.with_name(
        f".{path.name}.tmp"
    )
    try:
        tmp.write_text(
            json.dumps(
                data,
                ensure_ascii=False,
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        tmp.replace(path)
    finally:
        tmp.unlink(missing_ok=True)


def _load_state(
    path: Path,
) -> dict[str, Any]:
    if not path.is_file():
        return {}

    try:
        data = json.loads(
            path.read_text(
                encoding="utf-8"
            )
        )
    except (
        OSError,
        ValueError,
    ):
        return {}

    return data if isinstance(
        data,
        dict,
    ) else {}


def _validate_identity(
    path: Path,
    *,
    course_ref: str,
    client: str,
) -> dict[str, Any]:
    try:
        data = json.loads(
            path.read_text(
                encoding="utf-8"
            )
        )
    except (
        OSError,
        UnicodeDecodeError,
        json.JSONDecodeError,
    ) as exc:
        raise ValueError(
            "Phase 7.1 extractor returned invalid JSON."
        ) from exc

    if not isinstance(data, dict):
        raise ValueError(
            "Phase 7.1 identity document must be an object."
        )
    if str(
        data.get(
            "schema_version",
            "",
        )
    ) != "1.0":
        raise ValueError(
            "Unsupported Phase 7.1 identity schema."
        )
    if str(
        data.get(
            "phase",
            "",
        )
    ) != "7.1":
        raise ValueError(
            "Unexpected Phase 7 identity phase."
        )
    if str(
        data.get(
            "source",
            {},
        ).get(
            "lms",
            "",
        )
    ) != "ILIAS":
        raise ValueError(
            "Phase 7.1 identity source is not ILIAS."
        )
    if str(
        data.get(
            "source",
            {},
        ).get(
            "client_id",
            "",
        )
    ) != client:
        raise ValueError(
            "Phase 7.1 identity client_id mismatch."
        )
    if str(
        data.get(
            "course",
            {},
        ).get(
            "ref_id",
            "",
        )
    ) != course_ref:
        raise ValueError(
            "Phase 7.1 identity course ref_id mismatch."
        )
    if not isinstance(
        data.get("users"),
        dict,
    ):
        raise ValueError(
            "Phase 7.1 identity users map is missing."
        )
    if not isinstance(
        data.get(
            "course",
            {},
        ).get(
            "roles"
        ),
        dict,
    ):
        raise ValueError(
            "Phase 7.1 identity roles map is missing."
        )

    return data


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Worker ILIAS Phase 7.1 : extraire en lecture seule "
            "les utilisateurs, inscriptions et rôles demandés par Moodle."
        )
    )
    parser.add_argument(
        "--host",
        required=True,
    )
    parser.add_argument(
        "--user",
        default="ilias2moodlepush",
    )
    parser.add_argument(
        "--identity",
        required=True,
        type=Path,
    )
    parser.add_argument(
        "--known-hosts",
        required=True,
        type=Path,
    )
    parser.add_argument(
        "--port",
        type=int,
        default=22,
    )
    parser.add_argument(
        "--ilias-root",
        required=True,
        type=Path,
    )
    parser.add_argument(
        "--client",
        required=True,
    )
    parser.add_argument(
        "--project-root",
        type=Path,
        default=ROOT,
    )
    parser.add_argument(
        "--state-root",
        type=Path,
        default=Path(
            "/var/lib/ilias2moodle-recovery-worker/phase7-identities"
        ),
    )
    parser.add_argument(
        "--max-jobs",
        type=int,
        default=5,
    )
    parser.add_argument(
        "--retry-failed",
        action="store_true",
    )

    args = parser.parse_args()

    if args.max_jobs < 1 or args.max_jobs > 50:
        parser.error(
            "--max-jobs doit être compris entre 1 et 50."
        )

    project_root = (
        args.project_root
        .expanduser()
        .resolve()
    )
    extractor = (
        project_root
        / "tools"
        / "ilias_course_identity_extract.php"
    )

    if not extractor.is_file():
        parser.error(
            "Extracteur Phase 7.1 introuvable."
        )

    php = shutil.which("php")
    if not php:
        parser.error(
            "Exécutable PHP introuvable."
        )

    ilias_root = (
        args.ilias_root
        .expanduser()
        .resolve()
    )
    if not ilias_root.is_dir():
        parser.error(
            "Racine ILIAS invalide."
        )

    state_root = (
        args.state_root
        .expanduser()
        .resolve()
    )
    state_root.mkdir(
        parents=True,
        exist_ok=True,
    )

    lockpath = (
        state_root
        / "worker.lock"
    )

    with lockpath.open("a+") as lock:
        try:
            fcntl.flock(
                lock.fileno(),
                fcntl.LOCK_EX
                | fcntl.LOCK_NB,
            )
        except BlockingIOError:
            print(
                json.dumps(
                    {
                        "mode": "phase7_identity_worker",
                        "success": True,
                        "already_running": True,
                        "processed_count": 0,
                    },
                    ensure_ascii=False,
                    indent=2,
                )
            )
            return 0

        started_at = _now()

        try:
            pending = list_pending_identity_jobs(
                host=args.host,
                user=args.user,
                identity_file=args.identity,
                known_hosts_file=args.known_hosts,
                port=args.port,
            )
        except Exception as exc:
            print(
                json.dumps(
                    {
                        "mode": "phase7_identity_worker",
                        "success": False,
                        "started_at": started_at,
                        "error": (
                            f"{exc.__class__.__name__}: "
                            f"{exc}"
                        ),
                    },
                    ensure_ascii=False,
                    indent=2,
                )
            )
            return 1

        results: list[
            dict[str, Any]
        ] = []
        failed_count = 0
        skipped_count = 0

        for job in pending["jobs"][
            : args.max_jobs
        ]:
            package_name = str(
                job["package_name"]
            )
            course_ref = str(
                job["course_ref"]
            )
            request_sha256 = str(
                job["request_sha256"]
            )

            job_root = (
                state_root
                / package_name
            )
            job_root.mkdir(
                parents=True,
                exist_ok=True,
            )

            state_path = (
                job_root
                / "state.json"
            )
            output = (
                job_root
                / "identities.json"
            )
            previous = _load_state(
                state_path
            )

            if (
                not args.retry_failed
                and previous.get(
                    "status"
                )
                == "FAILED"
                and previous.get(
                    "request_sha256"
                )
                == request_sha256
            ):
                skipped_count += 1
                results.append({
                    "package_name": package_name,
                    "course_ref": course_ref,
                    "status": "SKIPPED_FAILED_SAME_REQUEST",
                    "request_sha256": request_sha256,
                })
                continue

            output.unlink(
                missing_ok=True
            )

            state = {
                "schema_version": "1.0",
                "package_name": package_name,
                "course_ref": course_ref,
                "request_sha256": request_sha256,
                "status": "RUNNING",
                "started_at": _now(),
            }
            _write_json_atomic(
                state_path,
                state,
            )

            try:
                command = [
                    php,
                    str(extractor),
                    "--course-ref",
                    course_ref,
                    "--ilias-root",
                    str(ilias_root),
                    "--client",
                    args.client,
                    "--output",
                    str(output),
                ]

                completed = subprocess.run(
                    command,
                    capture_output=True,
                    check=False,
                    text=True,
                )

                if completed.returncode != 0:
                    raise RuntimeError(
                        "Phase 7.1 extractor failed "
                        f"(exit={completed.returncode}): "
                        f"{completed.stderr.strip() or completed.stdout.strip()}"
                    )

                identity = _validate_identity(
                    output,
                    course_ref=course_ref,
                    client=args.client,
                )

                publication = publish_identity_document(
                    output,
                    package_name=package_name,
                    host=args.host,
                    user=args.user,
                    identity_file=args.identity,
                    known_hosts_file=args.known_hosts,
                    port=args.port,
                )

                counts = (
                    identity.get(
                        "counts"
                    )
                    if isinstance(
                        identity.get(
                            "counts"
                        ),
                        dict,
                    )
                    else {}
                )

                state.update({
                    "status": "SUCCESS",
                    "finished_at": _now(),
                    "extractor_stdout": completed.stdout.strip(),
                    "identity_sha256": publication[
                        "sha256"
                    ],
                    "publication": publication,
                    "counts": counts,
                })
                _write_json_atomic(
                    state_path,
                    state,
                )

                results.append({
                    "package_name": package_name,
                    "course_ref": course_ref,
                    "status": "SUCCESS",
                    "request_sha256": request_sha256,
                    "identity_sha256": publication[
                        "sha256"
                    ],
                    "counts": counts,
                })
            except Exception as exc:
                failed_count += 1
                state.update({
                    "status": "FAILED",
                    "finished_at": _now(),
                    "error": (
                        f"{exc.__class__.__name__}: "
                        f"{exc}"
                    ),
                })
                _write_json_atomic(
                    state_path,
                    state,
                )
                results.append({
                    "package_name": package_name,
                    "course_ref": course_ref,
                    "status": "FAILED",
                    "request_sha256": request_sha256,
                    "error": state["error"],
                })

        success = failed_count == 0

        print(
            json.dumps(
                {
                    "mode": "phase7_identity_worker",
                    "success": success,
                    "already_running": False,
                    "started_at": started_at,
                    "finished_at": _now(),
                    "pending_job_count": pending[
                        "job_count"
                    ],
                    "processed_count": len(
                        results
                    ),
                    "failed_count": failed_count,
                    "skipped_count": skipped_count,
                    "server_skipped": pending.get(
                        "skipped",
                        [],
                    ),
                    "results": results,
                },
                ensure_ascii=False,
                indent=2,
            )
        )

        return 0 if success else 1


if __name__ == "__main__":
    raise SystemExit(main())
