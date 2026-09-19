from __future__ import annotations

import zipfile
from pathlib import PurePosixPath
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


class MediaCastParser:
    """Parse one native ILIAS MediaCast export set."""

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

    def _component_export(
        self, component: str, set_number: int = 0
    ) -> str | None:
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

    def _media_objects(self) -> dict[str, dict[str, Any]]:
        component = self._component_export("MediaObjects")
        if component is None:
            return {}

        root = self._parse_xml(component)
        result: dict[str, dict[str, Any]] = {}

        for export_item in root.iter():
            if _local_name(export_item.tag) != "ExportItem":
                continue

            mob = next(
                (
                    candidate
                    for candidate in export_item.iter()
                    if _local_name(candidate.tag) == "Mob"
                ),
                None,
            )
            if mob is None:
                continue

            mob_id = (
                _text_descendant(mob, "Id")
                or export_item.attrib.get("Id", "")
            )
            if not mob_id:
                continue

            container = _text_descendant(mob, "MediaContainer")
            media_items: list[dict[str, Any]] = []

            for candidate in export_item.iter():
                if _local_name(candidate.tag) != "MobMediaItem":
                    continue

                location = _text_descendant(candidate, "Location")
                location_type = _text_descendant(
                    candidate, "LocationType"
                )
                archive_path = ""
                embedded = False

                if (
                    location_type == "LocalFile"
                    and container
                    and location
                ):
                    archive_path = (
                        f"{self.base}/{container.rstrip('/')}/"
                        f"{location.lstrip('/')}"
                    )
                    embedded = archive_path in self.infos

                media_items.append(
                    {
                        "source_id": _text_descendant(candidate, "Id"),
                        "mob_id": mob_id,
                        "purpose": _text_descendant(
                            candidate, "Purpose"
                        ),
                        "location": location,
                        "location_type": location_type,
                        "format": _text_descendant(
                            candidate, "Format"
                        ),
                        "width": _int(candidate, "Width", 0),
                        "height": _int(candidate, "Height", 0),
                        "caption": _text_descendant(
                            candidate, "Caption"
                        ),
                        "text_representation": _text_descendant(
                            candidate, "TextRepresentation"
                        ),
                        "archive_path": archive_path,
                        "embedded": embedded,
                    }
                )

            preview_assets: list[dict[str, Any]] = []
            if container:
                prefix = (
                    f"{self.base}/{container.rstrip('/')}/"
                )
                for archive_path, info in self.infos.items():
                    if not archive_path.startswith(prefix):
                        continue
                    relative = archive_path[len(prefix) :]
                    if not relative:
                        continue
                    if any(
                        media.get("embedded")
                        and media.get("archive_path") == archive_path
                        for media in media_items
                    ):
                        continue
                    preview_assets.append(
                        {
                            "filename": PurePosixPath(
                                archive_path
                            ).name,
                            "relative_path": relative,
                            "archive_path": archive_path,
                            "size": int(info.file_size),
                            "embedded": True,
                        }
                    )

            result[mob_id] = {
                "source_id": mob_id,
                "title": _text_descendant(mob, "Title"),
                "description": _text_descendant(
                    mob, "Description"
                ),
                "media_container": container,
                "media_items": media_items,
                "preview_assets": preview_assets,
            }

        return result

    def _news_items(self) -> list[dict[str, Any]]:
        component = self._component_export("News")
        if component is None:
            return []

        root = self._parse_xml(component)
        items: list[dict[str, Any]] = []

        for candidate in root.iter():
            if _local_name(candidate.tag) != "News":
                continue
            items.append(
                {
                    "source_id": _text_descendant(candidate, "Id"),
                    "title": _text_descendant(candidate, "Title"),
                    "description": _text_descendant(
                        candidate, "Content"
                    )
                    or _text_descendant(candidate, "ContentLong"),
                    "priority": _int(candidate, "Priority", 0),
                    "context_object_id": _text_descendant(
                        candidate, "ContextObjId"
                    ),
                    "context_object_type": _text_descendant(
                        candidate, "ContextObjType"
                    ),
                    "content_type": _text_descendant(
                        candidate, "ContentType"
                    ),
                    "visibility": _text_descendant(
                        candidate, "Visibility"
                    ),
                    "mob_id": _text_descendant(candidate, "MobId"),
                    "playtime": _text_descendant(
                        candidate, "Playtime"
                    ),
                }
            )

        return items

    def parse(self) -> dict[str, Any]:
        component = self._component_export("MediaCast")
        if component is None:
            raise ValueError("Composant ILIAS/MediaCast introuvable")

        root = self._parse_xml(component)
        mcst = next(
            (
                candidate
                for candidate in root.iter()
                if _local_name(candidate.tag) == "Mcst"
            ),
            None,
        )
        if mcst is None:
            raise ValueError("Élément Mcst introuvable")

        object_id = _text_descendant(mcst, "Id")
        media_objects = self._media_objects()
        news_items = self._news_items()

        manual_order = [
            value
            for value in _text_descendant(
                mcst, "Order"
            ).split(";")
            if value
        ]
        order_index = {
            source_id: index
            for index, source_id in enumerate(manual_order)
        }

        entries: list[dict[str, Any]] = []
        missing_assets: list[dict[str, str]] = []

        for position, news in enumerate(news_items, start=1):
            mob_id = str(news.get("mob_id", ""))
            mob = media_objects.get(mob_id, {})
            media_items = [
                value
                for value in mob.get("media_items", [])
                if isinstance(value, dict)
                and (
                    value.get("purpose") == "Standard"
                    or len(mob.get("media_items", [])) == 1
                )
            ]
            media = media_items[0] if media_items else {}

            location_type = str(
                media.get("location_type", "")
            )
            media_format = str(media.get("format", ""))
            location = str(media.get("location", ""))

            if location_type == "LocalFile":
                source_kind = "local_file"
                supported = media_format == "video/mp4"
            elif location_type == "Reference":
                source_kind = "external_url"
                supported = bool(location)
            else:
                source_kind = "unsupported"
                supported = False

            needs_recovery = (
                source_kind == "local_file"
                and not bool(media.get("embedded"))
            )

            if needs_recovery:
                missing_assets.append(
                    {
                        "entry_id": str(
                            news.get("source_id", "")
                        ),
                        "mob_id": mob_id,
                        "kind": "mediacast_local_media",
                        "source_path": location,
                    }
                )

            entries.append(
                {
                    "source_id": str(
                        news.get("source_id", "")
                    ),
                    "position": (
                        order_index.get(
                            str(news.get("source_id", "")),
                            position - 1,
                        )
                        + 1
                    ),
                    "title": str(
                        news.get("title", "")
                        or mob.get("title", "")
                    ),
                    "description": str(
                        news.get("description", "")
                        or mob.get("description", "")
                    ),
                    "playtime": str(
                        news.get("playtime", "")
                    ),
                    "visibility": str(
                        news.get("visibility", "")
                    ),
                    "content_type": str(
                        news.get("content_type", "")
                    ),
                    "mob_id": mob_id,
                    "media_object_title": str(
                        mob.get("title", "")
                    ),
                    "media_object_description": str(
                        mob.get("description", "")
                    ),
                    "source_kind": source_kind,
                    "supported": supported,
                    "location": location,
                    "location_type": location_type,
                    "format": media_format,
                    "media_item_source_id": str(
                        media.get("source_id", "")
                    ),
                    "embedded": bool(
                        media.get("embedded", False)
                    ),
                    "archive_path": str(
                        media.get("archive_path", "")
                    ),
                    "needs_recovery": needs_recovery,
                    "preview_assets": list(
                        mob.get("preview_assets", [])
                    ),
                }
            )

        entries.sort(
            key=lambda entry: (
                int(entry.get("position", 0)),
                str(entry.get("source_id", "")),
            )
        )
        for position, entry in enumerate(entries, start=1):
            entry["position"] = position

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "export_base": self.base,
            },
            "title": _text_descendant(mcst, "Title"),
            "description": _text_descendant(
                mcst, "Description"
            ),
            "settings": {
                "public_files": _bool(mcst, "PublicFiles"),
                "downloadable": _bool(mcst, "Downloadable"),
                "default_access": _int(
                    mcst, "DefaultAccess", 0
                ),
                "sortmode": _int(mcst, "Sortmode", 0),
                "viewmode": _text_descendant(
                    mcst, "Viewmode"
                ),
                "autoplaymode": _int(
                    mcst, "Autoplaymode", 0
                ),
                "nr_initial_videos": _int(
                    mcst, "NrInitialVideos", 0
                ),
                "new_items_in_lp": _bool(
                    mcst, "NewItemsInLp"
                ),
                "public_feed": _text_descendant(
                    mcst, "PublicFeed"
                ),
                "keep_rss_min": _int(
                    mcst, "KeepRssMin", 0
                ),
            },
            "entries": entries,
            "entry_count": len(entries),
            "local_file_count": sum(
                1
                for entry in entries
                if entry["source_kind"] == "local_file"
            ),
            "external_url_count": sum(
                1
                for entry in entries
                if entry["source_kind"] == "external_url"
            ),
            "unsupported_entry_count": sum(
                1
                for entry in entries
                if not entry["supported"]
            ),
            "components": self._components(),
            "missing_assets": missing_assets,
            "target_strategy": {
                "moodle_activity": "mod_data",
                "one_database_per_ilias_mediacast": True,
                "one_record_per_mediacast_entry": True,
                "local_mp4_field": "file",
                "external_url_field": "url",
                "preserve_title_description_order": True,
            },
            "migration_scope": {
                "supported_local_formats": ["video/mp4"],
                "supported_external_references": True,
                "webm_tested": False,
                "image_audio_tested": False,
            },
        }


