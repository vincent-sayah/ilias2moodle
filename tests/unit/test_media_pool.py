from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.media_pool import parse_media_pools
from ilias2moodle.media_pool_package import (
    enrich_document_media_pools,
    extract_media_pool_assets,
)
from ilias2moodle.model import (
    CourseExport,
    MigrationDocument,
    MigrationItem,
)


BASE = "set_35/1789892867__0__mep_818"


def _write_fixture(path: Path) -> None:
    manifest = """<?xml version="1.0" encoding="utf-8"?>
<Manifest MainEntity="crs">
  <ExportSet Type="crs" Path="set_1/course"/>
  <ExportSet Type="mep" Path="set_35/1789892867__0__mep_818"/>
</Manifest>
"""

    mep = """<?xml version="1.0" encoding="utf-8"?>
<Export>
  <ExportItem Id="818">
    <DataSet>
      <Rec Entity="mep"><Mep>
        <Id>818</Id>
        <Title>galerie de media</Title>
        <Description>test galerie</Description>
        <DefaultWidth>0</DefaultWidth>
        <DefaultHeight>0</DefaultHeight>
      </Mep></Rec>
      <Rec Entity="mep_tree"><MepTree>
        <MepId>818</MepId><Child>1</Child><Parent>0</Parent>
        <Depth>1</Depth><Type>dummy</Type><Title>Dummy</Title>
        <ForeignId>1</ForeignId><ImportId>il_0_dummy_1</ImportId>
      </MepTree></Rec>
      <Rec Entity="mep_tree"><MepTree>
        <MepId>818</MepId><Child>2</Child><Parent>1</Parent>
        <Depth>2</Depth><Type>mob</Type><Title>duo</Title>
        <ForeignId>819</ForeignId><ImportId>il_0_mob_2</ImportId>
      </MepTree></Rec>
      <Rec Entity="mep_tree"><MepTree>
        <MepId>818</MepId><Child>3</Child><Parent>1</Parent>
        <Depth>2</Depth><Type>mob</Type><Title>video5</Title>
        <ForeignId>820</ForeignId><ImportId>il_0_mob_3</ImportId>
      </MepTree></Rec>
      <Rec Entity="mep_tree"><MepTree>
        <MepId>818</MepId><Child>4</Child><Parent>1</Parent>
        <Depth>2</Depth><Type>pg</Type><Title>texte media</Title>
        <ForeignId>0</ForeignId><ImportId>il_0_pg_4</ImportId>
      </MepTree></Rec>
      <Rec Entity="mep_tree"><MepTree>
        <MepId>818</MepId><Child>5</Child><Parent>1</Parent>
        <Depth>2</Depth><Type>fold</Type><Title>dossier1</Title>
        <ForeignId>0</ForeignId><ImportId>il_0_fold_5</ImportId>
      </MepTree></Rec>
      <Rec Entity="mep_tree"><MepTree>
        <MepId>818</MepId><Child>6</Child><Parent>5</Parent>
        <Depth>3</Depth><Type>mob</Type>
        <Title>femme_noire_robot.png</Title>
        <ForeignId>822</ForeignId><ImportId>il_0_mob_6</ImportId>
      </MepTree></Rec>
    </DataSet>
  </ExportItem>
</Export>
"""

    media0 = """<?xml version="1.0" encoding="utf-8"?>
<Export>
  <ExportItem Id="819"><DataSet>
    <Rec Entity="mob"><Mob>
      <Id>819</Id><Title>duo</Title><Description>image/png</Description>
      <MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer>
    </Mob></Rec>
    <Rec Entity="mob_media_item"><MobMediaItem>
      <Id>73</Id><MobId>819</MobId><Purpose>Standard</Purpose>
      <Location>du2.png</Location><LocationType>LocalFile</LocationType>
      <Format>image/png</Format><Caption>legende</Caption>
      <TextRepresentation>alt duo</TextRepresentation>
    </MobMediaItem></Rec>
  </DataSet></ExportItem>
  <ExportItem Id="820"><DataSet>
    <Rec Entity="mob"><Mob>
      <Id>820</Id><Title>video5</Title><Description>video/mp4</Description>
      <MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_2/dsDir_1</MediaContainer>
    </Mob></Rec>
    <Rec Entity="mob_media_item"><MobMediaItem>
      <Id>75</Id><MobId>820</MobId><Purpose>Standard</Purpose>
      <Location>vid5.mp4</Location><LocationType>LocalFile</LocationType>
      <Format>video/mp4</Format>
    </MobMediaItem></Rec>
  </DataSet></ExportItem>
  <ExportItem Id="822"><DataSet>
    <Rec Entity="mob"><Mob>
      <Id>822</Id><Title>femme_noire_robot.png</Title>
      <MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_3/dsDir_1</MediaContainer>
    </Mob></Rec>
    <Rec Entity="mob_media_item"><MobMediaItem>
      <Id>77</Id><MobId>822</MobId><Purpose>Standard</Purpose>
      <Location>femme_noire_robot.png</Location>
      <LocationType>LocalFile</LocationType><Format>image/png</Format>
    </MobMediaItem></Rec>
  </DataSet></ExportItem>
</Export>
"""

    media1 = """<?xml version="1.0" encoding="utf-8"?>
<Export>
  <ExportItem Id="821"><DataSet>
    <Rec Entity="mob"><Mob>
      <Id>821</Id><Title>trio.png</Title>
      <MediaContainer>components/ILIAS/MediaObjects/set_1/expDir_1/dsDir_1</MediaContainer>
    </Mob></Rec>
    <Rec Entity="mob_media_item"><MobMediaItem>
      <Id>76</Id><MobId>821</MobId><Purpose>Standard</Purpose>
      <Location>trio.png</Location><LocationType>LocalFile</LocationType>
      <Format>image/png</Format>
    </MobMediaItem></Rec>
  </DataSet></ExportItem>
</Export>
"""

    copage = """<?xml version="1.0" encoding="utf-8"?>
<Export>
  <ExportItem Id="mep:4">
    <PageObject Language="-" Active="1">
      <PageContent><Paragraph Characteristic="Standard">
        texte de contenu
      </Paragraph></PageContent>
      <PageContent><MediaObject>
        <MediaAlias OriginId="il_0_mob_821"/>
        <MediaAliasItem Purpose="Standard"/>
      </MediaObject></PageContent>
    </PageObject>
  </ExportItem>
</Export>
"""

    members = {
        "manifest.xml": manifest.encode(),
        f"{BASE}/components/ILIAS/MediaPool/set_0/export.xml": mep.encode(),
        f"{BASE}/components/ILIAS/MediaObjects/set_0/export.xml": media0.encode(),
        f"{BASE}/components/ILIAS/MediaObjects/set_1/export.xml": media1.encode(),
        f"{BASE}/components/ILIAS/COPage/set_0/export.xml": copage.encode(),
        f"{BASE}/components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1/du2.png": b"DUO",
        f"{BASE}/components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1/mob_vpreview.png": b"PREVIEW",
        f"{BASE}/components/ILIAS/MediaObjects/set_0/expDir_2/dsDir_1/vid5.mp4": b"VIDEO5",
        f"{BASE}/components/ILIAS/MediaObjects/set_0/expDir_2/dsDir_1/mob_vpreview.png": b"",
        f"{BASE}/components/ILIAS/MediaObjects/set_0/expDir_3/dsDir_1/femme_noire_robot.png": b"ROBOT",
        f"{BASE}/components/ILIAS/MediaObjects/set_1/expDir_1/dsDir_1/trio.png": b"TRIO",
    }

    with zipfile.ZipFile(path, "w") as archive:
        for name, content in members.items():
            archive.writestr(name, content)


