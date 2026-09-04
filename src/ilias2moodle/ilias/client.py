from __future__ import annotations

from typing import Protocol

from ilias2moodle.model import CourseExport


class IliasClient(Protocol):
    """Contract implemented by all ILIAS data sources."""

    def get_course(self, course_id: str) -> CourseExport:
        """Return a complete neutral representation of one ILIAS course."""
        ...
