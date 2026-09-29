#!/usr/bin/env python3
"""Normalize non-numeric ``security-severity`` values in Snyk SARIF files.

Why this exists
---------------
Snyk's Open Source (SCA) SARIF can emit a rule whose ``security-severity``
property is the literal string ``"undefined"``: license issues carry no CVSS
score, so Snyk has no number to write. GitHub Code Scanning rejects the entire
file with::

    could not convert rules: invalid security severity value, is not a number: undefined

which means the SCA findings never reach the Code Scanning tab even though the
scan itself succeeded.

This script rewrites any non-numeric ``security-severity`` to ``"0.0"`` so the
file is accepted. It touches ONLY the SARIF *evidence*; the workflow's
fail-closed verdict is computed from the scanner exit codes and is unaffected.

Inputs
------
The three SARIF files are fixed by the workflow that produces them, so they are
declared here as module constants rather than read from ``sys.argv``. A path
that arrives as a command-line argument is attacker-influenced input, and
feeding it to ``open()`` is a path-traversal sink (code-scanning rule
python/PT) that no amount of downstream validation reliably clears for a
static analyser. With the names as constants there is no taint source at all:
the only paths this script can ever touch are the three below, resolved under
the repository root.

Missing files are skipped (a scanner that produced no SARIF is handled by the
verdict step, not here). Exit status is always 0 unless a file exists but is
not valid JSON, which is a real error worth failing on.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Fixed by .github/workflows/snyk-security.yml — one file per scanner.
SARIF_FILES = ("snyk-code.sarif", "snyk-sca.sarif", "snyk-iac.sarif")


def sarif_path(name: str) -> Path:
    """Resolve one of the fixed SARIF names under the repository root.

    ``name`` is always a module constant, never user input, so this is a
    containment assertion rather than a sanitizer: it guarantees the script
    can only ever read and rewrite files inside the checkout.
    """
    path = (REPO_ROOT / name).resolve()
    if path != REPO_ROOT and REPO_ROOT not in path.parents:
        print(f"ERROR: refusing to touch a path outside the repository: {name}", file=sys.stderr)
        sys.exit(2)
    return path


def normalize(path: Path) -> int:
    """Rewrite non-numeric security-severity values in ``path``.

    Returns the number of values rewritten. Raises ``json.JSONDecodeError`` if
    the file is not valid JSON.
    """
    with path.open(encoding="utf-8") as handle:
        document = json.load(handle)

    fixed = 0
    for run in document.get("runs", []):
        driver = run.get("tool", {}).get("driver", {})
        for rule in driver.get("rules", []):
            properties = rule.get("properties")
            if not isinstance(properties, dict):
                continue
            severity = properties.get("security-severity")
            if severity is None:
                continue
            try:
                float(severity)
            except (TypeError, ValueError):
                properties["security-severity"] = "0.0"
                fixed += 1

    if fixed:
        with path.open("w", encoding="utf-8") as handle:
            json.dump(document, handle)

    return fixed


def main() -> int:
    for name in SARIF_FILES:
        path = sarif_path(name)
        if not path.exists():
            print(f"{name}: absent, skipped")
            continue
        try:
            fixed = normalize(path)
        except json.JSONDecodeError as error:
            print(f"{name}: not valid JSON: {error}", file=sys.stderr)
            return 1
        print(f"{name}: normalized {fixed} non-numeric security-severity value(s)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
