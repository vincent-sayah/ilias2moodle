from __future__ import annotations

from pathlib import Path


def test_irss_empty_collection_is_success_contract() -> None:
    root = Path(__file__).resolve().parents[2]
    source = (
        root / "tools" / "ilias_irss_extract.php"
    ).read_text(encoding="utf-8")

    marker = (
        'echo "RESULTAT    : COLLECTION_VIDE\\n";'
    )
    position = source.index(marker)
    tail = source[position: position + 220]

    assert "exit(0);" in tail
    assert "exit(3);" not in tail
