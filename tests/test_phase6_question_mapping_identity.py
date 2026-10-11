from pathlib import Path


def test_phase6_question_mapping_uses_source_ident_not_external_id():
    builder = Path(
        "moodle/local_iliasmigration/classes/phase6_moodle_xml_builder.php"
    ).read_text(encoding="utf-8")

    assert "$mappingref = $testref . ':' . $ident;" in builder
    assert "'mapping_ref' => $mappingref" in builder
    assert "$mappingref = $testref . ':' . $external" not in builder


def test_linked_pool_quiz_uses_exact_pool_mapping_ref():
    executor = Path(
        "moodle/local_iliasmigration/classes/phase6_executor.php"
    ).read_text(encoding="utf-8")

    assert (
        "$descriptor['shared_pool_mapping_ref'] ="
        in executor
    )
    assert (
        "(string) $pooldescriptor['mapping_ref']"
        in executor
    )
    assert (
        "$poolmappingref"
        in executor
    )
    assert (
        "$this->find_mapping(\n"
        "                        $sourcecourseid,\n"
        "                        $poolmappingref,"
        in executor
    )
    assert "$sharedpoolref . ':' . $stable" not in executor
