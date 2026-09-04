from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any

from ilias2moodle.model.item import MigrationItem


@dataclass(slots=True)
class CourseExport:
    source_id: str
    title: str
    description: str = ""
    metadata: dict[str, Any] = field(default_factory=dict)
    items: list[MigrationItem] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)
