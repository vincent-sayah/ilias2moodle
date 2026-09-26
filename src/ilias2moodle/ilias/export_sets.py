from __future__ import annotations

import re
import zipfile
from pathlib import PurePosixPath
from xml.etree import ElementTree as ET


def _local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def find_export_sets(
    archive: zipfile.ZipFile,
    object_type: str,
) -> list[dict[str, str]]:
    """Return ILIAS export sets for one object type.

    Supports the historical aggregate manifest layout and the newer
    multi-set layout with one manifest.xml per set_N directory.
    """

    names = archive.namelist()

    aggregate_manifest = next(
        (name for name in names if name.lstrip("/") == "manifest.xml"),
        None,
    )
    if aggregate_manifest is not None:
        root = ET.fromstring(archive.read(aggregate_manifest))
        results: list[dict[str, str]] = []
        aggregate_layout = False

        for element in root:
            if _local_name(element.tag) != "ExportSet":
                continue
            aggregate_layout = True
            if element.attrib.get("Type") != object_type:
                continue

            path = element.attrib.get("Path", "").lstrip("/")
            match = re.search(
                rf"__{re.escape(object_type)}_(\d+)$",
                path,
            )
            results.append(
                {
                    "object_id": match.group(1) if match else "",
                    "path": path,
                    "type": object_type,
                }
            )

        if aggregate_layout:
            return results

    multiset_results: list[tuple[int, dict[str, str]]] = []
    object_pattern = re.compile(
        rf"__{re.escape(object_type)}_(\d+)$"
    )

    for name in names:
        normalized = name.lstrip("/").rstrip("/")
        if PurePosixPath(normalized).name.lower() != "manifest.xml":
            continue

        base = str(PurePosixPath(normalized).parent)
        object_match = object_pattern.search(base)
        if object_match is None:
            continue

        try:
            root = ET.fromstring(archive.read(name))
        except ET.ParseError:
            continue

        main_entity = root.attrib.get("MainEntity", "")
        if main_entity and main_entity != object_type:
            continue

        set_match = re.match(r"set_(\d+)/", normalized)
        set_number = int(set_match.group(1)) if set_match else 0

        multiset_results.append(
            (
                set_number,
                {
                    "object_id": object_match.group(1),
                    "path": base,
                    "type": object_type,
                },
            )
        )

    multiset_results.sort(key=lambda entry: (entry[0], entry[1]["path"]))
    return [entry for _, entry in multiset_results]
