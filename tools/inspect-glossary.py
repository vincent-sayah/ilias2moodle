#!/usr/bin/env python3
# ruff: noqa: E402
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "src"
if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))

from ilias2moodle.ilias.glossary import parse_glossaries


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Inspect ILIAS Glossary objects contained in a native course export ZIP."
    )
    parser.add_argument("zip", help="Path to the native ILIAS course ZIP")
    parser.add_argument(
        "--output",
        help="Optional JSON output file. Without it, JSON is printed to stdout.",
    )
    args = parser.parse_args()

    glossaries = parse_glossaries(args.zip)
    payload = {
        "glossary_count": len(glossaries),
        "glossaries": glossaries,
    }
    text = json.dumps(payload, ensure_ascii=False, indent=2)

    if args.output:
        destination = Path(args.output)
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(text + "\n", encoding="utf-8")
        print(destination)
    else:
        print(text)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
