# ruff: noqa: E501
from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.glossary import GlossaryParser, parse_glossaries


def _write(archive: zipfile.ZipFile, name: str, content: str | bytes) -> None:
    archive.writestr(name, content)


def test_glossary_parser_preserves_terms_definitions_and_media(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_29/1789580035__0__glo_797"

    with zipfile.ZipFile(archive_path, "w") as archive:
        _write(
            archive,
            "manifest.xml",
            """<?xml version='1.0'?>
<Manifest>
<ExportSet Path='set_1/1789580035__0__crs_504' Type='crs'/>
<ExportSet Path='set_29/1789580035__0__glo_797' Type='glo'/>
</Manifest>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/Glossary/set_0/export.xml",
            """<Export><ExportItem Id='797'><DataSet>
<Rec Entity='glo'><Glo><Title>glossaire test migration</Title><Description></Description><Id>797</Id><Virtual>none</Virtual><PresMode>table</PresMode><SnippetLength>200</SnippetLength><ShowTax>0</ShowTax><GloMenuActive>y</GloMenuActive></Glo></Rec>
<Rec Entity='glo_term'><GloTerm><Id>6</Id><GloId>797</GloId><Term>Chat</Term><Language>fr</Language></GloTerm></Rec>
<Rec Entity='glo_term'><GloTerm><Id>7</Id><GloId>797</GloId><Term>chien</Term><Language>fr</Language></GloTerm></Rec>
</DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/COPage/set_0/export.xml",
            """<Export>
<ExportItem Id='term:6'><PageObject Language='-' Active='1'>
<PageContent><Paragraph Language='fr' Characteristic='Standard'>ceci est un chat</Paragraph></PageContent>
<PageContent><MediaObject><MediaAlias OriginId='il_0_mob_798'/><MediaAliasItem Purpose='Standard'><Layout HorizontalAlign='Left'/></MediaAliasItem></MediaObject></PageContent>
</PageObject></ExportItem>
<ExportItem Id='term:7'><PageObject Language='-' Active='1'>
<PageContent><Paragraph Language='fr' Characteristic='Standard'>Ceci est un <Strong><Emph>chien</Emph></Strong></Paragraph></PageContent>
<PageContent><MediaObject><MediaAlias OriginId='il_0_mob_799'/><MediaAliasItem Purpose='Standard'><Layout HorizontalAlign='Left'/></MediaAliasItem></MediaObject></PageContent>
</PageObject></ExportItem>
</Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/export.xml",
            """<Export>
<ExportItem Id='798'><DataSet><Rec><Mob><Id>798</Id><Title>chat.jpg</Title><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer></Mob></Rec><Rec><MobMediaItem><Id>60</Id><MobId>798</MobId><Purpose>Standard</Purpose><Location>chat.jpg</Location><LocationType>LocalFile</LocationType><Format>image/jpeg</Format></MobMediaItem></Rec></DataSet></ExportItem>
<ExportItem Id='799'><DataSet><Rec><Mob><Id>799</Id><Title>chien.png</Title><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_2/dsDir_1</MediaContainer></Mob></Rec><Rec><MobMediaItem><Id>61</Id><MobId>799</MobId><Purpose>Standard</Purpose><Location>chien.png</Location><LocationType>LocalFile</LocationType><Format>image/png</Format></MobMediaItem></Rec></DataSet></ExportItem>
</Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/File/set_0/export.xml",
            "<Export></Export>",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1/chat.jpg",
            b"chat",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/expDir_2/dsDir_1/chien.png",
            b"chien",
        )

    glossaries = parse_glossaries(archive_path)
    assert len(glossaries) == 1

    glossary = glossaries[0]
    assert glossary["source"]["object_id"] == "797"
    assert glossary["title"] == "glossaire test migration"
    assert glossary["settings"]["presentation_mode"] == "table"
    assert glossary["taxonomy"]["enabled"] is False
    assert glossary["taxonomy"]["export_component_present"] is False
    assert glossary["files"] == {}
    assert len(glossary["terms"]) == 2

    chat = glossary["terms"][0]
    assert chat["source_id"] == "6"
    assert chat["term"] == "Chat"
    assert chat["definition"]["status"] == "ok"
    assert chat["definition"]["blocks"][0]["text"] == "ceci est un chat"
    assert chat["definition"]["blocks"][1]["type"] == "media"
    assert chat["definition"]["blocks"][1]["source_id"] == "798"

    chien = glossary["terms"][1]
    assert chien["source_id"] == "7"
    assert chien["term"] == "chien"
    inline = chien["definition"]["blocks"][0]["inline"]
    strong = next(part for part in inline if part["type"] == "strong")
    assert strong["children"][0]["type"] == "emphasis"
    assert chien["definition"]["blocks"][1]["source_id"] == "799"

    assert glossary["media"]["798"]["items"][0]["archive_path"].endswith("chat.jpg")
    assert glossary["media"]["799"]["items"][0]["archive_path"].endswith("chien.png")
    assert glossary["unsupported_components"] == []

    json.dumps(glossary, ensure_ascii=False)


def test_glossary_inactive_root_only_taxonomy_is_safe_empty(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "taxonomy-empty.zip"
    base = "set_31/1790441011__0__glo_797"

    taxonomy = """<Export>
<ExportItem Id="800">
<DataSet>
<Rec Entity="tax">
<Tax>
<Id>800</Id>
<Title>test taxonomie</Title>
<Description></Description>
<SortingMode>0</SortingMode>
</Tax>
</Rec>
<Rec Entity="tax_tree">
<TaxTree>
<TaxId>800</TaxId>
<Child>2</Child>
<Parent>0</Parent>
<Depth>1</Depth>
<Type></Type>
<Title>Root node for taxonomy 800</Title>
<OrderNr>0</OrderNr>
</TaxTree>
</Rec>
<Rec Entity="tax_usage">
<TaxUsage>
<TaxId>800</TaxId>
<ObjId>797</ObjId>
</TaxUsage>
</Rec>
</DataSet>
</ExportItem>
</Export>"""

    with zipfile.ZipFile(
        archive_path,
        "w",
    ) as archive:
        _write(
            archive,
            (
                f"{base}/components/ILIAS/"
                "Taxonomy/set_0/export.xml"
            ),
            taxonomy,
        )

    with zipfile.ZipFile(archive_path) as archive:
        parser = GlossaryParser(
            archive,
            base,
        )
        result = parser._parse_taxonomy(
            "797",
            "0",
        )

    assert result["enabled"] is False
    assert result[
        "export_component_present"
    ] is True
    assert (
        result["classification"]
        == "inactive_empty"
    )
    assert result["taxonomy_count"] == 1
    assert result["tree_node_count"] == 1
    assert result["root_node_count"] == 1
    assert result["business_node_count"] == 0
    assert result["usage_count"] == 1
    assert result["usage_object_ids"] == ["797"]
    assert result["unknown_entities"] == []


def test_glossary_taxonomy_with_business_node_requires_review(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "taxonomy-real.zip"
    base = "set_31/1790441011__0__glo_797"

    taxonomy = """<Export>
<ExportItem Id="800">
<DataSet>
<Rec Entity="tax">
<Tax>
<Id>800</Id>
<Title>test taxonomie</Title>
</Tax>
</Rec>
<Rec Entity="tax_tree">
<TaxTree>
<TaxId>800</TaxId>
<Child>2</Child>
<Parent>0</Parent>
<Depth>1</Depth>
<Title>Root node for taxonomy 800</Title>
</TaxTree>
</Rec>
<Rec Entity="tax_tree">
<TaxTree>
<TaxId>800</TaxId>
<Child>3</Child>
<Parent>2</Parent>
<Depth>2</Depth>
<Title>Catégorie réelle</Title>
</TaxTree>
</Rec>
<Rec Entity="tax_usage">
<TaxUsage>
<TaxId>800</TaxId>
<ObjId>797</ObjId>
</TaxUsage>
</Rec>
</DataSet>
</ExportItem>
</Export>"""

    with zipfile.ZipFile(
        archive_path,
        "w",
    ) as archive:
        _write(
            archive,
            (
                f"{base}/components/ILIAS/"
                "Taxonomy/set_0/export.xml"
            ),
            taxonomy,
        )

    with zipfile.ZipFile(archive_path) as archive:
        parser = GlossaryParser(
            archive,
            base,
        )
        result = parser._parse_taxonomy(
            "797",
            "0",
        )

    assert (
        result["classification"]
        == "requires_review"
    )
    assert result["business_node_count"] == 1