def find_mediacast_export_sets(
    archive: zipfile.ZipFile,
) -> list[dict[str, str]]:
    manifest_name = next(
        (
            name
            for name in archive.namelist()
            if name.lstrip("/") == "manifest.xml"
        ),
        None,
    )
    if manifest_name is None:
        raise ValueError("manifest.xml racine introuvable")

    root = ET.fromstring(archive.read(manifest_name))
    result: list[dict[str, str]] = []

    for element in root:
        if _local_name(element.tag) != "ExportSet":
            continue
        if element.attrib.get("Type") != "mcst":
            continue

        path = element.attrib.get("Path", "").lstrip("/")
        object_id = (
            path.rsplit("__mcst_", 1)[-1]
            if "__mcst_" in path
            else ""
        )
        result.append(
            {
                "object_id": object_id,
                "path": path,
                "type": "mcst",
            }
        )

    return result


def parse_mediacasts(
    archive_path: str | PurePosixPath,
) -> list[dict[str, Any]]:
    with zipfile.ZipFile(str(archive_path)) as archive:
        mediacasts: list[dict[str, Any]] = []
        for export_set in find_mediacast_export_sets(archive):
            parsed = MediaCastParser(
                archive,
                export_set["path"],
            ).parse()
            if not parsed["source"]["object_id"]:
                parsed["source"]["object_id"] = export_set[
                    "object_id"
                ]
            mediacasts.append(parsed)
        return mediacasts
