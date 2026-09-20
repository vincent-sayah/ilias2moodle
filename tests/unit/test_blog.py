# ruff: noqa: E501
from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.blog import parse_blogs


def _write(archive: zipfile.ZipFile, name: str, content: str | bytes) -> None:
    archive.writestr(name, content)


def test_blog_parser_preserves_postings_copage_media_grid_author_and_keywords(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "course.zip"
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
<Rec Entity='blog'><Blog>
<Id>732</Id><Title>blog</Title><Description>test pour migration blog</Description>
<Keywords>1</Keywords><Authors>0</Authors><ReadingTime>0</ReadingTime>
</Blog></Rec>
<Rec Entity='blog_posting'><BlogPosting>
<Id>12</Id><BlogId>732</BlogId><Title>titre 1</Title>
<Created>2026-09-06 15:43:12</Created><Author>il_0_usr_6</Author>
<Approved>0</Approved><LastWithdrawn/><Keyword0>tag-a</Keyword0><Keyword1>tag-b</Keyword1>
</BlogPosting></Rec>
<Rec Entity='blog_posting'><BlogPosting>
<Id>13</Id><BlogId>732</BlogId><Title>titre 2</Title>
<Created>2026-09-06 15:44:05</Created><Author>il_0_usr_6</Author>
<Approved>0</Approved><LastWithdrawn/>
</BlogPosting></Rec>
</DataSet></ExportItem></Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/COPage/set_0/export.xml",
            """<Export>
<ExportItem Id='blp:12'><PageObject Language='-' Active='1'>
<PageContent><Paragraph Language='fr' Characteristic='Standard'>Premier billet</Paragraph></PageContent>
</PageObject></ExportItem>
<ExportItem Id='blp:13'><PageObject Language='-' Active='1'>
<PageContent><MediaObject><MediaAlias OriginId='il_0_mob_735'/><MediaAliasItem Purpose='Standard'><Layout HorizontalAlign='Left'/></MediaAliasItem></MediaObject></PageContent>
<PageContent><Grid>
<GridCell WIDTH_M='4'><PageContent><Paragraph>colonne 1</Paragraph></PageContent></GridCell>
<GridCell WIDTH_M='4'><PageContent><MediaObject><MediaAlias OriginId='il_0_mob_736'/></MediaObject></PageContent></GridCell>
<GridCell WIDTH_M='4'><PageContent><Paragraph>colonne 3</Paragraph></PageContent></GridCell>
</Grid></PageContent>
</PageObject></ExportItem>
</Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/MediaObjects/set_0/export.xml",
            """<Export>
<ExportItem Id='735'><DataSet>
<Rec><Mob><Id>735</Id><Title>tous.png</Title><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer></Mob></Rec>
<Rec><MobMediaItem><Id>38</Id><Purpose>Standard</Purpose><Location>tous.png</Location><LocationType>LocalFile</LocationType><Format>image/png</Format></MobMediaItem></Rec>
</DataSet></ExportItem>
<ExportItem Id='736'><DataSet>
<Rec><Mob><Id>736</Id><Title>trio.png</Title><MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_2/dsDir_1</MediaContainer></Mob></Rec>
<Rec><MobMediaItem><Id>39</Id><Purpose>Standard</Purpose><Location>trio.png</Location><LocationType>LocalFile</LocationType><Format>image/png</Format></MobMediaItem></Rec>
</DataSet></ExportItem>
</Export>""",
        )
        _write(
            archive,
            f"{base}/components/ILIAS/File/set_0/export.xml",
            "<Export></Export>",
        )

    blogs = parse_blogs(archive_path)
    assert len(blogs) == 1

    blog = blogs[0]
    assert blog["source"]["object_id"] == "732"
    assert blog["title"] == "blog"
    assert blog["posting_count"] == 2
    assert blog["media_count"] == 2
    assert blog["file_count"] == 0
    assert blog["settings"]["Keywords"] == "1"
    assert blog["settings"]["Authors"] == "0"

    first, second = blog["postings"]
    assert first["source_id"] == "12"
    assert first["position"] == 1
    assert first["author_source"] == "il_0_usr_6"
    assert first["keywords"] == ["tag-a", "tag-b"]
    assert first["content"]["blocks"][0]["type"] == "paragraph"

    assert second["source_id"] == "13"
    assert second["position"] == 2
    assert second["content"]["blocks"][0]["type"] == "media"
    assert second["content"]["blocks"][0]["source_id"] == "735"
    grid = second["content"]["blocks"][1]
    assert grid["type"] == "grid"
    assert len(grid["cells"]) == 3
    assert grid["cells"][1]["blocks"][0]["source_id"] == "736"

    assert blog["author_policy"]["moodle_user_assignment"] == "phase7"
    assert blog["target_strategy"]["moodle_activity"] == "mod_data"
    assert blog["unsupported_components"] == []

    json.dumps(blog, ensure_ascii=False)
