from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem
from ilias2moodle.report import build_report


def test_report_counts_nested_items() -> None:
    course = CourseExport(
        source_id="100",
        title="Cours",
        items=[
            MigrationItem(
                source_id="101",
                type="folder",
                title="Dossier",
                items=[
                    MigrationItem(source_id="102", type="file", title="a.pdf"),
                    MigrationItem(source_id="103", type="file", title="b.pdf"),
                ],
            ),
            MigrationItem(source_id="104", type="custom", title="Non supporté"),
        ],
    )

    report = build_report(MigrationDocument(course=course))

    assert report["total_items"] == 4
    assert report["counts_by_type"]["file"] == 2
    assert report["unsupported_count"] == 1
