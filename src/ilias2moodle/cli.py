from __future__ import annotations

import argparse
import json
from pathlib import Path

from ilias2moodle.ilias.export_parser import IliasExportParser
from ilias2moodle.model import MigrationDocument
from ilias2moodle.package_builder import MigrationPackageBuilder
from ilias2moodle.report import write_reports


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="ilias2moodle",
        description="Migration semi-automatisée ILIAS 10 vers Moodle 4.5",
    )
    parser.add_argument("--version", action="version", version="ILIAS2Moodle 0.1.0")

    subparsers = parser.add_subparsers(dest="command", required=True)

    analyse = subparsers.add_parser(
        "analyse", help="Analyser un cours ILIAS via le connecteur configuré"
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

    analyse_export = subparsers.add_parser(
        "analyse-export",
        help="Analyser un export natif XML/ZIP ILIAS sans connexion à l'instance source",
    )
    _add_export_arguments(analyse_export)
    analyse_export.add_argument(
        "--dry-run",
        action="store_true",
        help="Conservé pour cohérence CLI ; aucune écriture Moodle n'est faite par cette commande",
    )

    prepare_export = subparsers.add_parser(
        "prepare-export",
        help="Construire un package normalisé avec les ressources extraites de l'export ILIAS",
    )
    _add_export_arguments(prepare_export)
    return parser


def _add_export_arguments(command: argparse.ArgumentParser) -> None:
    command.add_argument(
        "--zip",
        required=True,
        type=Path,
        dest="zip_path",
        help="Archive ZIP exportée depuis ILIAS",
    )
    command.add_argument(
        "--output", required=True, type=Path, help="Répertoire de sortie des rapports ou du package"
    )
    command.add_argument(
        "--ilias-version",
        default="10",
        help="Version ILIAS source à inscrire dans migration.json (ex. 10.5)",
    )


def _client_from_settings(settings):
    from ilias2moodle.ilias.demo import DemoIliasClient
    from ilias2moodle.ilias.soap import SoapIliasClient

    if settings.ilias_mode == "demo":
        return DemoIliasClient()
    if settings.ilias_mode == "soap":
        return SoapIliasClient(settings)
    raise ValueError(f"ILIAS_MODE non supporté : {settings.ilias_mode}")


def _analyse(course_id: str, output: Path, dry_run: bool) -> int:
    from ilias2moodle.config import Settings

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


def _parse_export_document(zip_path: Path, ilias_version: str) -> MigrationDocument:
    if not zip_path.is_file():
        raise FileNotFoundError(f"Archive ILIAS introuvable : {zip_path}")

    with IliasExportParser(zip_path) as export_parser:
        course = export_parser.parse_course()

    return MigrationDocument(
        course=course,
        source={"lms": "ILIAS", "version": ilias_version},
    )


def _analyse_export(
    zip_path: Path, output: Path, ilias_version: str, dry_run: bool
) -> int:
    document = _parse_export_document(zip_path, ilias_version)
    report = write_reports(document, output)

    summary = {
        "mode": "native_export",
        "dry_run": dry_run,
        "archive": str(zip_path),
        "course": document.course.source_id,
        "title": document.course.title,
        "output": str(output),
        "total_items": report["total_items"],
        "counts_by_type": report["counts_by_type"],
        "unsupported_count": report["unsupported_count"],
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


def _prepare_export(zip_path: Path, output: Path, ilias_version: str) -> int:
    document = _parse_export_document(zip_path, ilias_version)
    result = MigrationPackageBuilder(zip_path, output).build(document)
    package = result["package"]
    report = result["report"]

    summary = {
        "mode": "prepare_export",
        "archive": str(zip_path),
        "course": document.course.source_id,
        "title": document.course.title,
        "output": str(output),
        "total_items": report["total_items"],
        "extracted": package["extracted"],
        "missing_count": package["missing_count"],
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    args = parser.parse_args(argv)

    if args.command == "analyse":
        return _analyse(args.course, args.output, args.dry_run)
    if args.command == "analyse-export":
        return _analyse_export(
            args.zip_path,
            args.output,
            args.ilias_version,
            args.dry_run,
        )
    if args.command == "prepare-export":
        return _prepare_export(args.zip_path, args.output, args.ilias_version)

    parser.error("Commande inconnue")
    return 2
