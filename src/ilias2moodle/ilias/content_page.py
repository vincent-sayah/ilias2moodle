from __future__ import annotations

import re
import zipfile
from pathlib import PurePosixPath
from typing import Any
from xml.etree import ElementTree as ET


def _local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def _first_descendant(element: ET.Element, name: str) -> ET.Element | None:
    for candidate in element.iter():
        if _local_name(candidate.tag) == name:
            return candidate
    return None


def _text_descendant(element: ET.Element, name: str, default: str = "") -> str:
    candidate = _first_descendant(element, name)
    if candidate is None or candidate.text is None:
        return default
    return candidate.text.strip()


def _element_text(element: ET.Element) -> str:
    return "".join(element.itertext()).strip()


def _source_id_from_identifier(value: str) -> str:
    match = re.search(r"_(\d+)$", value)
    if match:
        return match.group(1)
    return value.rsplit(":", 1)[-1].strip()


def _normalized_external_href(href: str, text: str) -> str:
    """Rebuild native COPage ExtLink URLs when only the scheme is in Href."""
    href = href.strip()
    text = text.strip()

    if href in {"http://", "https://"}:
        if text.startswith(("http://", "https://")):
            return text
        if text:
            return href + text
    return href


def _ilias_type_from_target(value: str) -> str:
    # Native COPage exports commonly use il__<type>_<id> (for example
    # il__wpg_12), while other components/releases may include an installation
    # id before or after the object type. Accept all observed layouts.
    match = re.search(r"^il__([a-z][a-z0-9]*)_\d+$", value)
    if match:
        return match.group(1)
    match = re.search(r"_([a-z][a-z0-9]*)_\d+_\d+$", value)
    if match:
        return match.group(1)
    match = re.search(r"_\d+_([a-z][a-z0-9]*)_\d+$", value)
    return match.group(1) if match else ""


