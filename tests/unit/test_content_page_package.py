from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.content_page_package import (
    enrich_document_content_pages,
    extract_content_page_assets,
)
from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


def _write(archive: zipfile.ZipFile, name: str, content: str | bytes) -> None:
    archive.writestr(name, content)


def test_content_page_enrichment_and_asset_extraction(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    output = tmp_path / "package"
    base = "set_2/123__0__copa_789"

    with zipfile.ZipFile(archive_path, "w") as archive:
        _write(
            archive,
            "manifest.xml",
            """<Manifest>
<ExportSet Path='set_1/123__0__crs_504' Type='crs'/>
<ExportSet Path='set_2/123__0__copa_789' Type='copa'/>
</Manifest>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/ContentPage/set_0/export.xml",
            """<Export><ExportItem Id='789'><DataSet><Rec><Copa>
<id>789</id><title>Page test migration</title>
<description>Description POC</description>
</Copa></Rec></DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/COPage/set_0/export.xml",
            """<Export><ExportItem Id='copa:789'>
<PageObject Language='fr' Active='1'>
<PageContent><MediaObject><MediaAlias OriginId='il_0_mob_790'/></MediaObject></PageContent>
<PageContent><FileList><FileItem>
<Identifier Catalog='ILIAS' Entry='il_0_file_791'/>
<Location>doc.pdf</Location><Format>application/pdf</Format>
</FileItem></FileList></PageContent>
</PageObject></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/export.xml",
            """<Export><ExportItem Id='790'><DataSet>
<Rec><Mob><Id>790</Id><Title>image.png</Title>
<MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer>
</Mob></Rec>
<Rec><MobMediaItem><Id>1</Id><Purpose>Standard</Purpose>
<Location>image.png</Location><LocationType>LocalFile</LocationType>
<Format>image/png</Format></MobMediaItem></Rec>
</DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/File/set_0/export.xml",
            """<Export><ExportItem Id='791'>
<File obj_id='il_0_file_791' size='3' type='application/pdf'>
<Filename>doc.pdf</Filename><Title>doc.pdf</Title><Description></Description>
<Versions><Version>components/ILIAS/File/set_0/expDir_1/doc.pdf</Version></Versions>
</File></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1/image.png",
            b"png",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/File/set_0/expDir_1/doc.pdf",
            b"pdf",
        )

    item = MigrationItem(
        source_id="270",
        type="copa",
        title="Page test migration",
        metadata={"obj_id": "789", "ref_id": "270", "ilias_type": "copa"},
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="Cours", items=[item])
    )

    enrichment = enrich_document_content_pages(document, archive_path)
    assert enrichment == {"detected": 1, "matched": 1, "missing": 0}
    assert item.type == "content_page"
    assert item.description == "Description POC"
    assert item.metadata["content_page_media_count"] == 1
    assert item.metadata["content_page_file_count"] == 1

    result = extract_content_page_assets(document, archive_path, output)
    assert result["missing"] == []
    assert result["extracted"]["content_page_structures"] == 1
    assert result["extracted"]["content_page_media_files"] == 1
    assert result["extracted"]["content_page_files"] == 1

    structure_path = output / "content_pages" / "270" / "structure.json"
    assert structure_path.is_file()
    structure = json.loads(structure_path.read_text(encoding="utf-8"))
    assert structure["source"]["ref_id"] == "270"
    assert structure["media"]["790"]["items"][0]["migration_path"].endswith(
        "image.png"
    )
    assert structure["files"]["791"]["migration_path"].endswith("doc.pdf")
    assert item.metadata["migration_structure_path"] == "content_pages/270/structure.json"
    assert "content_page_structure" not in item.metadata
