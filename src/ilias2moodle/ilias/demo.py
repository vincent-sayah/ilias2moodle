from __future__ import annotations

from ilias2moodle.model import CourseExport, MigrationItem


class DemoIliasClient:
    """Deterministic source used to validate Phase 1 before real ILIAS access."""

    def get_course(self, course_id: str) -> CourseExport:
        return CourseExport(
            source_id=str(course_id),
            title=f"Cours ILIAS de démonstration {course_id}",
            description="Jeu de données temporaire pour valider la chaîne Phase 1.",
            metadata={"source_mode": "demo"},
            items=[
                MigrationItem(
                    source_id=f"{course_id}-10",
                    type="folder",
                    title="Séquence 1",
                    position=1,
                    items=[
                        MigrationItem(
                            source_id=f"{course_id}-11",
                            type="file",
                            title="Guide utilisateur.pdf",
                            position=1,
                            metadata={"filename": "guide-utilisateur.pdf"},
                        ),
                        MigrationItem(
                            source_id=f"{course_id}-12",
                            type="url",
                            title="Ressource externe",
                            position=2,
                            metadata={"url": "https://example.org"},
                        ),
                    ],
                ),
                MigrationItem(
                    source_id=f"{course_id}-20",
                    type="scorm",
                    title="Module SCORM de démonstration",
                    position=2,
                    metadata={"package": "demo-scorm.zip"},
                ),
                MigrationItem(
                    source_id=f"{course_id}-30",
                    type="test",
                    title="Test de démonstration",
                    position=3,
                ),
            ],
        )