def test_media_pool_parser_handles_tree_multiple_media_sets_and_copage(
    tmp_path: Path,
) -> None:
    archive = tmp_path / "mep.zip"
    _write_fixture(archive)

    pools = parse_media_pools(archive)

    assert len(pools) == 1
    pool = pools[0]

    assert pool["source"]["object_id"] == "818"
    assert pool["title"] == "galerie de media"
    assert pool["target_strategy"]["moodle_activity"] == "mod_data"
    assert pool["counts"] == {
        "tree_nodes": 6,
        "content_records": 4,
        "folders": 1,
        "direct_media": 3,
        "all_media": 4,
        "page_items": 1,
    }
    assert set(pool["media"]) == {"819", "820", "821", "822"}
    assert pool["unsupported_components"] == []

    by_tree = {
        record["source_tree_id"]: record
        for record in pool["records"]
    }

    assert by_tree["6"]["folder_path"] == ["dossier1"]
    assert by_tree["6"]["media_source_id"] == "822"

    page = by_tree["4"]
    assert page["item_type"] == "page"
    assert page["content"]["status"] == "ok"
    assert page["content"]["blocks"][0]["type"] == "paragraph"
    assert page["content"]["blocks"][1]["type"] == "media"
    assert page["content"]["blocks"][1]["source_id"] == "821"
    assert page["content"]["blocks"][1]["media"]["title"] == "trio.png"


def test_media_pool_package_extracts_originals_only(
    tmp_path: Path,
) -> None:
    archive = tmp_path / "mep.zip"
    output = tmp_path / "prepared"
    _write_fixture(archive)

    document = MigrationDocument(
        course=CourseExport(
            source_id="128",
            title="cours test migration",
            items=[
                MigrationItem(
                    source_id="278",
                    type="media_pool",
                    title="galerie de media",
                    metadata={
                        "obj_id": "818",
                        "ref_id": "278",
                        "ilias_type": "mep",
                    },
                )
            ],
        ),
        source={"lms": "ILIAS", "version": "10.8"},
    )

    enrich = enrich_document_media_pools(
        document,
        archive,
    )
    assert enrich == {
        "detected": 1,
        "matched": 1,
        "missing": 0,
    }

    result = extract_media_pool_assets(
        document,
        archive,
        output,
    )

    assert result["missing"] == []
    assert result["extracted"]["media_pool_structures"] == 1
    assert result["extracted"]["media_pool_records"] == 4
    assert result["extracted"]["media_pool_media_files"] == 4

    expected = {
        "media_pools/278/media/819/du2.png",
        "media_pools/278/media/820/vid5.mp4",
        "media_pools/278/media/821/trio.png",
        "media_pools/278/media/822/femme_noire_robot.png",
    }

    actual = {
        path.relative_to(output).as_posix()
        for path in (output / "media_pools" / "278" / "media").rglob("*")
        if path.is_file()
    }

    assert actual == expected
    assert not list(output.rglob("mob_vpreview.png"))

    structure = json.loads(
        (
            output
            / "media_pools"
            / "278"
            / "structure.json"
        ).read_text(encoding="utf-8")
    )

    for media in structure["media"].values():
        for item in media["items"]:
            assert item["migration_size"] > 0
            assert len(item["migration_sha256"]) == 64

    page = next(
        record
        for record in structure["records"]
        if record["source_tree_id"] == "4"
    )
    nested = page["content"]["blocks"][1]["media"]["items"][0]
    assert nested["migration_path"] == (
        "media_pools/278/media/821/trio.png"
    )
