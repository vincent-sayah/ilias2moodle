from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.export_parser import IliasExportParser
from ilias2moodle.model import MigrationDocument
from ilias2moodle.package_builder import MigrationPackageBuilder


def _write_learning_module_zip(path: Path) -> None:
    course_base = "set_1/export__0__crs_100"
    lm_base = "set_2/export__0__lm_721"

    root_manifest = f"""<?xml version="1.0"?>
<Manifest MainEntity="crs" Title="Cours test" InstallationId="0" InstallationUrl="http://ilias.test">
  <ExportSet Path="{course_base}" Type="crs"/>
  <ExportSet Path="{lm_base}" Type="lm"/>
</Manifest>
"""
    container = """<?xml version="1.0"?>
<exp:Export xmlns:exp="http://www.ilias.de/Services/Export/exp/4_1">
  <exp:ExportItem Id="100">
    <Items>
      <Item RefId="10" Id="100" Title="Cours test" Type="crs">
        <Item RefId="243" Id="721" Title="module ilias" Type="lm"/>
      </Item>
    </Items>
  </exp:ExportItem>
</exp:Export>
"""
    learning_module = """<?xml version="1.0"?>
<exp:Export xmlns:exp="http://www.ilias.de/Services/Export/exp/4_1">
  <exp:ExportItem Id="721">
    <DataSet>
      <Rec Entity="lm"><Lm><Id>721</Id><Title>module ilias</Title><Description>module test</Description><TocActive>y</TocActive></Lm></Rec>
      <Rec Entity="lm_tree"><LmTree><LmId>721</LmId><Child>1</Child><Parent>0</Parent><Depth>1</Depth><Type>du</Type><Title>dummy</Title><Active>y</Active></LmTree></Rec>
      <Rec Entity="lm_tree"><LmTree><LmId>721</LmId><Child>2425</Child><Parent>1</Parent><Depth>2</Depth><Type>st</Type><Title>Chapitre1</Title><ShortTitle>chapitre 1</ShortTitle><Active>y</Active></LmTree></Rec>
      <Rec Entity="lm_tree"><LmTree><LmId>721</LmId><Child>2426</Child><Parent>2425</Parent><Depth>3</Depth><Type>pg</Type><Title>Nouvelle page</Title><ShortTitle>page 1</ShortTitle><Active>y</Active></LmTree></Rec>
      <Rec Entity="lm_tree"><LmTree><LmId>721</LmId><Child>2427</Child><Parent>2425</Parent><Depth>3</Depth><Type>pg</Type><Title>page2</Title><Active>y</Active></LmTree></Rec>
    </DataSet>
  </exp:ExportItem>
</exp:Export>
"""
    copage = """<?xml version="1.0"?>
<exp:Export xmlns:exp="http://www.ilias.de/Services/Export/exp/4_1">
  <exp:ExportItem Id="lm:2426">
    <PageObject Language="-" Active="1">
      <PageContent><MediaObject><MediaAlias OriginId="il_0_mob_722"/><MediaAliasItem Purpose="Standard"><Layout HorizontalAlign="Left"/></MediaAliasItem></MediaObject></PageContent>
      <PageContent><Paragraph Language="fr" Characteristic="Standard">ceci est une nouvelle page avec du texte</Paragraph></PageContent>
    </PageObject>
  </exp:ExportItem>
  <exp:ExportItem Id="lm:2427">
    <PageObject Language="-" Active="1">
      <PageContent><FileList><Title Language="fr">liste de fichier</Title><FileItem><Identifier Catalog="ILIAS" Entry="il_0_file_723"/><Location Type="LocalFile">document.pdf</Location><Format>application/pdf</Format></FileItem></FileList></PageContent>
    </PageObject>
  </exp:ExportItem>
</exp:Export>
"""
    media = """<?xml version="1.0"?>
<exp:Export xmlns:exp="http://www.ilias.de/Services/Export/exp/4_1">
  <exp:ExportItem Id="722"><DataSet><Rec><Mob><Id>722</Id><Title>vince.jpg</Title><Description></Description><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer></Mob></Rec><Rec><MobMediaItem><Id>28</Id><MobId>722</MobId><Halign>Left</Halign><Purpose>Standard</Purpose><Location>vince.jpg</Location><LocationType>LocalFile</LocationType><Format>image/jpeg</Format></MobMediaItem></Rec></DataSet></exp:ExportItem>
</exp:Export>
"""
    files = """<?xml version="1.0"?>
<exp:Export xmlns:exp="http://www.ilias.de/Services/Export/exp/4_1">
  <exp:ExportItem Id="723"><File obj_id="il_0_file_723" size="12" type="application/pdf"><Filename>document.pdf</Filename><Title>document.pdf</Title><Description></Description><Versions><Version>components/ILIAS/File/set_0/expDir_1/1_document.pdf</Version></Versions></File></exp:ExportItem>
</exp:Export>
"""

    with zipfile.ZipFile(path, "w") as archive:
        archive.writestr("manifest.xml", root_manifest)
        archive.writestr(
            f"{course_base}/components/ILIAS/Container/set_0/export.xml", container
        )
        archive.writestr(
            f"{lm_base}/components/ILIAS/LearningModule/set_0/export.xml",
            learning_module,
        )
        archive.writestr(
            f"{lm_base}/components/ILIAS/COPage/set_0/export.xml", copage
        )
        archive.writestr(
            f"{lm_base}/components/ILIAS/MediaObjects/set_0/export.xml", media
        )
        archive.writestr(f"{lm_base}/components/ILIAS/File/set_0/export.xml", files)
        archive.writestr(
            f"{lm_base}/components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1/vince.jpg",
            b"jpeg-test",
        )
        archive.writestr(
            f"{lm_base}/components/ILIAS/File/set_0/expDir_1/1_document.pdf",
            b"pdf-test",
        )


