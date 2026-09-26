from __future__ import annotations

import zipfile
from pathlib import PurePosixPath\n\nfrom ilias2moodle.ilias.export_sets import find_export_sets
from typing import Any
from xml.etree import ElementTree as ET

from ilias2moodle.ilias.content_page import _local_name, _text_descendant


def _int(element: ET.Element, field: str, default: int = 0) -> int:
    raw = _text_descendant(element, field, "")
    try:
        return int(raw) if raw != "" else default
    except ValueError:
        return default


def _bool(element: ET.Element, field: str) -> bool:
    return _int(element, field, 0) != 0


class ForumParser:
    """Parse one native ILIAS Forum export set.

    Phase 6.5.5 preserves the complete thread/post structure and assets in the
    neutral package. Author identities are source metadata only until Phase 7
    resolves them to Moodle users.
    """

    def __init__(self, archive: zipfile.ZipFile, base: str) -> None:
        self.archive = archive
        self.base = base.strip("/")
        self.names = {name.lstrip("/"): name for name in archive.namelist()}
        self.infos = {
            info.filename.lstrip("/"): info
            for info in archive.infolist()
            if not info.is_dir()
        }

    def _archive_member(self, suffix: str) -> str | None:
        return self.names.get(f"{self.base}/{suffix.lstrip('/')}")

    def _component_export(self, component: str, set_number: int = 0) -> str | None:
        return self._archive_member(
            f"components/ILIAS/{component}/set_{set_number}/export.xml"
        )

    def _parse_xml(self, member: str) -> ET.Element:
        return ET.fromstring(self.archive.read(member))

    def _components(self) -> list[str]:
        prefix = f"{self.base}/components/ILIAS/"
        result: set[str] = set()
        for name in self.names:
            if not name.startswith(prefix):
                continue
            relative = name[len(prefix) :]
            if "/" in relative:
                result.add(relative.split("/", 1)[0])
        return sorted(result)

    def _asset(self, relative_path: str) -> dict[str, Any]:
        relative = relative_path.strip("/")
        archive_path = (
            f"{self.base}/{relative}"
            if relative
            else ""
        )
        info = self.infos.get(archive_path)
        return {
            "filename": PurePosixPath(relative).name if relative else "",
            "relative_path": relative,
            "archive_path": archive_path,
            "size": int(info.file_size) if info is not None else 0,
            "embedded": info is not None,
        }

    def _attachment(self, element: ET.Element) -> dict[str, Any]:
        content = _text_descendant(element, "Content")
        asset = self._asset(content)
        asset["kind"] = "attachment"
        return asset

    def _media(self, element: ET.Element) -> dict[str, Any]:
        uri = element.attrib.get("uri", "").strip()
        asset = self._asset(uri)
        asset.update(
            {
                "kind": "media_object",
                "label": element.attrib.get("label", ""),
                "uri": uri,
            }
        )
        return asset

    def _post(self, element: ET.Element, thread_id: str) -> dict[str, Any]:
        attachments = [
            self._attachment(child)
            for child in element
            if _local_name(child.tag) == "Attachment"
        ]

        media_objects: list[dict[str, Any]] = []
        for candidate in element.iter():
            if _local_name(candidate.tag) == "MediaObject":
                media_objects.append(self._media(candidate))

        return {
            "source_id": _text_descendant(element, "Id"),
            "thread_source_id": thread_id,
            "parent_source_id": _text_descendant(element, "ParentId"),
            "subject": _text_descendant(element, "Subject"),
            "message_html": _text_descendant(element, "Message"),
            "create_date": _text_descendant(element, "CreateDate"),
            "update_date": _text_descendant(element, "UpdateDate"),
            "source_user_id": _text_descendant(element, "UserId"),
            "source_author_id": _text_descendant(element, "AuthorId"),
            "source_update_user_id": _text_descendant(element, "UpdateUserId"),
            "alias": _text_descendant(element, "Alias"),
            "status": _int(element, "Status", 0),
            "censored": _bool(element, "Censorship"),
            "censorship_message": _text_descendant(
                element, "CensorshipMessage"
            ),
            "notification": _text_descendant(element, "Notification"),
            "import_name": _text_descendant(element, "ImportName"),
            "author_was_moderator": _text_descendant(
                element, "isAuthorModerator"
            ),
            "tree": {
                "left": _int(element, "Lft", 0),
                "right": _int(element, "Rgt", 0),
                "depth": _int(element, "Depth", 0),
            },
            "attachments": attachments,
            "media_objects": media_objects,
        }

    def _thread(self, element: ET.Element) -> dict[str, Any]:
        thread_id = _text_descendant(element, "Id")
        posts = [
            self._post(child, thread_id)
            for child in element
            if _local_name(child.tag) == "Post"
        ]
        return {
            "source_id": thread_id,
            "subject": _text_descendant(element, "Subject"),
            "source_user_id": _text_descendant(element, "UserId"),
            "source_author_id": _text_descendant(element, "AuthorId"),
            "alias": _text_descendant(element, "Alias"),
            "last_post": _text_descendant(element, "LastPost"),
            "create_date": _text_descendant(element, "CreateDate"),
            "update_date": _text_descendant(element, "UpdateDate"),
            "import_name": _text_descendant(element, "ImportName"),
            "sticky": _bool(element, "Sticky"),
            "closed": _bool(element, "Closed"),
            "posts": posts,
        }

    def parse(self) -> dict[str, Any]:
        component = self._component_export("Forum")
        if component is None:
            raise ValueError("Composant ILIAS/Forum introuvable")

        root = self._parse_xml(component)
        forum = next(
            (
                candidate
                for candidate in root.iter()
                if _local_name(candidate.tag) == "Forum"
            ),
            None,
        )
        if forum is None:
            raise ValueError("Élément Forum introuvable")

        object_id = _text_descendant(forum, "ObjId")
        threads = [
            self._thread(child)
            for child in forum
            if _local_name(child.tag) == "Thread"
        ]
        posts = [
            post
            for thread in threads
            for post in thread["posts"]
        ]
        attachments = [
            attachment
            for post in posts
            for attachment in post["attachments"]
        ]
        media_objects = [
            media
            for post in posts
            for media in post["media_objects"]
        ]

        author_ids = sorted(
            {
                value
                for value in [
                    _text_descendant(forum, "UserId"),
                    _text_descendant(forum, "UpdateUserId"),
                    *[
                        str(thread.get("source_user_id", ""))
                        for thread in threads
                    ],
                    *[
                        str(thread.get("source_author_id", ""))
                        for thread in threads
                    ],
                    *[
                        str(post.get("source_user_id", ""))
                        for post in posts
                    ],
                    *[
                        str(post.get("source_author_id", ""))
                        for post in posts
                    ],
                    *[
                        str(post.get("source_update_user_id", ""))
                        for post in posts
                    ],
                ]
                if value and value != "0"
            }
        )

        missing_assets: list[dict[str, str]] = []
        for post in posts:
            post_id = str(post.get("source_id", ""))
            for asset in [
                *post.get("attachments", []),
                *post.get("media_objects", []),
            ]:
                if not asset.get("embedded"):
                    missing_assets.append(
                        {
                            "post_id": post_id,
                            "kind": str(asset.get("kind", "")),
                            "source_path": str(
                                asset.get("relative_path", "")
                            ),
                        }
                    )

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "forum_id": _text_descendant(forum, "Id"),
                "export_base": self.base,
            },
            "title": _text_descendant(forum, "Title"),
            "description": _text_descendant(forum, "Description"),
            "settings": {
                "default_view": _int(forum, "DefaultView", 0),
                "pseudonyms": _bool(forum, "Pseudonyms"),
                "statistics": _bool(forum, "Statistics"),
                "thread_ratings": _bool(forum, "ThreadRatings"),
                "mark_moderator_posts": _bool(
                    forum, "MarkModeratorPosts"
                ),
                "posting_activation": _bool(
                    forum, "PostingActivation"
                ),
                "preset_subject": _bool(forum, "PresetSubject"),
                "preset_re": _bool(forum, "PresetRe"),
                "notification_type": _text_descendant(
                    forum, "NotificationType"
                ),
                "notification_events": _int(
                    forum, "NotificationEvents", 0
                ),
                "force_notification": _bool(
                    forum, "ForceNotification"
                ),
                "toggle_notification": _bool(
                    forum, "ToggleNotification"
                ),
                "file_upload": _bool(forum, "FileUpload"),
                "moderator_count": _int(forum, "Moderator", 0),
            },
            "create_date": _text_descendant(forum, "CreateDate"),
            "update_date": _text_descendant(forum, "UpdateDate"),
            "source_user_id": _text_descendant(forum, "UserId"),
            "source_update_user_id": _text_descendant(
                forum, "UpdateUserId"
            ),
            "threads": threads,
            "thread_count": len(threads),
            "post_count": len(posts),
            "attachment_count": len(attachments),
            "media_object_count": len(media_objects),
            "source_author_ids": author_ids,
            "components": self._components(),
            "missing_assets": missing_assets,
            "target_strategy": {
                "moodle_activity": "mod_forum",
                "one_moodle_forum_per_ilias_forum": True,
                "forum_container_migrated_in_phase65": True,
                "discussions_migrated_in_phase65": False,
                "posts_migrated_in_phase65": False,
                "contribution_assets_migrated_in_phase65": False,
            },
            "user_data_policy": {
                "source_export_contains_authored_contributions": bool(posts),
                "source_author_ids_preserved": True,
                "authors_resolved_to_moodle": False,
                "discussions_migrated": False,
                "posts_migrated": False,
                "attachments_migrated_to_posts": False,
                "target_phase": "7",
                "reason": "author_identity_resolution_required",
            },
        }


def find_forum_export_sets(archive: zipfile.ZipFile) -> list[dict[str, str]]:
    """Return frm export sets from a native ILIAS course ZIP."""

    return find_export_sets(archive, "frm")

def parse_forums(
    archive_path: str | PurePosixPath,
) -> list[dict[str, Any]]:
    with zipfile.ZipFile(str(archive_path)) as archive:
        forums: list[dict[str, Any]] = []
        for export_set in find_forum_export_sets(archive):
            parsed = ForumParser(
                archive,
                export_set["path"],
            ).parse()
            if not parsed["source"]["object_id"]:
                parsed["source"]["object_id"] = export_set[
                    "object_id"
                ]
            forums.append(parsed)
        return forums
