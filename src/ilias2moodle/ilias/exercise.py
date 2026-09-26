from __future__ import annotations

import re
import zipfile
from pathlib import PurePosixPath
from typing import Any
from xml.etree import ElementTree as ET

from ilias2moodle.ilias.content_page import _local_name, _text_descendant
from ilias2moodle.ilias.export_sets import find_export_sets


RESOURCE_COLLECTION_UUID_RE = re.compile(
    r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
)


ASSIGNMENT_TYPES: dict[int, dict[str, Any]] = {
    1: {
        "key": "file_upload",
        "uses_files": True,
        "uses_online_text": False,
        "uses_teams": False,
        "migration_support": "supported",
    },
    2: {
        "key": "blog",
        "uses_files": False,
        "uses_online_text": False,
        "uses_teams": False,
        "migration_support": "manual_strategy_required",
    },
    3: {
        "key": "portfolio",
        "uses_files": False,
        "uses_online_text": False,
        "uses_teams": False,
        "migration_support": "manual_strategy_required",
    },
    4: {
        "key": "team_file_upload",
        "uses_files": True,
        "uses_online_text": False,
        "uses_teams": True,
        "migration_support": "phase7_group_dependency",
    },
    5: {
        "key": "online_text",
        "uses_files": False,
        "uses_online_text": True,
        "uses_teams": False,
        "migration_support": "supported",
    },
    6: {
        "key": "team_wiki",
        "uses_files": False,
        "uses_online_text": False,
        "uses_teams": True,
        "migration_support": "manual_strategy_required",
    },
}


def _records(root: ET.Element, entity: str) -> list[ET.Element]:
    wanted = entity.lower()
    records: list[ET.Element] = []
    for candidate in root.iter():
        if _local_name(candidate.tag) != "Rec":
            continue
        if candidate.attrib.get("Entity", "").lower() == wanted:
            records.append(candidate)
    return records


def _int(record: ET.Element, field: str, default: int = 0) -> int:
    raw = _text_descendant(record, field, "")
    try:
        return int(raw) if raw != "" else default
    except ValueError:
        return default


def _bool(record: ET.Element, field: str) -> bool:
    return _int(record, field, 0) != 0


