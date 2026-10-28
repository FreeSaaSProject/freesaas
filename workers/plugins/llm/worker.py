#!/usr/bin/env python3
"""Local LLM plugin stub — not implemented yet. See plugins/llm/README.md."""
from __future__ import annotations

import sys


def main() -> int:
    print(
        "llm plugin is a stub. Implement worker + models, then add "
        "'llm' to workers/host.local.json enabled_plugins. See docs/agents/workers.md §8.",
        file=sys.stderr,
    )
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
