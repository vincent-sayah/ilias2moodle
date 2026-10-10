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

from ilias2moodle.recovery_transport import (  # noqa: E402
    publish_recovery_bundle,
)


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Publier un bundle recovery vers le récepteur "
            "SSH forcé du serveur Moodle."
        )
    )
    parser.add_argument(
        "--bundle",
        required=True,
        type=Path,
        help="Bundle .tar.gz/.tgz à publier.",
    )
    parser.add_argument(
        "--host",
        required=True,
        help="Hôte ou IP du serveur Moodle.",
    )
    parser.add_argument(
        "--user",
        default="ilias2moodlepush",
        help="Utilisateur SSH dédié au dépôt recovery.",
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
        help="Fichier known_hosts dédié et vérifié.",
    )
    parser.add_argument(
        "--port",
        type=int,
        default=22,
        help="Port SSH du serveur Moodle.",
    )
    parser.add_argument(
        "--name",
        help="Nom distant du bundle.",
    )

    args = parser.parse_args()

    try:
        result = publish_recovery_bundle(
            args.bundle,
            host=args.host,
            user=args.user,
            identity_file=args.identity,
            known_hosts_file=args.known_hosts,
            port=args.port,
            remote_name=args.name,
        )
    except Exception as exc:
        print(
            json.dumps(
                {
                    "mode": "publish_recovery_bundle",
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
            {
                "mode": "publish_recovery_bundle",
                "success": True,
                **result,
            },
            ensure_ascii=False,
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
