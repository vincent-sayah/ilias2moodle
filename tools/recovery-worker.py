#!/usr/bin/env python3
from __future__ import annotations

import argparse
import fcntl
import json
import shutil
import sys
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "src"

if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))

from ilias2moodle.recovery_executor import execute_recovery_plan  # noqa: E402
from ilias2moodle.recovery_transport import (  # noqa: E402
    build_recovery_bundle,
    fetch_recovery_plan,
    list_pending_recovery_jobs,
    publish_recovery_bundle,
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


def _load_state(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        data = json.loads(
            path.read_text(encoding="utf-8")
        )
    except (
        OSError,
        ValueError,
        json.JSONDecodeError,
    ):
        return {}
    return data if isinstance(data, dict) else {}


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Worker ILIAS : détecter les recoveries Moodle "
            "en attente, exécuter et republier les bundles."
        )
    )
    parser.add_argument(
        "--host",
        required=True,
        help="Hôte ou IP du serveur Moodle.",
    )
    parser.add_argument(
        "--user",
        default="ilias2moodlepush",
        help="Utilisateur SSH forced-command.",
    )
    parser.add_argument(
        "--identity",
        required=True,
        type=Path,
        help="Clé privée SSH dédiée.",
    )
    parser.add_argument(
        "--known-hosts",
        required=True,
        type=Path,
        help="known_hosts dédié et vérifié.",
    )
    parser.add_argument(
        "--port",
        type=int,
        default=22,
        help="Port SSH Moodle.",
    )
    parser.add_argument(
        "--ilias-root",
        required=True,
        type=Path,
        help="Racine ILIAS.",
    )
    parser.add_argument(
        "--client",
        required=True,
        help="Client ILIAS.",
    )
    parser.add_argument(
        "--project-root",
        type=Path,
        default=ROOT,
        help="Checkout ILIAS2Moodle.",
    )
    parser.add_argument(
        "--state-root",
        type=Path,
        default=Path(
            "/var/lib/ilias2moodle-recovery-worker"
        ),
        help="Spool et état local du worker.",
    )
    parser.add_argument(
        "--max-jobs",
        type=int,
        default=10,
        help="Nombre maximum de jobs par exécution.",
    )
    parser.add_argument(
        "--retry-failed",
        action="store_true",
        help=(
            "Retenter un plan déjà marqué FAILED sans "
            "attendre un changement de SHA-256."
        ),
    )

    args = parser.parse_args()

    if args.max_jobs < 1 or args.max_jobs > 100:
        parser.error(
            "--max-jobs doit être compris entre 1 et 100."
        )

    state_root = args.state_root.expanduser().resolve()
    state_root.mkdir(
        parents=True,
        exist_ok=True,
    )

    lockpath = state_root / "worker.lock"

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
                        "mode": "recovery_worker",
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
            pending = list_pending_recovery_jobs(
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
                        "mode": "recovery_worker",
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

        results: list[dict[str, Any]] = []
        failed_count = 0
        skipped_count = 0

        for job in pending["jobs"][
            : args.max_jobs
        ]:
            package_name = str(
                job["package_name"]
            )
            plan_sha256 = str(
                job["plan_sha256"]
            )
            bundle_name = str(
                job["bundle_name"]
            )

            job_root = (
                state_root / package_name
            )
            state_path = (
                job_root / "state.json"
            )
            previous = _load_state(
                state_path
            )

            if (
                not args.retry_failed
                and previous.get("status")
                == "FAILED"
                and previous.get("plan_sha256")
                == plan_sha256
            ):
                skipped_count += 1
                results.append({
                    "package_name": package_name,
                    "status": "SKIPPED_FAILED_SAME_PLAN",
                    "plan_sha256": plan_sha256,
                })
                continue

            plan_path = (
                job_root
                / "recovery-plan.json"
            )
            recovery_root = (
                job_root / "recovery"
            )
            bundle_path = (
                job_root / bundle_name
            )

            if recovery_root.exists():
                shutil.rmtree(
                    recovery_root
                )
            bundle_path.unlink(
                missing_ok=True
            )

            state = {
                "schema_version": "1.0",
                "package_name": package_name,
                "plan_sha256": plan_sha256,
                "bundle_name": bundle_name,
                "status": "RUNNING",
                "started_at": _now(),
            }
            _write_json_atomic(
                state_path,
                state,
            )

            try:
                fetched = fetch_recovery_plan(
                    package_name=package_name,
                    output=plan_path,
                    host=args.host,
                    user=args.user,
                    identity_file=args.identity,
                    known_hosts_file=args.known_hosts,
                    port=args.port,
                )

                if (
                    fetched["sha256"]
                    != plan_sha256
                ):
                    raise RuntimeError(
                        "Recovery plan changed between "
                        "list-pending and fetch-plan."
                    )

                recovery = execute_recovery_plan(
                    plan_path,
                    recovery_root,
                    args.ilias_root,
                    args.client,
                    args.project_root,
                    False,
                )

                if not recovery["success"]:
                    raise RuntimeError(
                        "Recovery execution returned success=false."
                    )

                bundle = build_recovery_bundle(
                    recovery_root,
                    bundle_path,
                )

                publication = publish_recovery_bundle(
                    Path(bundle["bundle"]),
                    host=args.host,
                    user=args.user,
                    identity_file=args.identity,
                    known_hosts_file=args.known_hosts,
                    port=args.port,
                    remote_name=bundle_name,
                )

                state.update({
                    "status": "SUCCESS",
                    "finished_at": _now(),
                    "plan_fetch": fetched,
                    "recovery": recovery,
                    "bundle": bundle,
                    "publication": publication,
                })
                _write_json_atomic(
                    state_path,
                    state,
                )

                results.append({
                    "package_name": package_name,
                    "status": "SUCCESS",
                    "plan_sha256": plan_sha256,
                    "bundle_name": bundle_name,
                    "bundle_sha256": bundle[
                        "sha256"
                    ],
                    "request_count": recovery[
                        "request_count"
                    ],
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
                    "status": "FAILED",
                    "plan_sha256": plan_sha256,
                    "error": state["error"],
                })

        success = failed_count == 0

        print(
            json.dumps(
                {
                    "mode": "recovery_worker",
                    "success": success,
                    "already_running": False,
                    "started_at": started_at,
                    "finished_at": _now(),
                    "pending_job_count": pending[
                        "job_count"
                    ],
                    "processed_count": len(results),
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
