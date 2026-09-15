# ruff: noqa: E501
from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.content_page import parse_content_pages


def _write(archive: zipfile.ZipFile, name: str, content: str) -> None:
    archive.writestr(name, content)


def test_content_page_parser_preserves_inline_links_media_files_and_layout(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_2/123__0__copa_789"

    with zipfile.ZipFile(archive_path, "w") as archive:
        _write(
            archive,
            "manifest.xml",
            """<?xml version='1.0'?>
<Manifest><ExportSet Path='set_1/123__0__crs_504' Type='crs'/>
<ExportSet Path='set_2/123__0__copa_789' Type='copa'/></Manifest>""",
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
            """<Export><ExportItem Id='copa:789'><PageObject Language='fr' Active='1'>
<PageContent><Paragraph Language='fr' Characteristic='Headline1'><Strong>Titre</Strong></Paragraph></PageContent>
<PageContent><Paragraph Language='fr' Characteristic='Standard'>Voir <ExtLink Href='https://example.org'>externe</ExtLink> et <IntLink Target='il_0_htlm_518_134' Type='RepositoryItem'>interne</IntLink>.</Paragraph></PageContent>
<PageContent><MediaObject><MediaAlias OriginId='il_0_mob_790'/><MediaAliasItem Purpose='Standard'><Layout HorizontalAlign='Center'/></MediaAliasItem></MediaObject></PageContent>
<PageContent><FileList><Title>Fichiers</Title><FileItem><Identifier Catalog='ILIAS' Entry='il_0_file_791'/><Location>doc.pdf</Location><Format>application/pdf</Format></FileItem></FileList></PageContent>
<PageContent><Section Characteristic='Attention'><PageContent><Tabs Type='VerticalAccordion' Behavior='AllClosed'><Tab><PageContent><Paragraph>Onglet</Paragraph></PageContent><TabCaption>Un</TabCaption></Tab></Tabs></PageContent></Section></PageContent>
<PageContent><Grid><GridCell WIDTH_M='6'><PageContent><Paragraph>Colonne</Paragraph></PageContent></GridCell></Grid></PageContent>
</PageObject></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/export.xml",
            """<Export><ExportItem Id='790'><DataSet><Rec><Mob><Id>790</Id><Title>image.png</Title><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer></Mob></Rec><Rec><MobMediaItem><Id>1</Id><Purpose>Standard</Purpose><Location>image.png</Location><LocationType>LocalFile</LocationType><Format>image/png</Format></MobMediaItem></Rec></DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/File/set_0/export.xml",
            """<Export><ExportItem Id='791'><File obj_id='il_0_file_791' size='10' type='application/pdf'><Filename>doc.pdf</Filename><Title>doc.pdf</Title><Description></Description><Versions><Version>components/ILIAS/File/set_0/expDir_1/doc.pdf</Version></Versions></File></ExportItem></Export>""",
        )

    pages = parse_content_pages(archive_path)
    assert len(pages) == 1

    page = pages[0]
    assert page["source"]["object_id"] == "789"
    assert page["title"] == "Page test migration"
    assert page["description"] == "Description POC"
    assert page["active"] == "1"

    first = page["blocks"][0]
    assert first["type"] == "paragraph"
    assert first["inline"][0]["type"] == "strong"

    links = page["blocks"][1]["inline"]
    external = next(part for part in links if part["type"] == "external_link")
    internal = next(part for part in links if part["type"] == "internal_link")
    assert external["href"] == "https://example.org"
    assert internal["target_type"] == "htlm"
    assert internal["source_ref_id"] == "134"

    media = page["blocks"][2]
    assert media["source_id"] == "790"
    assert media["media"]["items"][0]["archive_path"].endswith("image.png")

    files = page["blocks"][3]
    assert files["files"][0]["source_id"] == "791"
    assert files["files"][0]["file"]["archive_path"].endswith("doc.pdf")

    section = page["blocks"][4]
    assert section["type"] == "section"
    assert section["blocks"][0]["type"] == "tabs"
    assert section["blocks"][0]["tabs"][0]["caption"] == "Un"

    grid = page["blocks"][5]
    assert grid["type"] == "grid"
    assert grid["cells"][0]["widths"]["width_m"] == "6"
    assert page["unsupported_components"] == []

    json.dumps(page, ensure_ascii=False)
