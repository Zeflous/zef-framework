#!/usr/bin/env python3
"""Generate the committed per-zone mutation tables from campaign results.

Outputs, in canonical zone order (scripts/f16_zones.tsv):

  docs/mutation/baseline.tsv   frozen floor per zone (the measurement at freeze time)
  docs/mutation/zones.tsv      current measurement table

Both share the same 7-column shape the gate parses:

  zone|msi|covered_msi|total|status|evidence|reason

Zones without a measurement yet are written as UNKNOWN with a reason, so the gate
still sees a complete zone set while a campaign is in flight. Re-run this script
after the campaign finishes to replace those rows with real numbers.

SINGLE SOURCE OF TRUTH
----------------------
The numbers in a row are derived from the evidence file the row CITES, not from the
campaign aggregate (build/zone-campaign-results.json). Deriving them from the
aggregate while citing a different file is what allowed a row to report one run's
score next to another run's evidence — e.g. a zone whose cited summary said
`Killed 103 / Escaped 10` (MSI 88.03) sitting behind a row that said 87.18 because
the aggregate came from a later measurement.

The evidence file chosen for a zone is the STRICTER of the two candidates:

  * docs/mutation/evidence/infection-summary-<zone>.json  (already committed)
  * build/infection-summary-<zone>.json                   (this campaign)

A weaker measurement never overwrites a stronger committed one, so re-running this
script cannot lower a recorded floor — mutation scores wobble by a mutant between
runs, and silently freezing the lower number would weaken the ratchet (AR-2:
never weaken a gate so a cycle passes). When the committed evidence is the weaker
of the two, the row records that in the reason column instead of hiding it.

Usage:
  python3 build/gen_zone_tables.py [--report PATH]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ZONES_TSV = REPO / "scripts" / "f16_zones.tsv"
RESULTS = REPO / "build" / "zone-campaign-results.json"
OUT_DIR = REPO / "docs" / "mutation"
EVIDENCE_DIR = OUT_DIR / "evidence"
FLOOR = 95.0
CAMPAIGN_LABEL = "campaign 2026-09-23"


def repo_path(raw: str, what: str) -> Path:
    """Resolve a CLI-supplied path and refuse anything outside the repository.

    Every path this tool writes stays inside the checkout by construction, and
    a `--report` target must obey the same rule: a mistyped or injected
    argument must never point `write_text()` at an arbitrary location
    (code-scanning rule python/PT). Symlinks are resolved too, so a link that
    lives in the repo but points outward is rejected as well.
    """
    resolved = Path(raw).resolve()
    if resolved != REPO and REPO not in resolved.parents:
        print(f"ERROR: {what} must stay inside the repository: {raw}", file=sys.stderr)
        sys.exit(2)
    return resolved


def canonical_zones() -> list[str]:
    zones = []
    for raw in ZONES_TSV.read_text().splitlines():
        line = raw.strip()
        if line and not line.startswith("#"):
            zones.append(line.split("|", 1)[0])
    return zones


def parse_summary(path: Path) -> dict | None:
    """Parse Infection's text summary into counts. Mirrors zone_campaign.parse_summary."""
    if not path.exists() or path.stat().st_size == 0:
        return None
    counts: dict[str, int] = {}
    for line in path.read_text().splitlines():
        if ":" not in line:
            continue
        key, _, value = line.partition(":")
        value = value.strip()
        if value.isdigit():
            counts[key.strip().lower()] = int(value)
    total = counts.get("total", 0)
    if not total:
        return None
    not_covered = counts.get("not covered", 0)
    covered = total - not_covered
    killed = counts.get("killed by test framework", 0) + counts.get("killed by static analysis", 0)
    return {
        "total": total,
        "not_covered": not_covered,
        "covered": covered,
        "killed": killed,
        "escaped": counts.get("escaped", 0),
        "timed_out": counts.get("timed out", 0),
        "errored": counts.get("errored", 0),
        "msi": round(killed / total * 100.0, 2),
        "covered_msi": round(killed / covered * 100.0, 2) if covered else 0.0,
        "source": path.name,
    }


