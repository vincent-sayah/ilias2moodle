#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from pathlib import Path

from ilias2moodle.ilias.wiki import parse_wikis


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Inspecter les objets Wiki d'un export natif ILIAS."
    )
    parser.add_argument("archive", type=Path)
    args = parser.parse_args()

    wikis = parse_wikis(args.archive)
    print(json.dumps(wikis, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
