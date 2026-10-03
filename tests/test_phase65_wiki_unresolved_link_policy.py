from pathlib import Path


def test_unexported_wiki_page_links_are_preserved_as_text():
    renderer = Path(
        "moodle/local_iliasmigration/classes/phase65_wiki_renderer.php"
    ).read_text(encoding="utf-8")
    validator = Path(
        "moodle/local_iliasmigration/classes/phase65_wiki_package_validator.php"
    ).read_text(encoding="utf-8")

    assert "WIKI_PAGE_UNRESOLVED" in renderer
    assert "PAGE_NOT_EXPORTED" in renderer
    assert "'url' => ''" in renderer
    assert "unresolved_wiki_page_link_count" in validator
    assert "PRESERVE_AS_TEXT_PAGE_NOT_EXPORTED" in validator
    assert "WIKI_INTERNAL_PAGE_LINK_MISSING" not in validator