def resolve(zone: str) -> tuple[dict | None, Path, str]:
    """Return (measurement, evidence path, note).

    The strictest available measurement wins, so a weaker capture can never lower a
    floor that a previous campaign recorded.
    """
    evidence = EVIDENCE_DIR / f"infection-summary-{zone}.json"
    build = REPO / "build" / f"infection-summary-{zone}.json"
    committed = parse_summary(evidence)
    campaign = parse_summary(build)

    if committed and campaign:
        if campaign["msi"] > committed["msi"] + 1e-9:
            note = (
                f"campaign re-measurement is stricter ({campaign['msi']:.2f} vs committed "
                f"{committed['msi']:.2f}); committed evidence retained until refreshed"
            )
            return committed, evidence, note
        if committed["msi"] > campaign["msi"] + 1e-9:
            note = (
                f"re-measurement measured {campaign['msi']:.2f}, weaker than the committed "
                f"{committed['msi']:.2f}; the stricter committed evidence is kept"
            )
            return committed, evidence, note
        return committed, evidence, ""
    if committed:
        return committed, evidence, ""
    if campaign:
        return campaign, evidence, ""
    return None, evidence, ""


def row_for(zone: str, measurement: dict | None, note: str) -> list[str]:
    if not measurement:
        return [
            zone,
            "-",
            "-",
            "-",
            "UNKNOWN",
            "docs/mutation/evidence/ (no summary yet)",
            "no per-zone measurement yet in this campaign; see docs/mutation/README.md",
        ]
    msi = float(measurement["msi"])
    status = "OK" if msi + 1e-9 >= FLOOR else "DEBT"
    reason = "" if status == "OK" else f"measured MSI {msi:.2f} below the {FLOOR:.0f} target"
    if note:
        reason = f"{reason}; {note}" if reason else note
    # The evidence must live IN the repository: build/ is git-ignored, so a path
    # into it would point at a file no reviewer can open.
    return [
        zone,
        f"{msi:.2f}",
        f"{float(measurement['covered_msi']):.2f}",
        str(measurement["total"]),
        status,
        f"docs/mutation/evidence/infection-summary-{zone}.json",
        reason,
    ]


def write_table(path: Path, rows: list[list[str]], header: str) -> None:
    lines = [header]
    lines += ["|".join(r) for r in rows]
    path.write_text("\n".join(lines) + "\n")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--report", default=None, help="also write a markdown table here")
    args = ap.parse_args()
    report_path = repo_path(args.report, "--report target") if args.report else None

    zones = canonical_zones()
    EVIDENCE_DIR.mkdir(parents=True, exist_ok=True)

    rows: list[list[str]] = []
    published = 0
    notes = 0
    for zone in zones:
        measurement, evidence, note = resolve(zone)
        # Publish the strictest capture as the committed evidence for this zone:
        # copy the campaign summary only when it is not weaker than what is there.
        build = REPO / "build" / f"infection-summary-{zone}.json"
        if measurement and build.exists() and build.stat().st_size > 0:
            campaign = parse_summary(build)
            committed = parse_summary(evidence)
            if campaign and (not committed or campaign["msi"] >= committed["msi"] - 1e-9):
                if not evidence.exists() or evidence.read_text() != build.read_text():
                    evidence.write_text(build.read_text())
                    published += 1
        if note:
            notes += 1
        rows.append(row_for(zone, measurement, note))

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    hdr = "# zone|msi|covered_msi|total|status|evidence|reason  (generated by build/gen_zone_tables.py)"
    write_table(OUT_DIR / "zones.tsv", rows, hdr)
    write_table(OUT_DIR / "baseline.tsv", rows, hdr)

    measured = sum(1 for r in rows if r[4] != "UNKNOWN")
    ok = sum(1 for r in rows if r[4] == "OK")
    print(f"zones.tsv + baseline.tsv written: {len(rows)} zones, {measured} measured, {ok} at/above {FLOOR:.0f}%")
    print(f"evidence summaries published into docs/mutation/evidence/: {published}")
    if notes:
        print(f"rows carrying a measurement-disagreement note: {notes}")
    print("row numbers are derived from the cited evidence file (single source of truth)")

    if report_path is not None:
        md = [
            "| Zona | MSI | Covered MSI | Mutan | Status | Bukti |",
            "|---|---:|---:|---:|---|---|",
        ]
        for r in rows:
            md.append(f"| `{r[0]}` | {r[1]} | {r[2]} | {r[3]} | {r[4]} | `{r[5]}` |")
        report_path.write_text("\n".join(md) + "\n")

    # Propagate a contract violation instead of shipping a silently wrong table.
    for r in rows:
        if r[4] != "UNKNOWN" and r[5] != f"docs/mutation/evidence/infection-summary-{r[0]}.json":
            print(f"CONSISTENCY_VIOLATION: {r[0]} cites {r[5]}", file=sys.stderr)
            return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
