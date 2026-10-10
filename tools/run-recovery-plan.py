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
from ilias2moodle.recovery_transport import (  # noqa: E402
    build_recovery_bundle,
    publish_recovery_bundle,
)


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
    parser.add_argument(
        "--bundle",
        type=Path,
        help=(
            "Créer un .tar.gz du recovery après succès. "
            "Obligatoire implicitement si --publish-host est utilisé."
        ),
    )
    parser.add_argument(
        "--publish-host",
        help=(
            "Publier le bundle vers le récepteur SSH forcé "
            "du serveur Moodle."
        ),
    )
    parser.add_argument(
        "--publish-user",
        default="ilias2moodlepush",
        help="Utilisateur SSH dédié au dépôt recovery.",
    )
    parser.add_argument(
        "--publish-identity",
        type=Path,
        help="Clé privée SSH dédiée au dépôt recovery.",
    )
    parser.add_argument(
        "--publish-known-hosts",
        type=Path,
        help="Fichier known_hosts dédié et vérifié.",
    )
    parser.add_argument(
        "--publish-port",
        type=int,
        default=22,
        help="Port SSH du serveur Moodle.",
    )
    parser.add_argument(
        "--publish-name",
        help="Nom distant du bundle (.tar.gz/.tgz).",
    )

    args = parser.parse_args()

    publish_requested = args.publish_host is not None

    if publish_requested and (
        args.publish_identity is None
        or args.publish_known_hosts is None
    ):
        parser.error(
            "--publish-host exige --publish-identity "
            "et --publish-known-hosts."
        )

    if args.dry_run and (
        args.bundle is not None
        or publish_requested
    ):
        parser.error(
            "--bundle/--publish-host ne sont pas compatibles "
            "avec --dry-run."
        )

    try:
        result = execute_recovery_plan(
            args.plan,
            args.output,
            args.ilias_root,
            args.client,
            args.project_root,
            args.dry_run,
        )

        if result["success"] and (
            args.bundle is not None
            or publish_requested
        ):
            bundle_path = args.bundle
            if bundle_path is None:
                bundle_path = args.output.with_name(
                    args.output.name + ".tar.gz"
                )

            bundle = build_recovery_bundle(
                args.output,
                bundle_path,
            )
            result["bundle"] = bundle

            if publish_requested:
                result["publication"] = publish_recovery_bundle(
                    Path(bundle["bundle"]),
                    host=args.publish_host,
                    user=args.publish_user,
                    identity_file=args.publish_identity,
                    known_hosts_file=args.publish_known_hosts,
                    port=args.publish_port,
                    remote_name=args.publish_name,
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
