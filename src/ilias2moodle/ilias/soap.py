from __future__ import annotations

from ilias2moodle.config import Settings
from ilias2moodle.model import CourseExport


class SoapIliasClient:
    """Future ILIAS 10 SOAP implementation for Phase 1."""

    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    def get_course(self, course_id: str) -> CourseExport:
        raise NotImplementedError(
            "Le connecteur SOAP réel sera implémenté après validation des services "
            "exposés par l’instance ILIAS 10 cible. Utiliser ILIAS_MODE=demo pour le moment."
        )
