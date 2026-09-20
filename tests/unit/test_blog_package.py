from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.blog_package import (
    enrich_document_blogs,
    extract_blog_assets,
)
from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


def _write(archive: zipfile.ZipFile, name: str, content: str | bytes) -> None:
    archive.writestr(name, content)


def test_blog_enrichment_and_asset_extraction(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    output = tmp_path / "package"
    base = "set_11/1789835852__0__blog_732"

    with zipfile.ZipFile(archive_path, "w") as archive:
        _write(
            archive,
            "manifest.xml",
            """<Manifest>
<ExportSet Path='set_1/1789835852__0__crs_504' Type='crs'/>
<ExportSet Path='set_11/1789835852__0__blog_732' Type='blog'/>
</Manifest>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/Blog/set_0/export.xml",
            """<Export><ExportItem Id='732'><DataSet>
<Rec Entity='blog'><Blog><Id>732</Id><Title>blog</Title><Description>POC</Description><Keywords>1</Keywords><Authors>0</Authors></Blog></Rec>
<Rec Entity='blog_posting'><BlogPosting><Id>13</Id><BlogId>732</BlogId><Title>titre 2</Title><Created>2026-09-06 15:44:05</Created><Author>il_0_usr_6</Author><Approved>0</Approved><LastWithdrawn/></BlogPosting></Rec>
</DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/COPage/set_0/export.xml",
            """<Export><ExportItem Id='blp:13'><PageObject Language='-' Active='1'>
<PageContent><MediaObject><MediaAlias OriginId='il_0_mob_735'/></MediaObject></PageContent>
</PageObject></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/export.xml",
            """<Export><ExportItem Id='735'><DataSet>
<Rec><Mob><Id>735</Id><Title>tous.png</Title><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer></Mob></Rec>
<Rec><MobMediaItem><Id>38</Id><Purpose>Standard</Purpose><Location>tous.png</Location><LocationType>LocalFile</LocationType><Format>image/png</Format></MobMediaItem></Rec>
</DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/File/set_0/export.xml",
            "<Export></Export>",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1/tous.png",
            b"png-content",
        )

    item = MigrationItem(
        source_id="247",
        type="blog",
        title="blog",
        metadata={"obj_id": "732", "ref_id": "247", "ilias_type": "blog"},
    )
    document = MigrationDocument(
        course=CourseExport(source_id="128", title="Cours", items=[item])
    )

    enrichment = enrich_document_blogs(document, archive_path)
    assert enrichment == {"detected": 1, "matched": 1, "missing": 0}
    assert item.type == "blog"
    assert item.description == "POC"
    assert item.metadata["blog_posting_count"] == 1
    assert item.metadata["blog_media_count"] == 1
    assert item.metadata["blog_file_count"] == 0
    assert item.metadata["blog_source_author_count"] == 1

    result = extract_blog_assets(document, archive_path, output)
    assert result["managed_directory"] == "blogs"
    assert result["missing"] == []
    assert result["extracted"]["blog_structures"] == 1
    assert result["extracted"]["blog_postings"] == 1
    assert result["extracted"]["blog_media_files"] == 1
    assert result["extracted"]["blog_files"] == 0

    image = output / "blogs/247/media/735/tous.png"
    assert image.read_bytes() == b"png-content"

    structure_path = output / "blogs/247/structure.json"
    structure = json.loads(structure_path.read_text(encoding="utf-8"))
    media = structure["media"]["735"]["items"][0]
    assert media["migration_path"] == "blogs/247/media/735/tous.png"
    assert media["migration_size"] == len(b"png-content")
    assert len(media["migration_sha256"]) == 64
    assert (
        structure["postings"][0]["content"]["blocks"][0]["media"]["items"][0][
            "migration_path"
        ]
        == "blogs/247/media/735/tous.png"
    )
    assert item.metadata["migration_structure_path"] == "blogs/247/structure.json"
    assert "blog_structure" not in item.metadata
