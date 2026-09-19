# ruff: noqa: E501
from __future__ import annotations

import json
import zipfile
from pathlib import Path

from ilias2moodle.ilias.forum import parse_forums


def test_forum_parser_preserves_threads_posts_authors_and_assets(
    tmp_path: Path,
) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_32/1800000000__0__frm_807"

    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(
            "manifest.xml",
            """<Manifest>
<ExportSet Path='set_1/1800000000__0__crs_504' Type='crs'/>
<ExportSet Path='set_32/1800000000__0__frm_807' Type='frm'/>
</Manifest>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/Forum/set_0/export.xml",
            """<Export>
<Forum>
<Id>3</Id>
<ObjId>807</ObjId>
<Title>test migration forum</Title>
<Description>POC Forum</Description>
<DefaultView>2</DefaultView>
<Pseudonyms>0</Pseudonyms>
<Statistics>0</Statistics>
<ThreadRatings>0</ThreadRatings>
<MarkModeratorPosts>0</MarkModeratorPosts>
<PostingActivation>0</PostingActivation>
<PresetSubject>1</PresetSubject>
<PresetRe>0</PresetRe>
<NotificationType>default</NotificationType>
<NotificationEvents>0</NotificationEvents>
<ForceNotification>0</ForceNotification>
<ToggleNotification>0</ToggleNotification>
<FileUpload>0</FileUpload>
<Moderator>0</Moderator>
<CreateDate>2026-09-19 15:39:05</CreateDate>
<UpdateDate>2026-09-19 15:39:31</UpdateDate>
<UpdateUserId>0</UpdateUserId>
<UserId>6</UserId>
<Thread>
<Id>5</Id>
<Subject>Sujet 1</Subject>
<UserId>6</UserId>
<AuthorId>6</AuthorId>
<Alias>root</Alias>
<CreateDate>2026-09-19 15:40:15</CreateDate>
<UpdateDate>2026-09-19 15:40:15</UpdateDate>
<Sticky>1</Sticky>
<Closed>0</Closed>
<Post>
<Id>13</Id>
<UserId>6</UserId>
<AuthorId>6</AuthorId>
<Alias>root</Alias>
<Subject>Sujet 1</Subject>
<CreateDate>2026-09-19 15:40:15</CreateDate>
<UpdateDate>2026-09-19 15:40:15</UpdateDate>
<UpdateUserId>6</UpdateUserId>
<Status>1</Status>
<Message></Message>
<Lft>1</Lft><Rgt>6</Rgt><Depth>1</Depth><ParentId>0</ParentId>
</Post>
<Post>
<Id>14</Id>
<UserId>401</UserId>
<AuthorId>401</AuthorId>
<Alias>stagiaire.1</Alias>
<Subject>Re: Sujet 1</Subject>
<CreateDate>2026-09-19 15:41:15</CreateDate>
<UpdateDate>2026-09-19 15:41:15</UpdateDate>
<UpdateUserId>401</UpdateUserId>
<Status>1</Status>
<Message>&lt;p&gt;Réponse avec fichier&lt;/p&gt;</Message>
<MessageMediaObjects>
<MediaObject label='il_0_mob_901' uri='components/ILIAS/Forum/set_0/expDir_1/objects/il_0_mob_901/image.png'/>
</MessageMediaObjects>
<Lft>2</Lft><Rgt>5</Rgt><Depth>2</Depth><ParentId>13</ParentId>
<Attachment>
<Content>components/ILIAS/Forum/set_0/expDir_1/handout.pdf</Content>
</Attachment>
</Post>
</Thread>
</Forum>
</Export>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/Forum/set_0/expDir_1/handout.pdf",
            b"forum-attachment",
        )
        archive.writestr(
            f"{base}/components/ILIAS/Forum/set_0/expDir_1/objects/il_0_mob_901/image.png",
            b"forum-image",
        )

    forums = parse_forums(archive_path)

    assert len(forums) == 1
    forum = forums[0]

    assert forum["source"]["object_id"] == "807"
    assert forum["title"] == "test migration forum"
    assert forum["description"] == "POC Forum"
    assert forum["thread_count"] == 1
    assert forum["post_count"] == 2
    assert forum["attachment_count"] == 1
    assert forum["media_object_count"] == 1
    assert forum["source_author_ids"] == ["401", "6"]

    thread = forum["threads"][0]
    assert thread["source_id"] == "5"
    assert thread["sticky"] is True
    assert thread["closed"] is False

    reply = thread["posts"][1]
    assert reply["source_id"] == "14"
    assert reply["parent_source_id"] == "13"
    assert reply["tree"]["depth"] == 2
    assert reply["source_author_id"] == "401"
    assert reply["alias"] == "stagiaire.1"
    assert reply["attachments"][0]["filename"] == "handout.pdf"
    assert reply["attachments"][0]["embedded"] is True
    assert reply["media_objects"][0]["filename"] == "image.png"
    assert reply["media_objects"][0]["embedded"] is True

    policy = forum["user_data_policy"]
    assert policy["authors_resolved_to_moodle"] is False
    assert policy["discussions_migrated"] is False
    assert policy["posts_migrated"] is False
    assert policy["target_phase"] == "7"
    assert forum["missing_assets"] == []

    json.dumps(forum, ensure_ascii=False)
