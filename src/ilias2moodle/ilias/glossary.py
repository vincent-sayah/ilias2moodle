from __future__ import annotations

import re
import zipfile
from pathlib import PurePosixPath
from typing import Any
from xml.etree import ElementTree as ET

from ilias2moodle.ilias.export_sets import find_export_sets

from ilias2moodle.ilias.content_page import (
    ContentPageParser,
    _first_descendant,
    _local_name,
    _text_descendant,
)


class GlossaryParser:
    """Parse one native ILIAS Glossary export set.

    The ILIAS glossary component stores object/term metadata in the Glossary
    dataset and the actual term definitions in COPage export items named
    ``term:<term_id>``. Page Editor media/files are resolved through the same
    neutral block parser already validated for Content Page migration.
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

    def parse(self) -> dict[str, Any]:
        glossary_component = self._component_export("Glossary")
        copage_component = self._component_export("COPage")
        if glossary_component is None:
            raise ValueError("Composant ILIAS/Glossary introuvable")
        if copage_component is None:
            raise ValueError("Composant ILIAS/COPage introuvable pour le glossaire")

        glossary_root = self._parse_xml(glossary_component)
        glossary = _first_descendant(glossary_root, "Glo")
        if glossary is None:
            raise ValueError("Enregistrement Glo introuvable")

        object_id = _text_descendant(glossary, "Id")
        media = self.page_parser._parse_media()
        files = self.page_parser._parse_files()

        copage_root = self._parse_xml(copage_component)
        copages: dict[str, ET.Element] = {}
        for candidate in copage_root.iter():
            if _local_name(candidate.tag) != "ExportItem":
                continue
            identifier = candidate.attrib.get("Id", "")
            if identifier.startswith("term:"):
                copages[identifier.split(":", 1)[1]] = candidate

        terms: list[dict[str, Any]] = []
        unsupported: list[dict[str, str]] = []
        for candidate in glossary_root.iter():
            if _local_name(candidate.tag) != "GloTerm":
                continue

            term_id = _text_descendant(candidate, "Id")
            term = {
                "source_id": term_id,
                "term": _text_descendant(candidate, "Term"),
                "language": _text_descendant(candidate, "Language"),
                "definition": None,
            }

            export_item = copages.get(term_id)
            if export_item is None:
                term["definition"] = {
                    "status": "missing",
                    "blocks": [],
                    "unsupported_components": [],
                }
                terms.append(term)
                continue

            page_object = _first_descendant(export_item, "PageObject")
            if page_object is None:
                term["definition"] = {
                    "status": "missing_page_object",
                    "blocks": [],
                    "unsupported_components": [],
                }
                terms.append(term)
                continue

            blocks = self.page_parser._parse_page_children(page_object, media, files)
            term_unsupported = self.page_parser._collect_unsupported(blocks)
            for component in term_unsupported:
                unsupported.append(
                    {
                        "term_id": term_id,
                        "element": str(component.get("element", "")),
                    }
                )

            term["definition"] = {
                "status": "ok",
                "export_id": export_item.attrib.get("Id", ""),
                "active": page_object.attrib.get("Active", ""),
                "language": page_object.attrib.get("Language", ""),
                "blocks": blocks,
                "unsupported_components": term_unsupported,
            }
            terms.append(term)

        show_tax = _text_descendant(glossary, "ShowTax")
        taxonomy_component_present = any(
            name.startswith(f"{self.base}/components/ILIAS/Taxonomy/")
            for name in self.names
        )

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "export_base": self.base,
            },
            "title": _text_descendant(glossary, "Title"),
            "description": _text_descendant(glossary, "Description"),
            "settings": {
                "virtual": _text_descendant(glossary, "Virtual"),
                "presentation_mode": _text_descendant(glossary, "PresMode"),
                "snippet_length": _text_descendant(glossary, "SnippetLength"),
                "show_taxonomy": show_tax,
                "glossary_menu_active": _text_descendant(glossary, "GloMenuActive"),
            },
            "taxonomy": {
                "enabled": show_tax not in {"", "0", "n", "N", "false", "False"},
                "export_component_present": taxonomy_component_present,
            },
            "terms": terms,
            "media": media,
            "files": files,
            "unsupported_components": unsupported,
        }


def find_glossary_export_sets(archive: zipfile.ZipFile) -> list[dict[str, str]]:
    """Return glo export sets from a native ILIAS course ZIP."""

    return find_export_sets(archive, "glo")

def parse_glossaries(archive_path: str | PurePosixPath) -> list[dict[str, Any]]:
    """Parse every Glossary contained in a native ILIAS course ZIP."""

    with zipfile.ZipFile(str(archive_path)) as archive:
        glossaries: list[dict[str, Any]] = []
        for export_set in find_glossary_export_sets(archive):
            glossary = GlossaryParser(archive, export_set["path"]).parse()
            glossary["source"]["object_id"] = (
                glossary["source"].get("object_id") or export_set["object_id"]
            )
            glossaries.append(glossary)
        return glossaries
