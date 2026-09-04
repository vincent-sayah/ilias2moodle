from ilias2moodle.model import CourseExport, MigrationDocument, MigrationItem


def test_migration_document_serializes_nested_items() -> None:
    course = CourseExport(
        source_id="100",
        title="Cours",
        items=[
            MigrationItem(
                source_id="101",
                type="folder",
                title="Dossier",
                items=[MigrationItem(source_id="102", type="file", title="guide.pdf")],
            )
        ],
    )

    payload = MigrationDocument(course=course).to_dict()

    assert payload["schema_version"] == "1.0"
    assert payload["course"]["source_id"] == "100"
    assert payload["course"]["items"][0]["items"][0]["type"] == "file"
