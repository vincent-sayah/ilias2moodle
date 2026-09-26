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


def test_report_marks_phase65_types_supported_but_group_deferred() -> None:
    course = CourseExport(
        source_id="128",
        title="Cours POC",
        items=[
            MigrationItem(source_id="275", type="forum", title="Forum"),
            MigrationItem(source_id="276", type="mediacast", title="Mediacast"),
            MigrationItem(source_id="277", type="blog", title="Blog"),
            MigrationItem(source_id="278", type="media_pool", title="Media Pool"),
            MigrationItem(source_id="254", type="grp", title="Groupe"),
        ],
    )

    report = build_report(MigrationDocument(course=course))

    assert report["unsupported_count"] == 1
    assert report["unsupported"] == [
        {
            "source_id": "254",
            "type": "grp",
            "title": "Groupe",
        }
    ]
