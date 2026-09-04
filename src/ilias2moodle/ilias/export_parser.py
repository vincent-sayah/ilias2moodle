from __future__ import annotations

import re
import zipfile
from pathlib import PurePosixPath
from typing import Any
from xml.etree import ElementTree as ET

from ilias2moodle.model import CourseExport, MigrationItem

TYPE_MAP = {
    "crs": "course",
    "fold": "folder",
    "file": "file",
    "webr": "url",
    "sahs": "scorm",
    "htlm": "html_module",
    "lm": "learning_module",
    "tst": "test",
    "qpl": "question_pool",
}


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


class IliasExportParser:
    """Parse un export natif XML/ZIP ILIAS 10 sans modifier l'instance source."""

    def __init__(self, archive_path: str | PurePosixPath) -> None:
        self.archive_path = str(archive_path)
        self.archive = zipfile.ZipFile(self.archive_path)
        self.names = self.archive.namelist()
        self.root_manifest_name = self._find_root_manifest()
        self.root_manifest = self._parse_xml(self.root_manifest_name)
        self.export_sets = self._index_export_sets()

    def close(self) -> None:
        self.archive.close()

    def __enter__(self) -> IliasExportParser:
        return self

    def __exit__(self, exc_type: Any, exc: Any, traceback: Any) -> None:
        self.close()

    def _find_root_manifest(self) -> str:
        for name in self.names:
            if name.lstrip("/") == "manifest.xml":
                return name
        raise ValueError("manifest.xml racine introuvable dans l'export ILIAS")

    def _parse_xml(self, name: str) -> ET.Element:
        return ET.fromstring(self.archive.read(name))

    def _index_export_sets(self) -> dict[str, dict[str, str]]:
        result: dict[str, dict[str, str]] = {}
        for element in self.root_manifest:
            if _local_name(element.tag) != "ExportSet":
                continue
            path = element.attrib.get("Path", "").lstrip("/")
            object_type = element.attrib.get("Type", "")
            match = re.search(r"__([a-z0-9]+)_(\d+)$", path)
            if match:
                result[match.group(2)] = {"path": path, "type": object_type}
        return result

    def _archive_member(self, base: str, suffix: str) -> str | None:
        path = f"{base.rstrip('/')}/{suffix.lstrip('/')}"
        if path in self.names:
            return path
        absolute_path = f"/{path}"
        if absolute_path in self.names:
            return absolute_path
        return None

    def _component_export(self, base: str, component: str, set_number: int = 0) -> str | None:
        return self._archive_member(
            base, f"components/ILIAS/{component}/set_{set_number}/export.xml"
        )

    def parse_course(self) -> CourseExport:
        main_object_id = ""
        main_export: dict[str, str] | None = None
        for object_id, export_set in self.export_sets.items():
            if export_set["type"] == "crs":
                main_object_id = object_id
                main_export = export_set
                break

        if main_export is None:
            raise ValueError("Aucun objet cours (crs) trouvé dans l'export ILIAS")

        container_path = self._component_export(main_export["path"], "Container")
        if container_path is None:
            raise ValueError("Composant ILIAS/Container du cours introuvable")

        container_root = self._parse_xml(container_path)
        course_element = next(
            (
                element
                for element in container_root.iter()
                if _local_name(element.tag) == "Item"
                and element.attrib.get("Type") == "crs"
            ),
            None,
        )
        if course_element is None:
            raise ValueError("Élément racine du cours introuvable dans Container")

        course = CourseExport(
            source_id=course_element.attrib.get("RefId", main_object_id),
            title=course_element.attrib.get(
                "Title", self.root_manifest.attrib.get("Title", "")
            ),
            metadata={
                "obj_id": course_element.attrib.get("Id", main_object_id),
                "ref_id": course_element.attrib.get("RefId", ""),
                "ilias_type": "crs",
                "installation_id": self.root_manifest.attrib.get("InstallationId", ""),
                "installation_url": self.root_manifest.attrib.get("InstallationUrl", ""),
            },
        )
        self._enrich_course(course, main_export["path"])

        position = 0
        for child in course_element:
            if _local_name(child.tag) != "Item":
                continue
            position += 1
            course.items.append(self._parse_item(child, position))

        return course

    def _parse_item(self, element: ET.Element, position: int) -> MigrationItem:
        ilias_type = element.attrib.get("Type", "unknown")
        object_id = element.attrib.get("Id", "")
        ref_id = element.attrib.get("RefId", object_id)
        metadata: dict[str, Any] = {
            "obj_id": object_id,
            "ref_id": ref_id,
            "ilias_type": ilias_type,
        }
        for attribute in ("Page", "StartPage", "Style", "Offline"):
            if attribute in element.attrib:
                metadata[attribute.lower()] = element.attrib[attribute]

        item = MigrationItem(
            source_id=ref_id,
            type=TYPE_MAP.get(ilias_type, ilias_type),
            title=element.attrib.get("Title", ""),
            position=position,
            metadata=metadata,
        )

        export_base = self.export_sets.get(object_id, {}).get("path")
        if export_base:
            self._enrich_item(item, ilias_type, export_base)

        child_position = 0
        for child in element:
            if _local_name(child.tag) != "Item":
                continue
            child_position += 1
            item.items.append(self._parse_item(child, child_position))

        return item

    def _enrich_item(self, item: MigrationItem, ilias_type: str, base: str) -> None:
        if ilias_type == "file":
            self._enrich_file(item, base)
        elif ilias_type == "webr":
            self._enrich_web_resource(item, base)
        elif ilias_type == "sahs":
            self._enrich_scorm(item, base)
        elif ilias_type == "htlm":
            self._enrich_html_module(item, base)
        elif ilias_type == "tst":
            self._enrich_test(item, base)
        elif ilias_type == "qpl":
            self._enrich_question_pool(item, base)

    def _enrich_file(self, item: MigrationItem, base: str) -> None:
        component = self._component_export(base, "File")
        if component is None:
            return
        root = self._parse_xml(component)
        file_element = _first_descendant(root, "File")
        if file_element is None:
            return

        item.description = _text_descendant(file_element, "Description")
        item.metadata.update(
            {
                "filename": _text_descendant(file_element, "Filename"),
                "mime_type": file_element.attrib.get("type", ""),
                "size": int(file_element.attrib.get("size", "0") or 0),
            }
        )
        version = _first_descendant(file_element, "Version")
        if version is not None and version.text:
            item.metadata["archive_path"] = f"{base}/{version.text.strip()}"

    def _enrich_web_resource(self, item: MigrationItem, base: str) -> None:
        component = self._component_export(base, "WebResource")
        if component is None:
            return
        root = self._parse_xml(component)
        links: list[dict[str, str]] = []
        for web_link in root.iter():
            if _local_name(web_link.tag) != "WebLink":
                continue
            links.append(
                {
                    "title": _text_descendant(web_link, "Title"),
                    "description": _text_descendant(web_link, "Description"),
                    "target": _text_descendant(web_link, "Target"),
                }
            )
        item.metadata["links"] = links
        if links:
            item.metadata["url"] = links[0]["target"]
            item.description = links[0]["description"]

    def _enrich_scorm(self, item: MigrationItem, base: str) -> None:
        properties = self._archive_member(base, "properties.xml")
        package = self._archive_member(base, "content.zip")
        if properties is None:
            return
        root = self._parse_xml(properties)
        item.description = _text_descendant(root, "Description")
        item.metadata.update(
            {
                "scorm_subtype": _text_descendant(root, "SubType"),
                "package_path": package,
                "tries": _text_descendant(root, "Tries"),
                "width": _text_descendant(root, "Width"),
                "height": _text_descendant(root, "Height"),
            }
        )

    def _enrich_html_module(self, item: MigrationItem, base: str) -> None:
        component = self._component_export(base, "HTMLLearningModule")
        if component is None:
            return
        root = self._parse_xml(component)
        item.description = _text_descendant(root, "Description")
        content_dir = _text_descendant(root, "Dir")
        item.metadata.update(
            {
                "start_file": _text_descendant(root, "StartFile"),
                "content_dir": f"{base}/{content_dir}" if content_dir else "",
            }
        )

    def _enrich_test(self, item: MigrationItem, base: str) -> None:
        prefix = f"{base.rstrip('/')}/"
        qti_files = [
            name
            for name in self.names
            if name.startswith(prefix) and re.search(r"__qti_\d+\.xml$", name)
        ]
        structure_files = [
            name
            for name in self.names
            if name.startswith(prefix) and re.search(r"__tst_\d+\.xml$", name)
        ]

        if qti_files:
            root = self._parse_xml(qti_files[0])
            questions: list[dict[str, str]] = []
            for question in root.iter():
                if _local_name(question.tag) != "item":
                    continue
                question_type = ""
                for metadata_field in question.iter():
                    if _local_name(metadata_field.tag) != "qtimetadatafield":
                        continue
                    if _text_descendant(metadata_field, "fieldlabel") == "QUESTIONTYPE":
                        question_type = _text_descendant(metadata_field, "fieldentry")
                        break
                questions.append(
                    {
                        "ident": question.attrib.get("ident", ""),
                        "title": question.attrib.get("title", ""),
                        "question_type": question_type,
                    }
                )
            item.metadata.update(
                {
                    "qti_path": qti_files[0],
                    "question_count": len(questions),
                    "questions": questions,
                }
            )
        else:
            item.metadata["question_count"] = 0

        if structure_files:
            item.metadata["test_structure_path"] = structure_files[0]

    def _enrich_question_pool(self, item: MigrationItem, base: str) -> None:
        prefix = f"{base.rstrip('/')}/"
        question_exports = [
            name
            for name in self.names
            if name.startswith(prefix)
            and (
                "qti" in PurePosixPath(name).name.lower()
                or re.search(r"__qpl_\d+\.xml$", name)
            )
        ]
        item.metadata["question_data_present"] = bool(question_exports)
        item.metadata["question_export_files"] = question_exports

    def _enrich_course(self, course: CourseExport, base: str) -> None:
        page_component = self._component_export(base, "COPage")
        if page_component:
            root = self._parse_xml(page_component)
            course.metadata["page_paragraphs"] = [
                (element.text or "").strip()
                for element in root.iter()
                if _local_name(element.tag) == "Paragraph" and (element.text or "").strip()
            ]
            course.metadata["page_media_aliases"] = [
                element.attrib["OriginId"]
                for element in root.iter()
                if _local_name(element.tag) == "MediaAlias" and element.attrib.get("OriginId")
            ]

        media_component = self._component_export(base, "MediaObjects")
        if media_component:
            root = self._parse_xml(media_component)
            media_objects: list[dict[str, str]] = []
            for media_object in root.iter():
                if _local_name(media_object.tag) != "Mob":
                    continue
                object_id = _text_descendant(media_object, "Id")
                title = _text_descendant(media_object, "Title")
                media_container = _text_descendant(media_object, "MediaContainer")
                media_objects.append(
                    {
                        "obj_id": object_id,
                        "title": title,
                        "archive_path": (
                            f"{base}/{media_container}/{title}"
                            if media_container and title
                            else ""
                        ),
                    }
                )
            course.metadata["media_objects"] = media_objects
