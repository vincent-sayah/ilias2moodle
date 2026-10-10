#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "src"

if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))

from ilias2moodle.recovery_executor import execute_recovery_plan  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Exécuter localement sur le serveur ILIAS un "
            "recovery-plan.json read-only."
        )
    )
    parser.add_argument(
        "--plan",
        required=True,
        type=Path,
        help="Chemin vers recovery-plan.json",
    )
    parser.add_argument(
        "--output",
        required=True,
        type=Path,
        help="Répertoire de sortie des récupérations",
    )
    parser.add_argument(
        "--ilias-root",
        required=True,
        type=Path,
        help="Racine de l'installation ILIAS",
    )
    parser.add_argument(
        "--client",
        required=True,
        help="Client ILIAS",
    )
    parser.add_argument(
        "--project-root",
        type=Path,
        default=ROOT,
        help="Racine du checkout ILIAS2Moodle",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Valider le plan sans exécuter les extracteurs",
    )

    args = parser.parse_args()

    try:
        result = execute_recovery_plan(
            args.plan,
            args.output,
            args.ilias_root,
            args.client,
            args.project_root,
            args.dry_run,
        )
    except Exception as exc:
        print(
            json.dumps(
                {
                    "mode": "recover_source",
                    "success": False,
                    "error": (
                        f"{exc.__class__.__name__}: {exc}"
                    ),
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        return 1

    print(
        json.dumps(
            result,
            ensure_ascii=False,
            indent=2,
        )
    )
    return 0 if result["success"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
