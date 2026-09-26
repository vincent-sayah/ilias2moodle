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


def _records(root: ET.Element, entity: str) -> list[ET.Element]:
    wanted = entity.lower()
    result: list[ET.Element] = []
    for candidate in root.iter():
        if _local_name(candidate.tag) != "Rec":
            continue
        if candidate.attrib.get("Entity", "").lower() == wanted:
            result.append(candidate)
    return result


def _rewrite_wiki_markup_links(
    value: Any,
    title_to_id: dict[str, str],
) -> Any:
    """Convert native Wiki [[Page title]] markup embedded in COPage text nodes.

    ILIAS Wiki exports keep cross-page links as literal wiki markup inside
    Paragraph text rather than serializing them as COPage IntLink elements.
    """
    if isinstance(value, list):
        rewritten: list[Any] = []
        for item in value:
            if isinstance(item, dict) and item.get("type") == "text":
                text = str(item.get("text", ""))
                cursor = 0
                matches = list(re.finditer(r"\[\[([^\[\]\n]+)\]\]", text))
                if not matches:
                    rewritten.append(item)
                    continue

                for match in matches:
                    if match.start() > cursor:
                        rewritten.append(
                            {"type": "text", "text": text[cursor : match.start()]}
                        )

                    title = match.group(1).strip()
                    source_id = title_to_id.get(title, "")
                    rewritten.append(
                        {
                            "type": "internal_link",
                            "text": title,
                            "target": f"wiki:{title}",
                            "target_type": "wpg",
                            "source_ref_id": source_id,
                            "wiki_page_title": title,
                            "syntax": "wiki_markup",
                            "children": [{"type": "text", "text": title}],
                        }
                    )
                    cursor = match.end()

                if cursor < len(text):
                    rewritten.append({"type": "text", "text": text[cursor:]})
                continue

            rewritten.append(_rewrite_wiki_markup_links(item, title_to_id))
        return rewritten

    if isinstance(value, dict):
        result = dict(value)
        for key, nested in value.items():
            if isinstance(nested, (dict, list)):
                result[key] = _rewrite_wiki_markup_links(nested, title_to_id)
        return result

    return value


def _collect_internal_links(value: Any, page_id: str) -> list[dict[str, str]]:
    links: list[dict[str, str]] = []
    if isinstance(value, dict):
        if value.get("type") == "internal_link":
            target_type = str(value.get("target_type", ""))
            source_id = str(value.get("source_ref_id", ""))
            links.append(
                {
                    "from_page_id": page_id,
                    "target": str(value.get("target", "")),
                    "target_type": target_type,
                    "source_id": source_id,
                    "scope": "wiki_page" if target_type == "wpg" else "repository_object",
                }
            )
        for nested in value.values():
            links.extend(_collect_internal_links(nested, page_id))
    elif isinstance(value, list):
        for nested in value:
            links.extend(_collect_internal_links(nested, page_id))
    return links


