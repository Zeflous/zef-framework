#!/usr/bin/env python3
"""Merge the three Snyk scanner SARIF files into one evidence document.

Why this exists
---------------
Every scanner step in .github/workflows/snyk-security.yml writes its own
SARIF file. Uploaded separately, each file becomes its own GitHub Code
Scanning analysis and its own check run, so one Snyk pipeline used to
render three "Code scanning results / ..." check runs (SnykCode,
Snyk Open Source, Snyk IaC) alongside the workflow job itself.

This script concatenates the per-scanner documents into ONE SARIF whose
runs all carry the single tool name ``"Snyk"``. A single upload of that
merged document then renders ONE check run — "Code scanning results /
Snyk" — which is exactly the pattern the CodeQL default-setup analyses
already use for their multi-language runs (one tool name, one upload,
one check). The rename is evidence branding only: rule ids, results and
locations are passed through untouched, so alert identity and the
delta-versus-main comparison behave as before.

It touches ONLY the SARIF *evidence*; the workflow's fail-closed verdict
is computed from the scanner exit codes and is unaffected.

Inputs
------
The three SARIF files are fixed by the workflow that produces them, so they
are declared here as module constants rather than read from ``sys.argv``. A
path that arrives as a command-line argument is attacker-influenced input,
and feeding it to ``open()`` is a path-traversal sink (code-scanning rule
python/PT) that no amount of downstream validation reliably clears for a
static analyser. With the names as constants there is no taint source at all:
the only paths this script can ever touch are the four below, resolved under
the repository root.

A scanner that produced no SARIF is handled by the verdict step, not here:
missing inputs are skipped, and when NO input exists nothing is written (the
upload step's ``hashFiles`` guard then skips cleanly). Exit status is 0 in
both cases. A file that exists but is not valid JSON is a real error worth
failing on: exit 1.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Fixed by .github/workflows/snyk-security.yml — one file per scanner.
INPUTS = ("snyk-code.sarif", "snyk-sca.sarif", "snyk-iac.sarif")
OUTPUT = "snyk.sarif"

# The three Snyk products each report under their own driver name
# ("SnykCode", "Snyk Open Source", "Snyk IaC"). Rewriting every run's
# driver name to this single value is what collapses the three evidence
# check runs into one.
TOOL_NAME = "Snyk"


def load_runs(name: str) -> tuple[list[dict], dict] | None:
    """Read one input SARIF.

    Returns ``(runs, header)`` where ``header`` is every top-level key
    except ``runs`` (``$schema``, ``version``). Returns ``None`` when the
    file is absent (skip) and raises ``json.JSONDecodeError`` when it is
    not valid JSON (fail).
    """
    source = REPO_ROOT / name
    if not source.is_file():
        print(f"{name}: absent, skipped")
        return None
    document = json.loads(source.read_text(encoding="utf-8"))
    runs = document.get("runs", [])
    header = {key: value for key, value in document.items() if key != "runs"}
    return runs, header


def relabel(run: dict) -> dict:
    """Set one run's tool driver name to the shared evidence name."""
    driver = run.get("tool", {}).get("driver")
    if isinstance(driver, dict):
        driver["name"] = TOOL_NAME
    return run


def main() -> int:
    merged_runs: list[dict] = []
    merged_header: dict = {}
    seen_input = False
    try:
        for name in INPUTS:
            loaded = load_runs(name)
            if loaded is None:
                continue
            runs, header = loaded
            seen_input = True
            if not merged_header and header:
                merged_header = header
            merged_runs.extend(relabel(run) for run in runs)
            print(f"{name}: merged {len(runs)} run(s)")
    except json.JSONDecodeError as error:
        print(f"SARIF input is not valid JSON: {error}", file=sys.stderr)
        return 1

    if not seen_input:
        print("no SARIF input found — nothing to merge, no output written")
        return 0

    output = REPO_ROOT / OUTPUT
    if REPO_ROOT not in output.resolve().parents:
        print(f"ERROR: refusing to write outside the repository: {OUTPUT}", file=sys.stderr)
        return 2

    document = dict(merged_header)
    document["runs"] = merged_runs
    with output.open("w", encoding="utf-8") as handle:
        json.dump(document, handle)
    print(f"{OUTPUT}: wrote {len(merged_runs)} run(s) under tool name {TOOL_NAME}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
