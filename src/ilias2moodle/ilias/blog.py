from __future__ import annotations

import re
import zipfile
from pathlib import PurePosixPath
from typing import Any
from xml.etree import ElementTree as ET


from ilias2moodle.ilias.content_page import (
    ContentPageParser,
    _first_descendant,
    _local_name,
    _text_descendant,
)

from ilias2moodle.ilias.export_sets import find_export_sets

class BlogParser:
    """Parse one native ILIAS Blog export set."""

    def __init__(self, archive: zipfile.ZipFile, base: str) -> None:
        self.archive = archive
        self.base = base.strip("/")
        self.names = {name.lstrip("/"): name for name in archive.namelist()}
        self.page_parser = ContentPageParser(archive, self.base)

    def _archive_member(self, suffix: str) -> str | None:
        return self.names.get(f"{self.base}/{suffix.lstrip('/')}")

    def _component_export(self, component: str, set_number: int = 0) -> str | None:
        return self._archive_member(
            f"components/ILIAS/{component}/set_{set_number}/export.xml"
        )

    def _parse_xml(self, member: str) -> ET.Element:
        return ET.fromstring(self.archive.read(member))

    def parse(self) -> dict[str, Any]:
        blog_component = self._component_export("Blog")
        if blog_component is None:
            raise ValueError("Composant ILIAS/Blog introuvable")

        root = self._parse_xml(blog_component)
        blog = _first_descendant(root, "Blog")
        if blog is None:
            raise ValueError("Enregistrement Blog introuvable")

        object_id = _text_descendant(blog, "Id")
        media = self.page_parser._parse_media()
        files = self.page_parser._parse_files()
        copages = self._parse_copages(media, files)

        postings: list[dict[str, Any]] = []
        unsupported: list[dict[str, str]] = []

        position = 0
        for candidate in root.iter():
            if _local_name(candidate.tag) != "BlogPosting":
                continue

            position += 1
            posting_id = _text_descendant(candidate, "Id")
            content = copages.get(
                posting_id,
                {
                    "status": "missing_copage",
                    "export_id": f"blp:{posting_id}",
                    "active": "",
                    "language": "",
                    "blocks": [],
                    "unsupported_components": [],
                },
            )
            page_unsupported = content.get("unsupported_components", [])
            if isinstance(page_unsupported, list):
                for component in page_unsupported:
                    unsupported.append(
                        {
                            "posting_id": posting_id,
                            "element": str(component.get("element", "")),
                        }
                    )

            keywords: list[str] = []
            for child in candidate.iter():
                name = _local_name(child.tag)
                if not re.fullmatch(r"Keyword\d+", name):
                    continue
                value = (child.text or "").strip()
                if value:
                    keywords.append(value)

            postings.append(
                {
                    "source_id": posting_id,
                    "position": position,
                    "blog_id": _text_descendant(candidate, "BlogId"),
                    "title": _text_descendant(candidate, "Title"),
                    "created": _text_descendant(candidate, "Created"),
                    "author_source": _text_descendant(candidate, "Author"),
                    "approved": _text_descendant(candidate, "Approved"),
                    "last_withdrawn": _text_descendant(candidate, "LastWithdrawn"),
                    "keywords": keywords,
                    "content": content,
                }
            )

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "export_base": self.base,
            },
            "title": _text_descendant(blog, "Title"),
            "description": _text_descendant(blog, "Description"),
            "settings": self._settings(blog),
            "postings": postings,
            "posting_count": len(postings),
            "media": media,
            "files": files,
            "media_count": len(media),
            "file_count": len(files),
            "unsupported_components": unsupported,
            "author_policy": {
                "source_identifier_preserved": True,
                "moodle_user_assignment": "phase7",
                "invent_author": False,
            },
            "target_strategy": {
                "moodle_activity": "mod_data",
                "collection_policy": "one_database_per_ilias_blog",
                "record_policy": "one_record_per_blog_posting",
                "status": "proposed_pending_moodle_validation",
            },
        }

    def _settings(self, blog: ET.Element) -> dict[str, str]:
        names = (
            "Notes",
            "BgColor",
            "FontColor",
            "Img",
            "Ppic",
            "RssActive",
            "Approval",
            "AbsShorten",
            "AbsShortenLen",
            "AbsImage",
            "AbsImgWidth",
            "AbsImgHeight",
            "NavMode",
            "NavListMonWithPost",
            "NavListMon",
            "Keywords",
            "Authors",
            "NavOrder",
            "OvPost",
            "ReadingTime",
            "Style",
        )
        return {name: _text_descendant(blog, name) for name in names}

    def _parse_copages(
        self,
        media: dict[str, dict[str, Any]],
        files: dict[str, dict[str, Any]],
    ) -> dict[str, dict[str, Any]]:
        component = self._component_export("COPage")
        if component is None:
            return {}

        root = self._parse_xml(component)
        result: dict[str, dict[str, Any]] = {}

        for export_item in root.iter():
            if _local_name(export_item.tag) != "ExportItem":
                continue

            export_id = export_item.attrib.get("Id", "")
            match = re.fullmatch(r"blp:(\d+)", export_id)
            if match is None:
                continue

            posting_id = match.group(1)
            page_object = _first_descendant(export_item, "PageObject")
            if page_object is None:
                result[posting_id] = {
                    "status": "missing_page_object",
                    "export_id": export_id,
                    "active": "",
                    "language": "",
                    "blocks": [],
                    "unsupported_components": [],
                }
                continue

            blocks = self.page_parser._parse_page_children(
                page_object,
                media,
                files,
            )
            result[posting_id] = {
                "status": "ok",
                "export_id": export_id,
                "active": page_object.attrib.get("Active", ""),
                "language": page_object.attrib.get("Language", ""),
                "blocks": blocks,
                "unsupported_components": self.page_parser._collect_unsupported(
                    blocks
                ),
            }

        return result


def find_blog_export_sets(archive: zipfile.ZipFile) -> list[dict[str, str]]:
    """Return blog export sets from a native ILIAS course ZIP."""

    return find_export_sets(archive, "blog")

def parse_blogs(archive_path: str | PurePosixPath) -> list[dict[str, Any]]:
    """Parse every Blog contained in a native ILIAS course ZIP."""

    with zipfile.ZipFile(str(archive_path)) as archive:
        blogs: list[dict[str, Any]] = []
        for export_set in find_blog_export_sets(archive):
            blog = BlogParser(archive, export_set["path"]).parse()
            blog["source"]["object_id"] = (
                blog["source"].get("object_id") or export_set["object_id"]
            )
            blogs.append(blog)
        return blogs
