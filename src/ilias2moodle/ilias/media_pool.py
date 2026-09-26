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


def _records(root: ET.Element, entity: str) -> list[ET.Element]:
    wanted = entity.lower()
    result: list[ET.Element] = []
    for candidate in root.iter():
        if _local_name(candidate.tag) != "Rec":
            continue
        if candidate.attrib.get("Entity", "").lower() == wanted:
            result.append(candidate)
    return result


class MediaPoolParser:
    """Parse one native ILIAS Media Pool (mep) export set."""

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

    def _component_exports(self, component: str) -> list[str]:
        prefix = f"{self.base}/components/ILIAS/{component}/"
        pattern = re.compile(
            rf"^{re.escape(prefix)}set_(\d+)/export\.xml$"
        )
        matches: list[tuple[int, str]] = []
        for canonical, actual in self.names.items():
            match = pattern.match(canonical)
            if match:
                matches.append((int(match.group(1)), actual))
        return [path for _, path in sorted(matches)]

    def _parse_xml(self, member: str) -> ET.Element:
        return ET.fromstring(self.archive.read(member))

    def _parse_media(self) -> dict[str, dict[str, Any]]:
        media: dict[str, dict[str, Any]] = {}

        for component in self._component_exports("MediaObjects"):
            root = self._parse_xml(component)
            for export_item in root.iter():
                if _local_name(export_item.tag) != "ExportItem":
                    continue

                mob = _first_descendant(export_item, "Mob")
                if mob is None:
                    continue

                source_id = (
                    _text_descendant(mob, "Id")
                    or export_item.attrib.get("Id", "")
                )
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
                            "location_type": _text_descendant(
                                candidate, "LocationType"
                            ),
                            "mime_type": _text_descendant(candidate, "Format"),
                            "width": _text_descendant(candidate, "Width"),
                            "height": _text_descendant(candidate, "Height"),
                            "horizontal_align": _text_descendant(
                                candidate, "Halign"
                            ),
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

    def _parse_copages(
        self,
        media: dict[str, dict[str, Any]],
    ) -> dict[str, dict[str, Any]]:
        component = self._component_export("COPage")
        if component is None:
            return {}

        root = self._parse_xml(component)
        pages: dict[str, dict[str, Any]] = {}

        for export_item in root.iter():
            if _local_name(export_item.tag) != "ExportItem":
                continue

            identifier = export_item.attrib.get("Id", "")
            if not identifier.startswith("mep:"):
                continue

            tree_id = identifier.split(":", 1)[1]
            page_object = _first_descendant(export_item, "PageObject")
            if page_object is None:
                pages[tree_id] = {
                    "status": "missing_page_object",
                    "export_id": identifier,
                    "blocks": [],
                    "unsupported_components": [],
                }
                continue

            blocks = self.page_parser._parse_page_children(
                page_object,
                media,
                {},
            )
            pages[tree_id] = {
                "status": "ok",
                "export_id": identifier,
                "active": page_object.attrib.get("Active", ""),
                "language": page_object.attrib.get("Language", ""),
                "blocks": blocks,
                "unsupported_components": (
                    self.page_parser._collect_unsupported(blocks)
                ),
            }

        return pages

    @staticmethod
    def _folder_path(
        node: dict[str, Any],
        by_child: dict[str, dict[str, Any]],
    ) -> list[str]:
        path: list[str] = []
        seen: set[str] = set()
        parent = str(node.get("parent_id", ""))

        while parent and parent != "0" and parent not in seen:
            seen.add(parent)
            ancestor = by_child.get(parent)
            if ancestor is None:
                break

            if str(ancestor.get("type", "")) == "fold":
                title = str(ancestor.get("title", "")).strip()
                if title:
                    path.append(title)

            parent = str(ancestor.get("parent_id", ""))

        return list(reversed(path))

    def parse(self) -> dict[str, Any]:
        component = self._component_export("MediaPool")
        if component is None:
            raise ValueError("Composant ILIAS/MediaPool introuvable")

        root = self._parse_xml(component)
        mep_records = _records(root, "mep")
        if not mep_records:
            raise ValueError("Enregistrement Media Pool introuvable")

        mep = mep_records[0]
        object_id = _text_descendant(mep, "Id")
        media = self._parse_media()
        copages = self._parse_copages(media)

        tree: list[dict[str, Any]] = []
        by_child: dict[str, dict[str, Any]] = {}

        for position, record in enumerate(_records(root, "mep_tree"), start=1):
            node = {
                "source_tree_id": _text_descendant(record, "Child"),
                "parent_id": _text_descendant(record, "Parent"),
                "depth": int(
                    _text_descendant(record, "Depth", "0") or 0
                ),
                "type": _text_descendant(record, "Type"),
                "title": _text_descendant(record, "Title"),
                "foreign_id": _text_descendant(record, "ForeignId"),
                "import_id": _text_descendant(record, "ImportId"),
                "position": position,
            }
            tree.append(node)
            by_child[str(node["source_tree_id"])] = node

        records: list[dict[str, Any]] = []
        unsupported: list[dict[str, str]] = []

        for node in tree:
            node_type = str(node["type"])
            tree_id = str(node["source_tree_id"])

            if node_type in {"dummy", "fold"}:
                continue

            folder_path = self._folder_path(node, by_child)

            if node_type == "mob":
                media_id = str(node["foreign_id"])
                media_object = media.get(media_id)
                if media_object is None:
                    unsupported.append(
                        {
                            "tree_id": tree_id,
                            "element": "missing_media_object",
                        }
                    )

                records.append(
                    {
                        "source_tree_id": tree_id,
                        "position": int(node["position"]),
                        "depth": int(node["depth"]),
                        "item_type": "media",
                        "title": str(node["title"]),
                        "folder_path": folder_path,
                        "media_source_id": media_id,
                        "media": media_object,
                        "content": None,
                    }
                )
                continue

            if node_type == "pg":
                content = copages.get(
                    tree_id,
                    {
                        "status": "missing",
                        "export_id": f"mep:{tree_id}",
                        "blocks": [],
                        "unsupported_components": [],
                    },
                )
                for component_info in content.get(
                    "unsupported_components", []
                ):
                    if isinstance(component_info, dict):
                        unsupported.append(
                            {
                                "tree_id": tree_id,
                                "element": str(
                                    component_info.get("element", "")
                                ),
                            }
                        )

                records.append(
                    {
                        "source_tree_id": tree_id,
                        "position": int(node["position"]),
                        "depth": int(node["depth"]),
                        "item_type": "page",
                        "title": str(node["title"]),
                        "folder_path": folder_path,
                        "media_source_id": "",
                        "media": None,
                        "content": content,
                    }
                )
                continue

            unsupported.append(
                {
                    "tree_id": tree_id,
                    "element": f"mep_tree:{node_type}",
                }
            )

        direct_media_ids = {
            str(record["media_source_id"])
            for record in records
            if record["item_type"] == "media"
            and str(record["media_source_id"])
        }

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "export_base": self.base,
            },
            "title": _text_descendant(mep, "Title"),
            "description": _text_descendant(mep, "Description"),
            "settings": {
                "default_width": _text_descendant(mep, "DefaultWidth"),
                "default_height": _text_descendant(mep, "DefaultHeight"),
            },
            "tree": tree,
            "records": records,
            "media": media,
            "counts": {
                "tree_nodes": len(tree),
                "content_records": len(records),
                "folders": sum(
                    1 for node in tree if node["type"] == "fold"
                ),
                "direct_media": len(direct_media_ids),
                "all_media": len(media),
                "page_items": sum(
                    1
                    for record in records
                    if record["item_type"] == "page"
                ),
            },
            "unsupported_components": unsupported,
            "target_strategy": {
                "moodle_activity": "mod_data",
                "collection_policy": "one_database_per_media_pool",
                "record_policy": "one_record_per_content_tree_item",
                "folder_policy": "preserve_as_folder_path_metadata",
                "dummy_policy": "skip",
                "status": "proposed_pending_moodle_validation",
            },
        }


def find_media_pool_export_sets(archive: zipfile.ZipFile) -> list[dict[str, str]]:
    """Return mep export sets from a native ILIAS course ZIP."""

    return find_export_sets(archive, "mep")

def parse_media_pools(
    archive_path: str | PurePosixPath,
) -> list[dict[str, Any]]:
    with zipfile.ZipFile(str(archive_path)) as archive:
        pools: list[dict[str, Any]] = []
        for export_set in find_media_pool_export_sets(archive):
            pool = MediaPoolParser(
                archive,
                export_set["path"],
            ).parse()
            pool["source"]["object_id"] = (
                pool["source"].get("object_id")
                or export_set["object_id"]
            )
            pools.append(pool)
        return pools