class ExerciseParser:
    """Parse one native ILIAS Exercise export set.

    ILIAS Exercise objects contain one or more exc_assignment records. The
    neutral model keeps the Exercise as a logical container and each assignment
    as an independently mappable Moodle Assignment candidate.
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

    def _collection_files(
        self,
        collection_path: str,
        order_by_filename: dict[str, int],
    ) -> list[dict[str, Any]]:
        relative = collection_path.strip("/")
        if not relative:
            return []

        prefix = f"{self.base}/{relative}/"
        files: list[dict[str, Any]] = []
        for canonical, info in self.infos.items():
            if not canonical.startswith(prefix):
                continue
            rel = canonical[len(prefix) :]
            if not rel:
                continue
            filename = PurePosixPath(rel).name
            files.append(
                {
                    "filename": filename,
                    "relative_path": rel,
                    "archive_path": canonical,
                    "size": int(info.file_size),
                    "order": order_by_filename.get(filename, 0),
                }
            )
        return sorted(
            files,
            key=lambda f: (
                int(f.get("order", 0)) if int(f.get("order", 0)) > 0 else 10**9,
                str(f.get("relative_path", "")),
            ),
        )

    def parse(self) -> dict[str, Any]:
        component = self._component_export("Exercise")
        if component is None:
            raise ValueError("Composant ILIAS/Exercise introuvable")

        root = self._parse_xml(component)
        exercises = _records(root, "exc")
        if not exercises:
            raise ValueError("Enregistrement exc introuvable")
        exercise = exercises[0]

        object_id = _text_descendant(exercise, "Id")

        file_orders: dict[str, dict[str, int]] = {}
        for record in _records(root, "exc_ass_file_order"):
            assignment_id = _text_descendant(record, "AssignmentId")
            filename = _text_descendant(record, "Filename")
            if assignment_id and filename:
                file_orders.setdefault(assignment_id, {})[filename] = _int(
                    record, "OrderNr", 0
                )

        reminders: dict[str, list[dict[str, Any]]] = {}
        for record in _records(root, "exc_ass_reminders"):
            assignment_id = _text_descendant(record, "AssignmentId")
            reminders.setdefault(assignment_id, []).append(
                {
                    "type": _text_descendant(record, "Type"),
                    "status": _bool(record, "Status"),
                    "start": _int(record, "Start", 0),
                    "end": _text_descendant(record, "End"),
                    "frequency": _int(record, "Frequency", 0),
                    "last_send": _text_descendant(record, "LastSend"),
                    "template_id": _int(record, "TemplateId", 0),
                }
            )

        criteria_categories = [
            {
                "source_id": _text_descendant(record, "Id"),
                "parent": _text_descendant(record, "Parent"),
                "title": _text_descendant(record, "Title"),
                "position": _int(record, "Pos", 0),
            }
            for record in _records(root, "exc_crit_cat")
        ]
        criteria = [
            {
                "source_id": _text_descendant(record, "Id"),
                "parent": _text_descendant(record, "Parent"),
                "type": _text_descendant(record, "Type"),
                "title": _text_descendant(record, "Title"),
                "description": _text_descendant(record, "Descr"),
                "position": _int(record, "Pos", 0),
                "required": _bool(record, "Required"),
                "definition": _text_descendant(record, "Def"),
                "definition_json": _text_descendant(record, "DefJson"),
            }
            for record in _records(root, "exc_crit")
        ]

        assignments: list[dict[str, Any]] = []
        blocking_features: list[dict[str, str]] = []

        for record in _records(root, "exc_assignment"):
            assignment_id = _text_descendant(record, "Id")
            type_id = _int(record, "Type", 1)
            type_info = dict(
                ASSIGNMENT_TYPES.get(
                    type_id,
                    {
                        "key": f"unknown_{type_id}",
                        "uses_files": False,
                        "uses_online_text": False,
                        "uses_teams": False,
                        "migration_support": "unsupported",
                    },
                )
            )

            instruction_collection = _text_descendant(record, "InstructionCollection")
            instruction_collection_is_uuid = bool(
                RESOURCE_COLLECTION_UUID_RE.fullmatch(instruction_collection)
            )
            instruction_files = (
                []
                if instruction_collection_is_uuid
                else self._collection_files(
                    instruction_collection,
                    file_orders.get(assignment_id, {}),
                )
            )
            max_files = _int(record, "MaxFile", 0)
            assignment = {
                "source_id": assignment_id,
                "exercise_id": _text_descendant(record, "ExerciseId"),
                "title": _text_descendant(record, "Title"),
                "instruction": _text_descendant(record, "Instruction"),
                "type_id": type_id,
                "type": type_info,
                "start_time_utc": _text_descendant(record, "StartTime"),
                "deadline_utc": _text_descendant(record, "Deadline"),
                "extended_deadline_utc": _text_descendant(record, "Deadline2"),
                "deadline_mode": _int(record, "DeadlineMode", 0),
                "relative_deadline": _int(record, "RelativeDeadline", 0),
                "relative_deadline_last_submission": _int(
                    record, "RelDeadlineLastSubm", 0
                ),
                "mandatory": _bool(record, "Mandatory"),
                "order": _int(record, "OrderNr", 0),
                "max_files": max_files,
                "max_files_unlimited": bool(type_info.get("uses_files")) and max_files == 0,
                "team_tutor": _bool(record, "TeamTutor"),
                "instruction_collection": instruction_collection,
                "instruction_collection_kind": (
                    "resource_collection_uuid"
                    if instruction_collection_is_uuid
                    else ("archive_path" if instruction_collection else "none")
                ),
                "instruction_files": instruction_files,
                "instruction_files_embedded": not instruction_collection_is_uuid,
                "peer_review": {
                    "enabled": _bool(record, "Peer"),
                    "minimum_reviews": _int(record, "PeerMin", 0),
                    "deadline_utc": _text_descendant(record, "PeerDeadline"),
                    "file_upload": _bool(record, "PeerFile"),
                    "personalized": _bool(record, "PeerPersonal"),
                    "minimum_characters": _int(record, "PeerChar", 0),
                    "unlock_mode": _int(record, "PeerUnlock", 0),
                    "validation_mode": _int(record, "PeerValid", 0),
                    "text": _bool(record, "PeerText"),
                    "rating": _bool(record, "PeerRating"),
                    "criteria_catalogue_id": _int(record, "PeerCritCat", 0),
                },
                "global_feedback": {
                    "file": _text_descendant(record, "FeedbackFile"),
                    "cron": _bool(record, "FeedbackCron"),
                    "date_mode": _int(record, "FeedbackDate", 0),
                    "custom_date": _int(record, "FbDateCustom", 0),
                },
                "reminders": reminders.get(assignment_id, []),
            }

            reasons: list[str] = []
            phase7_dependencies: list[str] = []
            support = str(type_info.get("migration_support", "unsupported"))
            if support not in {"supported", "phase7_group_dependency"}:
                reasons.append(f"assignment_type:{type_info.get('key', type_id)}")
            if support == "phase7_group_dependency":
                phase7_dependencies.append("team_membership_phase7_dependency")
            if assignment["peer_review"]["enabled"]:
                reasons.append("peer_review")
            if assignment["deadline_mode"] != 0:
                reasons.append("non_absolute_deadline")
            if assignment["reminders"]:
                reasons.append("assignment_reminders")
            if instruction_collection_is_uuid:
                reasons.append("instruction_collection_not_embedded")

            assignment["automatic_ready"] = not reasons and support == "supported"
            assignment["migration_constraints"] = reasons + phase7_dependencies
            assignment["phase7_dependencies"] = phase7_dependencies
            for reason in reasons:
                blocking_features.append(
                    {
                        "assignment_id": assignment_id,
                        "feature": reason,
                    }
                )

            assignments.append(assignment)

        assignments.sort(
            key=lambda a: (
                int(a.get("order", 0)) if int(a.get("order", 0)) > 0 else 10**9,
                str(a.get("source_id", "")),
            )
        )

        return {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "object_id": object_id,
                "export_base": self.base,
            },
            "title": _text_descendant(exercise, "Title"),
            "description": _text_descendant(exercise, "Description"),
            "settings": {
                "pass_mode": _text_descendant(exercise, "PassMode"),
                "pass_number": _int(exercise, "PassNr", 0),
                "mandatory_random_count": _int(exercise, "NrMandatoryRandom", 0),
                "show_submissions": _bool(exercise, "ShowSubmissions"),
                "completion_by_submission": _bool(exercise, "ComplBySubmission"),
                "tutor_feedback": _int(exercise, "Tfeedback", 0),
            },
            "assignments": assignments,
            "criteria_categories": criteria_categories,
            "criteria": criteria,
            "blocking_features": blocking_features,
            "export_issues": [
                feature
                for feature in blocking_features
                if feature.get("feature") == "instruction_collection_not_embedded"
            ],
            "components": self._components(),
            "target_strategy": {
                "one_moodle_assignment_per_unit": True,
                "multi_unit_container": "subsection_candidate",
                "confirmed_by_real_poc": False,
            },
            "user_data_policy": {
                "source_export_contains_submissions": False,
                "submissions_migrated": False,
                "grades_migrated": False,
                "tutor_feedback_migrated": False,
                "peer_feedback_migrated": False,
                "team_memberships_migrated": False,
                "target_phase": "7",
            },
        }


def find_exercise_export_sets(archive: zipfile.ZipFile) -> list[dict[str, str]]:
    """Return exc export sets from a native ILIAS course ZIP."""

    return find_export_sets(archive, "exc")

def parse_exercises(archive_path: str | PurePosixPath) -> list[dict[str, Any]]:
    with zipfile.ZipFile(str(archive_path)) as archive:
        exercises: list[dict[str, Any]] = []
        for export_set in find_exercise_export_sets(archive):
            parsed = ExerciseParser(archive, export_set["path"]).parse()
            if not parsed["source"]["object_id"]:
                parsed["source"]["object_id"] = export_set["object_id"]
            exercises.append(parsed)
        return exercises