class WikiParser:
    """Parse one native ILIAS Wiki export set.

    ILIAS exports Wiki metadata/pages through the Wiki DataSet and the current
    content of every page through COPage items named wpg:<page_id>. Historic
    revisions are not part of the native XML page export, so the neutral model
    explicitly records a current-pages-only history policy.
    """

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

    def _components(self) -> list[str]:
        prefix = f"{self.base}/components/ILIAS/"
        components: set[str] = set()
        for name in self.names:
            if not name.startswith(prefix):
                continue
            relative = name[len(prefix) :]
            if "/" in relative:
                components.add(relative.split("/", 1)[0])
        return sorted(components)

    def parse(self) -> dict[str, Any]:
        wiki_component = self._component_export("Wiki")
        copage_component = self._component_export("COPage")
        if wiki_component is None:
            raise ValueError("Composant ILIAS/Wiki introuvable")
        if copage_component is None:
            raise ValueError("Composant ILIAS/COPage introuvable pour le wiki")

        wiki_root = self._parse_xml(wiki_component)
        wiki_records = _records(wiki_root, "wiki")
        if not wiki_records:
            raise ValueError("Enregistrement Wiki introuvable")
        wiki_record = wiki_records[0]

        object_id = _text_descendant(wiki_record, "Id")
        media = self.page_parser._parse_media()
        files = self.page_parser._parse_files()

        copage_root = self._parse_xml(copage_component)
        copages: dict[str, ET.Element] = {}
        for candidate in copage_root.iter():
            if _local_name(candidate.tag) != "ExportItem":
                continue
            identifier = candidate.attrib.get("Id", "")
            if identifier.startswith("wpg:"):
                copages[identifier.split(":", 1)[1]] = candidate

        important_pages: dict[str, dict[str, int]] = {}
        for record in _records(wiki_root, "wiki_imp_page"):
            page_id = _text_descendant(record, "PageId")
            if not page_id:
                continue
            important_pages[page_id] = {
                "order": int(_text_descendant(record, "Ord", "0") or 0),
                "indent": int(_text_descendant(record, "Indent", "0") or 0),
            }

        page_records = _records(wiki_root, "wpg")
        title_to_id = {
            _text_descendant(record, "Title"): _text_descendant(record, "Id")
            for record in page_records
            if _text_descendant(record, "Title") and _text_descendant(record, "Id")
        }

        pages: list[dict[str, Any]] = []
        unsupported: list[dict[str, str]] = []
        all_links: list[dict[str, str]] = []

        for record in page_records:
            page_id = _text_descendant(record, "Id")
            export_item = copages.get(page_id)

            page: dict[str, Any] = {
                "source_id": page_id,
                "wiki_id": _text_descendant(record, "WikiId"),
                "title": _text_descendant(record, "Title"),
                "blocked": _text_descendant(record, "Blocked"),
                "rating": _text_descendant(record, "Rating"),
                "template_new_pages": _text_descendant(record, "TemplateNewPages"),
                "template_add_to_page": _text_descendant(record, "TemplateAddToPage"),
                "language": _text_descendant(record, "Lang"),
                "import_id": _text_descendant(record, "ImportId"),
                "important_page": important_pages.get(page_id),
                "content": None,
                "internal_links": [],
            }

            if export_item is None:
                page["content"] = {
                    "status": "missing",
                    "blocks": [],
                    "unsupported_components": [],
                }
                pages.append(page)
                continue

            page_object = _first_descendant(export_item, "PageObject")
            if page_object is None:
                page["content"] = {
                    "status": "missing_page_object",
                    "blocks": [],
                    "unsupported_components": [],
                }
                pages.append(page)
                continue

            blocks = self.page_parser._parse_page_children(page_object, media, files)
            blocks = _rewrite_wiki_markup_links(blocks, title_to_id)
            page_unsupported = self.page_parser._collect_unsupported(blocks)
            links = _collect_internal_links(blocks, page_id)
            page["content"] = {
                "status": "ok",
                "export_id": export_item.attrib.get("Id", ""),
                "active": page_object.attrib.get("Active", ""),
                "language": page_object.attrib.get("Language", ""),
                "blocks": blocks,
                "unsupported_components": page_unsupported,
            }
            page["internal_links"] = links

            for component in page_unsupported:
                unsupported.append(
                    {
                        "page_id": page_id,
                        "element": str(component.get("element", "")),
                    }
                )
            all_links.extend(links)
            pages.append(page)

        start_page = _text_descendant(wiki_record, "StartPage")
        start_page_id = ""
        for page in pages:
            if page["title"] == start_page:
                start_page_id = str(page["source_id"])
                break

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "export_base": self.base,
            },
            "title": _text_descendant(wiki_record, "Title"),
            "description": _text_descendant(wiki_record, "Description"),
            "start_page": {
                "title": start_page,
                "source_id": start_page_id,
            },
            "settings": {
                "short": _text_descendant(wiki_record, "Short"),
                "introduction": _text_descendant(wiki_record, "Introduction"),
                "rating": _text_descendant(wiki_record, "Rating"),
                "public_notes": _text_descendant(wiki_record, "PublicNotes"),
                "page_toc": _text_descendant(wiki_record, "PageToc"),
                "rating_side": _text_descendant(wiki_record, "RatingSide"),
                "rating_new": _text_descendant(wiki_record, "RatingNew"),
                "rating_ext": _text_descendant(wiki_record, "RatingExt"),
                "rating_overall": _text_descendant(wiki_record, "RatingOverall"),
                "link_md_values": _text_descendant(wiki_record, "LinkMdValues"),
                "empty_page_template": _text_descendant(wiki_record, "EmptyPageTempl"),
            },
            "pages": pages,
            "important_pages": [
                {
                    "page_id": page_id,
                    **values,
                }
                for page_id, values in sorted(
                    important_pages.items(),
                    key=lambda item: (item[1]["order"], item[0]),
                )
            ],
            "internal_links": all_links,
            "media": media,
            "files": files,
            "history": {
                "source_export_contains_history": False,
                "migration_policy": "current_pages_only",
                "authors_migrated": False,
                "note": (
                    "L'export XML Wiki/COPage contient les pages courantes ; "
                    "les révisions historiques et leurs auteurs ne sont pas migrés."
                ),
            },
            "export_components": self._components(),
            "unsupported_components": unsupported,
        }


def find_wiki_export_sets(archive: zipfile.ZipFile) -> list[dict[str, str]]:
    """Return wiki export sets from a native ILIAS course ZIP."""

    return find_export_sets(archive, "wiki")

def parse_wikis(archive_path: str | PurePosixPath) -> list[dict[str, Any]]:
    """Parse every Wiki contained in a native ILIAS course ZIP."""

    with zipfile.ZipFile(str(archive_path)) as archive:
        wikis: list[dict[str, Any]] = []
        for export_set in find_wiki_export_sets(archive):
            wiki = WikiParser(archive, export_set["path"]).parse()
            wiki["source"]["object_id"] = (
                wiki["source"].get("object_id") or export_set["object_id"]
            )
            wikis.append(wiki)
        return wikis
