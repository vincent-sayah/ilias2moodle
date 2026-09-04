from __future__ import annotations

import zipfile
from pathlib import Path

from ilias2moodle.ilias.export_parser import IliasExportParser
from ilias2moodle.model import MigrationDocument
from ilias2moodle.package_builder import MigrationPackageBuilder


def _write_zip(path: Path) -> None:
    root_manifest = """<?xml version=\"1.0\"?>
<Manifest MainEntity=\"crs\" Title=\"Cours test\" InstallationId=\"0\" InstallationUrl=\"http://ilias.test\">
  <ExportSet Path=\"set_1/export__0__crs_100\" Type=\"crs\"/>
  <ExportSet Path=\"set_2/export__0__fold_101\" Type=\"fold\"/>
  <ExportSet Path=\"set_3/export__0__file_102\" Type=\"file\"/>
</Manifest>
"""
    container = """<?xml version=\"1.0\"?>
<exp:Export xmlns:exp=\"http://www.ilias.de/Services/Export/exp/4_1\">
  <exp:ExportItem Id=\"100\">
    <Items>
      <Item RefId=\"10\" Id=\"100\" Title=\"Cours test\" Type=\"crs\">
        <Item RefId=\"11\" Id=\"101\" Title=\"Dossier\" Type=\"fold\">
          <Item RefId=\"12\" Id=\"102\" Title=\"document.pdf\" Type=\"file\"/>
        </Item>
      </Item>
    </Items>
  </exp:ExportItem>
</exp:Export>
"""
    file_export = """<?xml version=\"1.0\"?>
<exp:Export xmlns:exp=\"http://www.ilias.de/Services/Export/exp/4_1\">
  <exp:ExportItem Id=\"102\">
    <File type=\"application/pdf\" size=\"1234\">
      <Filename>document.pdf</Filename>
      <Title>document.pdf</Title>
      <Description>PDF test</Description>
      <Versions><Version>components/ILIAS/File/set_0/expDir_1/1_document.pdf</Version></Versions>
    </File>
  </exp:ExportItem>
</exp:Export>
"""
    with zipfile.ZipFile(path, "w") as archive:
        archive.writestr("/manifest.xml", root_manifest)
        archive.writestr(
            "set_1/export__0__crs_100/components/ILIAS/Container/set_0/export.xml",
            container,
        )
        archive.writestr(
            "set_3/export__0__file_102/components/ILIAS/File/set_0/export.xml",
            file_export,
        )
        archive.writestr(
            "set_3/export__0__file_102/components/ILIAS/File/set_0/expDir_1/1_document.pdf",
            b"%PDF-test",
        )


def test_native_export_parser_rebuilds_tree(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    _write_zip(archive_path)

    with IliasExportParser(archive_path) as parser:
        course = parser.parse_course()

    assert course.source_id == "10"
    assert course.title == "Cours test"
    assert course.metadata["obj_id"] == "100"
    assert len(course.items) == 1

    folder = course.items[0]
    assert folder.source_id == "11"
    assert folder.type == "folder"
    assert len(folder.items) == 1

    file_item = folder.items[0]
    assert file_item.source_id == "12"
    assert file_item.type == "file"
    assert file_item.metadata["filename"] == "document.pdf"
    assert file_item.metadata["mime_type"] == "application/pdf"
    assert file_item.metadata["size"] == 1234
    assert file_item.description == "PDF test"


def test_package_builder_extracts_native_file(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    _write_zip(archive_path)

    with IliasExportParser(archive_path) as parser:
        course = parser.parse_course()

    document = MigrationDocument(
        course=course,
        source={"lms": "ILIAS", "version": "10.5"},
    )
    output = tmp_path / "package"
    result = MigrationPackageBuilder(archive_path, output).build(document)

    extracted = output / "files" / "12" / "document.pdf"
    assert extracted.read_bytes() == b"%PDF-test"
    assert result["package"]["extracted"]["files"] == 1
    assert result["package"]["missing_count"] == 0
    assert course.items[0].items[0].metadata["migration_path"] == "files/12/document.pdf"
    assert (output / "migration.json").is_file()
    assert (output / "package.json").is_file()
