from __future__ import annotations

import argparse
import json
from pathlib import Path

from ilias2moodle.config import Settings
from ilias2moodle.ilias.demo import DemoIliasClient
from ilias2moodle.ilias.soap import SoapIliasClient
from ilias2moodle.model import MigrationDocument
from ilias2moodle.report import write_reports


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="ilias2moodle",
        description="Migration semi-automatisée ILIAS 10 vers Moodle 4.5",
    )
    parser.add_argument("--version", action="version", version="ILIAS2Moodle 0.1.0")

    subparsers = parser.add_subparsers(dest="command", required=True)
    analyse = subparsers.add_parser(
        "analyse", help="Analyser un cours ILIAS et générer le format intermédiaire"
    )
    analyse.add_argument("--course", required=True, help="Identifiant/ref_id du cours ILIAS")
    analyse.add_argument(
        "--output", required=True, type=Path, help="Répertoire de sortie des rapports"
    )
    analyse.add_argument(
        "--dry-run",
        action="store_true",
        help="Signaler explicitement qu’aucune écriture Moodle ne doit être effectuée",
    )
    return parser


def _client_from_settings(settings: Settings):
    if settings.ilias_mode == "demo":
        return DemoIliasClient()
    if settings.ilias_mode == "soap":
        return SoapIliasClient(settings)
    raise ValueError(f"ILIAS_MODE non supporté : {settings.ilias_mode}")


def _analyse(course_id: str, output: Path, dry_run: bool) -> int:
    settings = Settings.from_env()
    client = _client_from_settings(settings)
    course = client.get_course(course_id)
    document = MigrationDocument(course=course)
    report = write_reports(document, output)

    summary = {
        "mode": settings.ilias_mode,
        "dry_run": dry_run,
        "course": course.source_id,
        "output": str(output),
        "total_items": report["total_items"],
        "unsupported_count": report["unsupported_count"],
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    args = parser.parse_args(argv)

    if args.command == "analyse":
        return _analyse(args.course, args.output, args.dry_run)

    parser.error("Commande inconnue")
    return 2