class ContentPageParser:
    """Parse one native ILIAS Content Page export set.

    This parser is intentionally isolated from the historical course parser while
    Phase 6.5 is validated on the real POC. It preserves Page Editor semantics in
    a neutral structure that can later be rendered as Moodle mod_page HTML.
    """

    def __init__(self, archive: zipfile.ZipFile, base: str) -> None:
        self.archive = archive
        self.base = base.strip("/")
        self.names = {name.lstrip("/"): name for name in archive.namelist()}

    def _archive_member(self, suffix: str) -> str | None:
        path = f"{self.base}/{suffix.lstrip('/')}"
        actual = self.names.get(path)
        return actual

    def _component_export(self, component: str, set_number: int = 0) -> str | None:
        return self._archive_member(
            f"components/ILIAS/{component}/set_{set_number}/export.xml"
        )

    def _parse_xml(self, member: str) -> ET.Element:
        return ET.fromstring(self.archive.read(member))

    def parse(self) -> dict[str, Any]:
        content_component = self._component_export("ContentPage")
        copage_component = self._component_export("COPage")
        if content_component is None:
            raise ValueError("Composant ILIAS/ContentPage introuvable")
        if copage_component is None:
            raise ValueError("Composant ILIAS/COPage introuvable")

        content_root = self._parse_xml(content_component)
        copa = _first_descendant(content_root, "Copa")
        if copa is None:
            raise ValueError("Enregistrement Copa introuvable")

        object_id = _text_descendant(copa, "id")
        title = _text_descendant(copa, "title")
        description = _text_descendant(copa, "description")

        media = self._parse_media()
        files = self._parse_files()

        copage_root = self._parse_xml(copage_component)
        export_item = next(
            (
                candidate
                for candidate in copage_root.iter()
                if _local_name(candidate.tag) == "ExportItem"
                and candidate.attrib.get("Id", "").startswith("copa:")
            ),
            None,
        )
        if export_item is None:
            raise ValueError("ExportItem copa introuvable dans COPage")

        page_object = _first_descendant(export_item, "PageObject")
        if page_object is None:
            raise ValueError("PageObject Content Page introuvable")

        blocks = self._parse_page_children(page_object, media, files)
        unsupported = self._collect_unsupported(blocks)

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "export_base": self.base,
                "copage_export_id": export_item.attrib.get("Id", ""),
            },
            "title": title,
            "description": description,
            "active": page_object.attrib.get("Active", ""),
            "language": page_object.attrib.get("Language", ""),
            "blocks": blocks,
            "media": media,
            "files": files,
            "unsupported_components": unsupported,
        }

    def _parse_media(self) -> dict[str, dict[str, Any]]:
        component = self._component_export("MediaObjects")
        if component is None:
            return {}

        root = self._parse_xml(component)
        media: dict[str, dict[str, Any]] = {}
        for export_item in root.iter():
            if _local_name(export_item.tag) != "ExportItem":
                continue
            mob = _first_descendant(export_item, "Mob")
            if mob is None:
                continue
            source_id = _text_descendant(mob, "Id") or export_item.attrib.get("Id", "")
            container = _text_descendant(mob, "MediaContainer")
            items: list[dict[str, Any]] = []
            for candidate in export_item.iter():
                if _local_name(candidate.tag) != "MobMediaItem":
                    continue
                location = _text_descendant(candidate, "Location")
                archive_path = (
                    f"{self.base}/{container.rstrip('/')}/{location.lstrip('/')}"
                    if container and location
                    else ""
                )
                items.append(
                    {
                        "id": _text_descendant(candidate, "Id"),
                        "purpose": _text_descendant(candidate, "Purpose"),
                        "location": location,
                        "location_type": _text_descendant(candidate, "LocationType"),
                        "mime_type": _text_descendant(candidate, "Format"),
                        "width": _text_descendant(candidate, "Width"),
                        "height": _text_descendant(candidate, "Height"),
                        "horizontal_align": _text_descendant(candidate, "Halign"),
                        "caption": _text_descendant(candidate, "Caption"),
                        "text_representation": _text_descendant(
                            candidate, "TextRepresentation"
                        ),
                        "archive_path": archive_path,
                    }
                )
            media[source_id] = {
                "source_id": source_id,
                "title": _text_descendant(mob, "Title"),
                "description": _text_descendant(mob, "Description"),
                "media_container": container,
                "items": items,
            }
        return media

    def _parse_files(self) -> dict[str, dict[str, Any]]:
        component = self._component_export("File")
        if component is None:
            return {}

        root = self._parse_xml(component)
        files: dict[str, dict[str, Any]] = {}
        for export_item in root.iter():
            if _local_name(export_item.tag) != "ExportItem":
                continue
            file_element = _first_descendant(export_item, "File")
            if file_element is None:
                continue
            source_id = export_item.attrib.get("Id", "")
            identifier = file_element.attrib.get("obj_id", "")
            if not source_id and identifier:
                source_id = _source_id_from_identifier(identifier)
            version = _first_descendant(file_element, "Version")
            relative = (version.text or "").strip() if version is not None else ""
            files[source_id] = {
                "source_id": source_id,
                "identifier": identifier,
                "filename": _text_descendant(file_element, "Filename"),
                "title": _text_descendant(file_element, "Title"),
                "description": _text_descendant(file_element, "Description"),
                "mime_type": file_element.attrib.get("type", ""),
                "size": int(file_element.attrib.get("size", "0") or 0),
                "archive_path": f"{self.base}/{relative}" if relative else "",
            }
        return files

    def _parse_inline_children(self, element: ET.Element) -> list[dict[str, Any]]:
        parts: list[dict[str, Any]] = []
        if element.text:
            parts.append({"type": "text", "text": element.text})

        for child in element:
            tag = _local_name(child.tag)
            children = self._parse_inline_children(child)
            text = _element_text(child)

            if tag == "Strong":
                parts.append({"type": "strong", "text": text, "children": children})
            elif tag in {"Emph", "Emphasis", "Italic"}:
                parts.append({"type": "emphasis", "text": text, "children": children})
            elif tag == "Underline":
                parts.append({"type": "underline", "text": text, "children": children})
            elif tag == "ExtLink":
                parts.append(
                    {
                        "type": "external_link",
                        "text": text,
                        "href": _normalized_external_href(
                            child.attrib.get("Href", ""),
                            text,
                        ),
                        "children": children,
                    }
                )
            elif tag in {"IntLink", "InternalLink"}:
                target = child.attrib.get("Target", "")
                parts.append(
                    {
                        "type": "internal_link",
                        "text": text,
                        "target": target,
                        "target_type": _ilias_type_from_target(target),
                        "source_ref_id": _source_id_from_identifier(target) if target else "",
                        "attributes": dict(child.attrib),
                        "children": children,
                    }
                )
            elif tag.lower() == "br":
                parts.append({"type": "line_break"})
            else:
                parts.append(
                    {
                        "type": "inline",
                        "element": tag,
                        "text": text,
                        "attributes": dict(child.attrib),
                        "children": children,
                    }
                )

            if child.tail:
                parts.append({"type": "text", "text": child.tail})

        return parts

    def _parse_page_content(
        self,
        element: ET.Element,
        media: dict[str, dict[str, Any]],
        files: dict[str, dict[str, Any]],
    ) -> dict[str, Any]:
        tag = _local_name(element.tag)

        if tag == "Paragraph":
            return {
                "type": "paragraph",
                "text": _element_text(element),
                "inline": self._parse_inline_children(element),
                "language": element.attrib.get("Language", ""),
                "characteristic": element.attrib.get("Characteristic", ""),
            }

        if tag == "MediaObject":
            alias = _first_descendant(element, "MediaAlias")
            origin_id = alias.attrib.get("OriginId", "") if alias is not None else ""
            source_id = _source_id_from_identifier(origin_id) if origin_id else ""
            alias_item = _first_descendant(element, "MediaAliasItem")
            layout = _first_descendant(element, "Layout")
            return {
                "type": "media",
                "source_id": source_id,
                "origin_id": origin_id,
                "purpose": alias_item.attrib.get("Purpose", "") if alias_item is not None else "",
                "horizontal_align": (
                    layout.attrib.get("HorizontalAlign", "") if layout is not None else ""
                ),
                "media": media.get(source_id),
            }

        if tag == "FileList":
            file_items: list[dict[str, Any]] = []
            for file_item in element.iter():
                if _local_name(file_item.tag) != "FileItem":
                    continue
                identifier = _first_descendant(file_item, "Identifier")
                entry = identifier.attrib.get("Entry", "") if identifier is not None else ""
                source_id = _source_id_from_identifier(entry) if entry else ""
                file_items.append(
                    {
                        "source_id": source_id,
                        "identifier": entry,
                        "filename": _text_descendant(file_item, "Location"),
                        "mime_type": _text_descendant(file_item, "Format"),
                        "file": files.get(source_id),
                    }
                )
            return {
                "type": "file_list",
                "title": _text_descendant(element, "Title"),
                "files": file_items,
            }

        if tag == "Table":
            rows: list[list[list[dict[str, Any]]]] = []
            for row in element:
                if _local_name(row.tag) != "TableRow":
                    continue
                cells: list[list[dict[str, Any]]] = []
                for cell in row:
                    if _local_name(cell.tag) != "TableData":
                        continue
                    cells.append(self._parse_page_children(cell, media, files))
                rows.append(cells)
            return {
                "type": "table",
                "language": element.attrib.get("Language", ""),
                "class": element.attrib.get("Class", ""),
                "data_table": element.attrib.get("DataTable", ""),
                "rows": rows,
            }

        if tag == "Section":
            return {
                "type": "section",
                "characteristic": element.attrib.get("Characteristic", ""),
                "blocks": self._parse_page_children(element, media, files),
            }

        if tag == "Tabs":
            tabs: list[dict[str, Any]] = []
            for tab in element:
                if _local_name(tab.tag) != "Tab":
                    continue
                tabs.append(
                    {
                        "caption": _text_descendant(tab, "TabCaption"),
                        "blocks": self._parse_page_children(tab, media, files),
                    }
                )
            return {
                "type": "tabs",
                "mode": element.attrib.get("Type", ""),
                "behavior": element.attrib.get("Behavior", ""),
                "tabs": tabs,
            }

        if tag == "Grid":
            cells: list[dict[str, Any]] = []
            for cell in element:
                if _local_name(cell.tag) != "GridCell":
                    continue
                cells.append(
                    {
                        "widths": {
                            key.lower(): value
                            for key, value in cell.attrib.items()
                            if key.startswith("WIDTH_")
                        },
                        "blocks": self._parse_page_children(cell, media, files),
                    }
                )
            return {"type": "grid", "cells": cells}

        if tag in {"IntLink", "InternalLink"}:
            target = element.attrib.get("Target", "")
            return {
                "type": "internal_link",
                "text": _element_text(element),
                "target": target,
                "target_type": _ilias_type_from_target(target),
                "source_ref_id": _source_id_from_identifier(target) if target else "",
                "attributes": dict(element.attrib),
            }

        return {
            "type": "unsupported",
            "element": tag,
            "text": _element_text(element),
            "attributes": dict(element.attrib),
        }

    def _parse_page_children(
        self,
        parent: ET.Element,
        media: dict[str, dict[str, Any]],
        files: dict[str, dict[str, Any]],
    ) -> list[dict[str, Any]]:
        blocks: list[dict[str, Any]] = []
        for child in parent:
            tag = _local_name(child.tag)
            if tag == "PageContent":
                blocks.extend(self._parse_page_children(child, media, files))
            elif tag == "TabCaption":
                continue
            else:
                blocks.append(self._parse_page_content(child, media, files))
        return blocks

    def _collect_unsupported(self, blocks: list[dict[str, Any]]) -> list[dict[str, str]]:
        unsupported: list[dict[str, str]] = []
        for block in blocks:
            block_type = str(block.get("type", ""))
            if block_type == "unsupported":
                unsupported.append({"element": str(block.get("element", ""))})
            elif block_type == "section":
                unsupported.extend(self._collect_unsupported(block.get("blocks", [])))
            elif block_type == "table":
                for row in block.get("rows", []):
                    for cell in row:
                        unsupported.extend(self._collect_unsupported(cell))
            elif block_type == "tabs":
                for tab in block.get("tabs", []):
                    unsupported.extend(self._collect_unsupported(tab.get("blocks", [])))
            elif block_type == "grid":
                for cell in block.get("cells", []):
                    unsupported.extend(self._collect_unsupported(cell.get("blocks", [])))
        return unsupported


