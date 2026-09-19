from __future__ import annotations

import argparse
import json
from pathlib import Path

from ilias2moodle.content_page_package import (
    enrich_document_content_pages,
    extract_content_page_assets,
)
from ilias2moodle.exercise_package import (
    enrich_document_exercises,
    extract_exercise_assets,
    recover_exercise_instruction_files,
)
from ilias2moodle.forum_package import (
    enrich_document_forums,
    extract_forum_assets,
)
from ilias2moodle.glossary_package import (
    enrich_document_glossaries,
    extract_glossary_assets,
)
from ilias2moodle.ilias.export_parser import IliasExportParser
from ilias2moodle.model import MigrationDocument
from ilias2moodle.package_builder import MigrationPackageBuilder
from ilias2moodle.report import write_reports
from ilias2moodle.wiki_package import (
    enrich_document_wikis,
    extract_wiki_assets,
)


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
    prepare_export.add_argument(
        "--exercise-irss-recovery",
        type=Path,
        default=None,
        help=(
            "Répertoire contenant les collections IRSS récupérées "
            "depuis ILIAS, indexées par UUID."
        ),
    )
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

    document = MigrationDocument(
        course=course,
        source={"lms": "ILIAS", "version": ilias_version},
    )
    enrich_document_content_pages(document, zip_path)
    enrich_document_glossaries(document, zip_path)
    enrich_document_wikis(document, zip_path)
    enrich_document_exercises(document, zip_path)
    enrich_document_forums(document, zip_path)
    return document


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


def _prepare_export(
    zip_path: Path,
    output: Path,
    ilias_version: str,
    exercise_irss_recovery: Path | None = None,
) -> int:
    document = _parse_export_document(zip_path, ilias_version)

    irss_result = {
        "recovered": {
            "collections_recovered": 0,
            "instruction_files_recovered": 0,
        },
        "missing": [],
    }

    if exercise_irss_recovery is not None:
        if not exercise_irss_recovery.is_dir():
            raise FileNotFoundError(
                "Répertoire IRSS introuvable : "
                f"{exercise_irss_recovery}"
            )

        irss_result = recover_exercise_instruction_files(
            document,
            exercise_irss_recovery,
        )
    content_page_result = extract_content_page_assets(document, zip_path, output)
    glossary_result = extract_glossary_assets(document, zip_path, output)
    wiki_result = extract_wiki_assets(document, zip_path, output)
    exercise_result = extract_exercise_assets(document, zip_path, output)
    forum_result = extract_forum_assets(document, zip_path, output)
    result = MigrationPackageBuilder(zip_path, output).build(document)
    package = result["package"]
    report = result["report"]

    extension_results = (
        content_page_result,
        glossary_result,
        wiki_result,
        exercise_result,
        forum_result,
    )
    for extension_result in extension_results:
        managed_directory = str(extension_result["managed_directory"])
        if managed_directory not in package["managed_directories"]:
            package["managed_directories"].append(managed_directory)
        package["extracted"].update(extension_result["extracted"])
        package["missing"].extend(extension_result["missing"])

    package["extracted"]["exercise_irss_collections_recovered"] = (
        irss_result["recovered"]["collections_recovered"]
    )
    package["extracted"]["exercise_irss_files_recovered"] = (
        irss_result["recovered"]["instruction_files_recovered"]
    )
    package["missing"].extend(irss_result["missing"])

    package["exercise_irss_recovery"] = {
        "enabled": exercise_irss_recovery is not None,
        "collections_recovered": (
            irss_result["recovered"]["collections_recovered"]
        ),
        "instruction_files_recovered": (
            irss_result["recovered"]["instruction_files_recovered"]
        ),
        "missing_count": len(irss_result["missing"]),
    }

    package["missing_count"] = len(package["missing"])
    (output / "package.json").write_text(
        json.dumps(package, ensure_ascii=False, indent=2), encoding="utf-8"
    )

    summary = {
        "mode": "prepare_export",
        "archive": str(zip_path),
        "course": document.course.source_id,
        "title": document.course.title,
        "output": str(output),
        "total_items": report["total_items"],
        "extracted": package["extracted"],
        "missing_count": package["missing_count"],
        "exercise_irss_recovery": package["exercise_irss_recovery"],
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
        return _prepare_export(
            args.zip_path,
            args.output,
            args.ilias_version,
            args.exercise_irss_recovery,
        )

    parser.error("Commande inconnue")
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
