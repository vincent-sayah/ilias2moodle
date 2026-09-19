# ruff: noqa: E501
from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.wiki import parse_wikis


def _write(archive: zipfile.ZipFile, name: str, content: str | bytes) -> None:
    archive.writestr(name, content)


def test_wiki_parser_preserves_pages_links_media_and_history_policy(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_31/1800000000__0__wiki_900"

    with zipfile.ZipFile(archive_path, "w") as archive:
        _write(
            archive,
            "manifest.xml",
            """<?xml version='1.0'?>
<Manifest>
<ExportSet Path='set_1/1800000000__0__crs_504' Type='crs'/>
<ExportSet Path='set_31/1800000000__0__wiki_900' Type='wiki'/>
</Manifest>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/Wiki/set_0/export.xml",
            """<Export><ExportItem Id='900'><DataSet>
<Rec Entity='wiki'><Wiki><Id>900</Id><Title>wiki test migration</Title><Description>Wiki POC</Description><StartPage>Accueil</StartPage><PageToc>1</PageToc></Wiki></Rec>
<Rec Entity='wpg'><Wpg><Id>10</Id><Title>Accueil</Title><WikiId>900</WikiId><Blocked>0</Blocked><Lang>fr</Lang></Wpg></Rec>
<Rec Entity='wpg'><Wpg><Id>11</Id><Title>Page 2</Title><WikiId>900</WikiId><Blocked>0</Blocked><Lang>fr</Lang></Wpg></Rec>
<Rec Entity='wpg'><Wpg><Id>12</Id><Title>Media</Title><WikiId>900</WikiId><Blocked>0</Blocked><Lang>fr</Lang></Wpg></Rec>
<Rec Entity='wiki_imp_page'><WikiImpPage><WikiId>900</WikiId><PageId>10</PageId><Ord>1</Ord><Indent>0</Indent></WikiImpPage></Rec>
</DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/COPage/set_0/export.xml",
            """<Export>
<ExportItem Id='wpg:10'><PageObject Language='fr' Active='1'>
<PageContent><Paragraph Language='fr' Characteristic='Headline1'>Accueil</Paragraph></PageContent>
<PageContent><Paragraph Language='fr' Characteristic='Standard'>Aller vers <IntLink Target='il__wpg_11'>Page 2</IntLink></Paragraph></PageContent>
</PageObject></ExportItem>
<ExportItem Id='wpg:11'><PageObject Language='fr' Active='1'>
<PageContent><Paragraph Language='fr' Characteristic='Standard'>Retour <IntLink Target='il__wpg_10'>Accueil</IntLink></Paragraph></PageContent>
</PageObject></ExportItem>
<ExportItem Id='wpg:12'><PageObject Language='fr' Active='1'>
<PageContent><MediaObject><MediaAlias OriginId='il_0_mob_901'/><MediaAliasItem Purpose='Standard'><Layout HorizontalAlign='Left'/></MediaAliasItem></MediaObject></PageContent>
</PageObject></ExportItem>
</Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/export.xml",
            """<Export>
<ExportItem Id='901'><DataSet>
<Rec><Mob><Id>901</Id><Title>wiki-image.png</Title><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer></Mob></Rec>
<Rec><MobMediaItem><Id>70</Id><MobId>901</MobId><Purpose>Standard</Purpose><Location>wiki-image.png</Location><LocationType>LocalFile</LocationType><Format>image/png</Format></MobMediaItem></Rec>
</DataSet></ExportItem>
</Export>""",
        )
        _write(archive, f"{base}/components/ILIAS/File/set_0/export.xml", "<Export/>")
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1/wiki-image.png",
            b"wiki-image",
        )

    wikis = parse_wikis(archive_path)
    assert len(wikis) == 1
    wiki = wikis[0]

    assert wiki["source"]["object_id"] == "900"
    assert wiki["title"] == "wiki test migration"
    assert wiki["description"] == "Wiki POC"
    assert wiki["start_page"] == {"title": "Accueil", "source_id": "10"}
    assert len(wiki["pages"]) == 3
    assert wiki["pages"][0]["important_page"] == {"order": 1, "indent": 0}

    accueil = wiki["pages"][0]
    assert accueil["content"]["status"] == "ok"
    assert accueil["internal_links"][0]["scope"] == "wiki_page"
    assert accueil["internal_links"][0]["source_id"] == "11"

    page2 = wiki["pages"][1]
    assert page2["internal_links"][0]["source_id"] == "10"

    media = wiki["pages"][2]
    assert media["content"]["blocks"][0]["type"] == "media"
    assert media["content"]["blocks"][0]["source_id"] == "901"
    assert wiki["media"]["901"]["items"][0]["archive_path"].endswith("wiki-image.png")

    assert wiki["history"]["source_export_contains_history"] is False
    assert wiki["history"]["migration_policy"] == "current_pages_only"
    assert wiki["history"]["authors_migrated"] is False
    assert wiki["unsupported_components"] == []
    assert len(wiki["internal_links"]) == 2
    json.dumps(wiki, ensure_ascii=False)
