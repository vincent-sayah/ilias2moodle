#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
SRC = REPO_ROOT / "src"
if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))

from ilias2moodle.ilias.qti import write_test_normalization


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Normalise un export QTI de test ILIAS vers questions.json et quiz.json "
            "sans modifier ILIAS ni Moodle."
        )
    )
    parser.add_argument("--qti", required=True, type=Path)
    parser.add_argument("--structure", type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--source-ref-id", default="")
    parser.add_argument("--source-obj-id", default="")
    parser.add_argument("--title", default="")
    args = parser.parse_args()

    if not args.qti.is_file():
        parser.error(f"QTI introuvable : {args.qti}")
    if args.structure is not None and not args.structure.is_file():
        parser.error(f"Structure de test introuvable : {args.structure}")

    result = write_test_normalization(
        args.qti,
        args.structure,
        args.output,
        source_ref_id=args.source_ref_id,
        source_obj_id=args.source_obj_id,
        title=args.title,
    )
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
