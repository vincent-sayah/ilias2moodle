from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.mediacast import parse_mediacasts


def test_mediacast_parser_preserves_local_mp4_and_external_url(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_33/1789835852__0__mcst_809"

    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(
            "manifest.xml",
            """<Manifest>
<ExportSet Path='set_1/1789835852__0__crs_504' Type='crs'/>
<ExportSet Path='set_33/1789835852__0__mcst_809' Type='mcst'/>
</Manifest>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/MediaCast/set_0/export.xml",
            """<Export><Mcst>
<Id>809</Id>
<Title>mediacast test migration</Title>
<Description></Description>
<PublicFiles>0</PublicFiles>
<Downloadable>1</Downloadable>
<DefaultAccess>0</DefaultAccess>
<Sortmode>3</Sortmode>
<Viewmode>video</Viewmode>
<Autoplaymode>0</Autoplaymode>
<NrInitialVideos>5</NrInitialVideos>
<NewItemsInLp>1</NewItemsInLp>
<PublicFeed></PublicFeed>
<KeepRssMin>0</KeepRssMin>
</Mcst></Export>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/News/set_0/export.xml",
            """<Export>
<News>
<Id>96</Id>
<Title>clip Vincent</Title>
<Content>description de clip1</Content>
<Priority>1</Priority>
<ContextObjId>809</ContextObjId>
<ContextObjType>mcst</ContextObjType>
<ContentType>audio</ContentType>
<Visibility>users</Visibility>
<MobId>810</MobId>
<Playtime>00:02:06</Playtime>
</News>
<News>
<Id>97</Id>
<Title>Kimo Sounds Rise Slow</Title>
<Content></Content>
<Priority>1</Priority>
<ContextObjId>809</ContextObjId>
<ContextObjType>mcst</ContextObjType>
<ContentType>audio</ContentType>
<Visibility>users</Visibility>
<MobId>811</MobId>
<Playtime>00:00:00</Playtime>
</News>
</Export>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/MediaObjects/set_0/export.xml",
            """<Export>
<ExportItem Id='810'>
<Mob>
<Id>810</Id>
<Title>clip Vincent</Title>
<Description>description de clip1</Description>
<MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_1/dsDir_1</MediaContainer>
<MobMediaItem>
<Id>68</Id>
<MobId>810</MobId>
<Purpose>Standard</Purpose>
<Location>clip1.mp4</Location>
<LocationType>LocalFile</LocationType>
<Format>video/mp4</Format>
</MobMediaItem>
</Mob>
</ExportItem>
<ExportItem Id='811'>
<Mob>
<Id>811</Id>
<Title>Kimo Sounds Rise Slow</Title>
<Description></Description>
<MediaContainer>components/ILIAS/MediaObjects/set_0/expDir_2/dsDir_1</MediaContainer>
<MobMediaItem>
<Id>67</Id>
<MobId>811</MobId>
<Purpose>Standard</Purpose>
<Location>https://youtu.be/example</Location>
<LocationType>Reference</LocationType>
<Format>video/youtube</Format>
</MobMediaItem>
</Mob>
</ExportItem>
</Export>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/MediaObjects/set_0/"
            "expDir_1/dsDir_1/mob_vpreview.png",
            b"preview-local",
        )
        archive.writestr(
            f"{base}/components/ILIAS/MediaObjects/set_0/"
            "expDir_2/dsDir_1/mob_vpreview.jpg",
            b"preview-youtube",
        )

    values = parse_mediacasts(archive_path)

    assert len(values) == 1
    mediacast = values[0]
    assert mediacast["source"]["object_id"] == "809"
    assert mediacast["title"] == "mediacast test migration"
    assert mediacast["settings"]["viewmode"] == "video"
    assert mediacast["settings"]["downloadable"] is True
    assert mediacast["entry_count"] == 2
    assert mediacast["local_file_count"] == 1
    assert mediacast["external_url_count"] == 1
    assert mediacast["unsupported_entry_count"] == 0

    local = mediacast["entries"][0]
    assert local["source_id"] == "96"
    assert local["mob_id"] == "810"
    assert local["source_kind"] == "local_file"
    assert local["location"] == "clip1.mp4"
    assert local["format"] == "video/mp4"
    assert local["embedded"] is False
    assert local["needs_recovery"] is True
    assert local["playtime"] == "00:02:06"
    assert local["preview_assets"][0]["filename"] == "mob_vpreview.png"

    external = mediacast["entries"][1]
    assert external["source_id"] == "97"
    assert external["mob_id"] == "811"
    assert external["source_kind"] == "external_url"
    assert external["location"] == "https://youtu.be/example"
    assert external["format"] == "video/youtube"
    assert external["needs_recovery"] is False
    assert external["preview_assets"][0]["filename"] == "mob_vpreview.jpg"

    assert mediacast["target_strategy"]["moodle_activity"] == "mod_data"
    assert len(mediacast["missing_assets"]) == 1
    assert mediacast["missing_assets"][0]["mob_id"] == "810"

    json.dumps(mediacast, ensure_ascii=False)
