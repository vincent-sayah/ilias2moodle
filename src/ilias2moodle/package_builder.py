from __future__ import annotations

import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem
from ilias2moodle.report import write_reports

MANAGED_DIRS = (
    "files",
    "media",
    "scorm",
    "html",
    "tests",
    "question_pools",
)


def _walk(items: Iterable[MigrationItem]) -> Iterable[MigrationItem]:
    for item in items:
        yield item
        yield from _walk(item.items)


def _safe_archive_relative(path: str) -> PurePosixPath:
    candidate = PurePosixPath(path.lstrip("/"))
    if not candidate.parts or ".." in candidate.parts:
        raise ValueError(f"Chemin d'archive non sûr : {path}")
    return candidate


class MigrationPackageBuilder:
    """Extrait les ressources d'un export ILIAS dans un package normalisé et rejouable."""

    def __init__(self, archive_path: Path, output_dir: Path) -> None:
        self.archive_path = archive_path
        self.output_dir = output_dir
        self.missing: list[dict[str, str]] = []
        self.extracted = {
            "files": 0,
            "media": 0,
            "scorm_packages": 0,
            "html_files": 0,
            "test_files": 0,
            "question_pool_files": 0,
        }

    def build(self, document: MigrationDocument) -> dict[str, Any]:
        if not self.archive_path.is_file():
            raise FileNotFoundError(f"Archive ILIAS introuvable : {self.archive_path}")

        self.output_dir.mkdir(parents=True, exist_ok=True)
        self._reset_managed_dirs()

        with zipfile.ZipFile(self.archive_path) as archive:
            self._members = {name.lstrip("/"): name for name in archive.namelist()}
            self._extract_course_media(archive, document.course)
            for item in _walk(document.course.items):
                self._extract_item(archive, item)

        package = {
            "schema_version": document.schema_version,
            "course": {
                "source_id": document.course.source_id,
                "title": document.course.title,
            },
            "source_archive": self.archive_path.name,
            "managed_directories": list(MANAGED_DIRS),
            "extracted": self.extracted,
            "missing_count": len(self.missing),
            "missing": self.missing,
        }
        (self.output_dir / "package.json").write_text(
            json.dumps(package, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        report = write_reports(document, self.output_dir)
        return {"package": package, "report": report}

    def _reset_managed_dirs(self) -> None:
        for dirname in MANAGED_DIRS:
            path = self.output_dir / dirname
            if path.exists():
                shutil.rmtree(path)

    def _record_missing(self, source_id: str, kind: str, source_path: str) -> None:
        self.missing.append(
            {
                "source_id": source_id,
                "kind": kind,
                "source_path": source_path,
            }
        )

    def _copy_member(
        self,
        archive: zipfile.ZipFile,
        source_path: str,
        destination_relative: PurePosixPath,
    ) -> bool:
        source = _safe_archive_relative(source_path).as_posix()
        actual_name = self._members.get(source)
        if actual_name is None:
            return False

        destination = self.output_dir.joinpath(*destination_relative.parts)
        destination.parent.mkdir(parents=True, exist_ok=True)
        with archive.open(actual_name) as src, destination.open("wb") as dst:
            shutil.copyfileobj(src, dst)
        return True

    def _extract_course_media(self, archive: zipfile.ZipFile, course: CourseExport) -> None:
        media_objects = course.metadata.get("media_objects", [])
        for index, media in enumerate(media_objects, start=1):
            source_path = str(media.get("archive_path", ""))
            if not source_path:
                continue
            obj_id = str(media.get("obj_id", "")) or f"media-{index}"
            filename = Path(str(media.get("title", "")) or source_path).name
            destination = PurePosixPath("media", obj_id, filename)
            if self._copy_member(archive, source_path, destination):
                media["migration_path"] = destination.as_posix()
                self.extracted["media"] += 1
            else:
                self._record_missing(course.source_id, "media", source_path)

    def _extract_item(self, archive: zipfile.ZipFile, item: MigrationItem) -> None:
        if item.type == "file":
            self._extract_file(archive, item)
        elif item.type == "scorm":
            self._extract_scorm(archive, item)
        elif item.type == "html_module":
            self._extract_html_module(archive, item)
        elif item.type == "test":
            self._extract_test(archive, item)
        elif item.type == "question_pool":
            self._extract_question_pool(archive, item)

    def _extract_file(self, archive: zipfile.ZipFile, item: MigrationItem) -> None:
        source_path = str(item.metadata.get("archive_path", ""))
        if not source_path:
            return
        filename = Path(str(item.metadata.get("filename", "")) or source_path).name
        destination = PurePosixPath("files", item.source_id, filename)
        if self._copy_member(archive, source_path, destination):
            item.metadata["migration_path"] = destination.as_posix()
            self.extracted["files"] += 1
        else:
            self._record_missing(item.source_id, "file", source_path)

    def _extract_scorm(self, archive: zipfile.ZipFile, item: MigrationItem) -> None:
        source_path = str(item.metadata.get("package_path", ""))
        if not source_path:
            return
        destination = PurePosixPath("scorm", item.source_id, "content.zip")
        if self._copy_member(archive, source_path, destination):
            item.metadata["migration_package_path"] = destination.as_posix()
            self.extracted["scorm_packages"] += 1
        else:
            self._record_missing(item.source_id, "scorm", source_path)

    def _extract_html_module(self, archive: zipfile.ZipFile, item: MigrationItem) -> None:
        source_dir = str(item.metadata.get("content_dir", ""))
        if not source_dir:
            return
        prefix = _safe_archive_relative(source_dir).as_posix().rstrip("/") + "/"
        copied = 0

        for canonical_name in sorted(self._members):
            if not canonical_name.startswith(prefix) or canonical_name.endswith("/"):
                continue
            relative = PurePosixPath(canonical_name[len(prefix) :])
            if not relative.parts or ".." in relative.parts:
                continue
            destination = PurePosixPath("html", item.source_id, "content", *relative.parts)
            if self._copy_member(archive, canonical_name, destination):
                copied += 1

        if copied:
            item.metadata["migration_content_dir"] = PurePosixPath(
                "html", item.source_id, "content"
            ).as_posix()
            start_file = str(item.metadata.get("start_file", "")).lstrip("/")
            if start_file:
                item.metadata["migration_start_file"] = PurePosixPath(
                    "html", item.source_id, "content", start_file
                ).as_posix()
            self.extracted["html_files"] += copied
        else:
            self._record_missing(item.source_id, "html_module", source_dir)

    def _extract_test(self, archive: zipfile.ZipFile, item: MigrationItem) -> None:
        mappings = (
            ("qti_path", "questions.xml", "migration_qti_path"),
            ("test_structure_path", "test-structure.xml", "migration_test_structure_path"),
        )
        for metadata_key, filename, migration_key in mappings:
            source_path = str(item.metadata.get(metadata_key, ""))
            if not source_path:
                continue
            destination = PurePosixPath("tests", item.source_id, filename)
            if self._copy_member(archive, source_path, destination):
                item.metadata[migration_key] = destination.as_posix()
                self.extracted["test_files"] += 1
            else:
                self._record_missing(item.source_id, "test", source_path)

    def _extract_question_pool(self, archive: zipfile.ZipFile, item: MigrationItem) -> None:
        migration_paths: list[str] = []
        for index, source_path in enumerate(item.metadata.get("question_export_files", []), start=1):
            source_path = str(source_path)
            filename = Path(source_path).name or f"questions-{index}.xml"
            destination = PurePosixPath("question_pools", item.source_id, filename)
            if self._copy_member(archive, source_path, destination):
                migration_paths.append(destination.as_posix())
                self.extracted["question_pool_files"] += 1
            else:
                self._record_missing(item.source_id, "question_pool", source_path)
        if migration_paths:
            item.metadata["migration_question_export_files"] = migration_paths