def test_learning_module_parser_reads_tree_pages_and_assets(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    _write_learning_module_zip(archive_path)

    with IliasExportParser(archive_path) as parser:
        course = parser.parse_course()

    item = course.items[0]
    assert item.type == "learning_module"
    assert item.source_id == "243"
    assert item.description == "module test"
    assert item.metadata["learning_module_chapter_count"] == 1
    assert item.metadata["learning_module_page_count"] == 2
    assert item.metadata["learning_module_media_count"] == 1
    assert item.metadata["learning_module_file_count"] == 1
    assert item.metadata["learning_module_unsupported_count"] == 0

    structure = item.metadata["learning_module_structure"]
    page1 = structure["pages"]["2426"]
    assert [block["type"] for block in page1["blocks"]] == ["media", "paragraph"]
    assert page1["blocks"][0]["source_id"] == "722"
    assert page1["blocks"][1]["text"] == "ceci est une nouvelle page avec du texte"

    page2 = structure["pages"]["2427"]
    assert page2["blocks"][0]["type"] == "file_list"
    assert page2["blocks"][0]["files"][0]["source_id"] == "723"


def test_learning_module_package_extracts_structure_media_and_files(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    _write_learning_module_zip(archive_path)

    with IliasExportParser(archive_path) as parser:
        course = parser.parse_course()

    document = MigrationDocument(
        course=course,
        source={"lms": "ILIAS", "version": "10.8"},
    )
    output = tmp_path / "package"
    result = MigrationPackageBuilder(archive_path, output).build(document)

    item = course.items[0]
    assert item.metadata["migration_structure_path"] == "learning_modules/243/structure.json"
    assert "learning_module_structure" not in item.metadata
    assert item.metadata["migration_media_file_count"] == 1
    assert item.metadata["migration_embedded_file_count"] == 1

    structure = json.loads(
        (output / "learning_modules" / "243" / "structure.json").read_text(encoding="utf-8")
    )
    assert structure["media"]["722"]["items"][0]["migration_path"] == (
        "learning_modules/243/media/722/vince.jpg"
    )
    assert structure["files"]["723"]["migration_path"] == (
        "learning_modules/243/files/723/document.pdf"
    )
    assert (output / "learning_modules" / "243" / "media" / "722" / "vince.jpg").read_bytes() == b"jpeg-test"
    assert (output / "learning_modules" / "243" / "files" / "723" / "document.pdf").read_bytes() == b"pdf-test"

    extracted = result["package"]["extracted"]
    assert extracted["learning_module_structures"] == 1
    assert extracted["learning_module_media_files"] == 1
    assert extracted["learning_module_files"] == 1
    assert result["package"]["missing_count"] == 0