def find_content_page_export_sets(archive: zipfile.ZipFile) -> list[dict[str, str]]:
    """Return Content Page export sets from a native ILIAS course ZIP."""

    manifest_name = next(
        (name for name in archive.namelist() if name.lstrip("/") == "manifest.xml"),
        None,
    )
    if manifest_name is None:
        raise ValueError("manifest.xml racine introuvable")

    root = ET.fromstring(archive.read(manifest_name))
    results: list[dict[str, str]] = []
    for element in root:
        if _local_name(element.tag) != "ExportSet":
            continue
        if element.attrib.get("Type") != "copa":
            continue
        path = element.attrib.get("Path", "").lstrip("/")
        match = re.search(r"__copa_(\d+)$", path)
        results.append(
            {
                "object_id": match.group(1) if match else "",
                "path": path,
                "type": "copa",
            }
        )
    return results


def parse_content_pages(archive_path: str | PurePosixPath) -> list[dict[str, Any]]:
    """Parse every Content Page contained in a native ILIAS course ZIP."""

    with zipfile.ZipFile(str(archive_path)) as archive:
        pages: list[dict[str, Any]] = []
        for export_set in find_content_page_export_sets(archive):
            page = ContentPageParser(archive, export_set["path"]).parse()
            page["source"]["object_id"] = page["source"].get("object_id") or export_set[
                "object_id"
            ]
            pages.append(page)
        return pages
