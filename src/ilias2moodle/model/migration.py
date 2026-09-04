from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import UTC, datetime
from typing import Any

from ilias2moodle.model.course import CourseExport


@dataclass(slots=True)
class MigrationDocument:
    course: CourseExport
    schema_version: str = "1.0"
    source: dict[str, str] = field(
        default_factory=lambda: {"lms": "ILIAS", "version": "10"}
    )
    generated_at: str = field(default_factory=lambda: datetime.now(UTC).isoformat())

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)
