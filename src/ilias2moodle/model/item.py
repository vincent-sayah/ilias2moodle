from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any


@dataclass(slots=True)
class MigrationItem:
    source_id: str
    type: str
    title: str
    description: str = ""
    position: int = 0
    metadata: dict[str, Any] = field(default_factory=dict)
    items: list["MigrationItem"] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)
