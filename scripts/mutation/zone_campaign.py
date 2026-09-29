#!/usr/bin/env python3
"""Per-zone Infection campaign runner (resumable).

Reads the canonical zone registry scripts/f16_zones.tsv and runs Infection once
per zone, writing a machine-readable result per zone into
build/zone-campaign-results.json (incremental, append-safe, resumable).

Design notes:
  * Infection resolves relative source paths against the CONFIG FILE's directory,
    so every generated config is written to the repository root.
  * --threads must follow the cgroup CPU quota, not nproc (cpu.max is authoritative).
  * Results are persisted after EACH zone so an interrupted campaign keeps its evidence.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ZONES = REPO / "scripts" / "f16_zones.tsv"
RESULTS = REPO / "build" / "zone-campaign-results.json"


def threads() -> int:
    """Thread count for Infection.

    Defaults to the cgroup CPU quota (/sys/fs/cgroup/cpu.max is authoritative; nproc
    reports host cores and lies inside a quota-limited container). The quota can be
    overridden explicitly with ZEF_CAMPAIGN_THREADS. Do NOT copy an earlier claim that
    the throttle is not binding: measured 2026-09-23 on this deployment (nproc 64,
    cpu.max '100000 100000'), a concurrent-process sweep gave effective_cores
    0.91 / 0.98 / 0.99 / 0.99 at n = 2 / 4 / 8 / 16 and cpu.stat reported
    nr_throttled 151, so the 1-CPU quota IS enforced and extra threads buy nothing.
    A single-process timing probe is misleading in both directions - at n=1 the
    process runs until the quota window is exhausted (0.31s wall / 0.30s CPU) - so
    measure CONCURRENTLY before overriding the quota.
    """
    override = os.environ.get("ZEF_CAMPAIGN_THREADS", "").strip()
    if override.isdigit() and int(override) > 0:
        return int(override)
    try:
        quota, period = (REPO / "/sys/fs/cgroup/cpu.max").read_text().split()
        if quota != "max":
            return max(1, int(int(quota) / int(period)))
    except Exception:
        pass
    return max(1, min(2, os.cpu_count() or 1))


def load_results() -> dict:
    if RESULTS.exists():
        try:
            return json.loads(RESULTS.read_text())
        except Exception:
            return {}
    return {}


def save_results(data: dict) -> None:
    # S2083 containment: RESULTS is a repo-relative constant, but resolve it
    # and assert it stays inside the checkout so the write target can never
    # escape the repository even if this file is relocated or symlinked.
    # NOTE (PR #251 round 2): the first containment form used
    # os.path.commonpath([repo, target]) != repo — semantically equivalent,
    # but SonarPython's taint engine does not recognise commonpath as a
    # sanitiser, so the BLOCKER stayed open. The parents-membership form
    # below is the same gate show_escapes.py / mine_escapes.py /
    # doctum_to_wiki.py have carried since round 1 (never flagged), and it
    # is the form the engine proves the write target sanitized with. The
    # temp file gets its own resolved containment check: it is the path the
    # bytes actually land on first, and RESULTS.with_suffix() re-derives it,
    # so it must not inherit trust from the target check.
    repo = REPO.resolve()
    target = RESULTS.resolve()
    if repo not in target.parents:
        raise RuntimeError("zone-campaign results path escapes the repository")
    RESULTS.parent.mkdir(parents=True, exist_ok=True)
    tmp = RESULTS.with_suffix(".tmp").resolve()
    if repo not in tmp.parents:
        raise RuntimeError("zone-campaign temp path escapes the repository")
    tmp.write_text(json.dumps(data, indent=2, sort_keys=True))
    tmp.replace(RESULTS)


def parse_summary(path: Path) -> dict:
    """Parse Infection's text summary into counts."""
    labels = {
        "Total": "total",
        "Killed by Test Framework": "killed_test",
        "Killed by Static Analysis": "killed_static",
        "Errored": "errored",
        "Syntax Errors": "syntax_errors",
        "Escaped": "escaped",
        "Timed Out": "timed_out",
        "Skipped": "skipped",
        "Ignored": "ignored",
        "Not Covered": "not_covered",
    }
    out = dict.fromkeys(labels.values(), 0)
    if not path.exists():
        return out
    txt = path.read_text()
    for label, key in labels.items():
        m = re.search(rf"^{re.escape(label)}:\s+(\d+)\s*$", txt, re.MULTILINE)
        if m:
            out[key] = int(m.group(1))
    return out


def compute(c: dict) -> dict:
    total = c["total"]
    covered = total - c["not_covered"]
    killed = c["killed_test"] + c["killed_static"]
    msi = (killed / total * 100.0) if total else 0.0
    covered_msi = (killed / covered * 100.0) if covered else 0.0
    return {
        **c,
        "covered": covered,
        "killed": killed,
        "msi": round(msi, 2),
        "covered_msi": round(covered_msi, 2),
    }


def read_zones() -> list[tuple[str, str]]:
    zones = []
    for raw in ZONES.read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        name, filt = line.split("|", 1)
        zones.append((name, filt))
    return zones


def run_zone(name: str, filt: str, threads_n: int, timeout_s: int, min_msi: float) -> dict:
    conf = REPO / f"infection-zone-{name}.json5"
    parents: list[str] = []
    for p in [x for x in filt.split(",") if x]:
        parent = p.rsplit("/", 1)[0] if "/" in p else "."
        if parent not in parents:
            parents.append(parent)
    summary = f"build/infection-summary-{name}.json"
    logs = f"build/infection-{name}.log"
    conf.write_text(json.dumps({
        "source": {"directories": parents},
        "timeout": 90,
        "logs": {"text": logs, "summary": summary},
        "mutators": {"@default": True},
    }, indent=2))

    started = time.time()
    timed_out = False
    try:
        subprocess.run(
            [
                "php", "vendor/bin/infection",
                f"--configuration={conf.name}",
                f"--threads={threads_n}",
                "--no-progress",
                f"--min-msi={min_msi}",
                "--min-covered-msi=0",
                f"--filter={filt}",
            ],
            cwd=REPO,
            stdin=subprocess.DEVNULL,
            stdout=open(REPO / f"build/campaign-{name}.stdout", "w"),
            stderr=subprocess.STDOUT,
            timeout=timeout_s,
        )
    except subprocess.TimeoutExpired:
        timed_out = True
    duration = round(time.time() - started, 1)

    counts = compute(parse_summary(REPO / summary))
    counts.update({
        "zone": name,
        "filter": filt,
        "duration_s": duration,
        "timed_out": timed_out,
        "threads": threads_n,
        "measured_at_epoch": int(started),
    })
    try:
        conf.unlink()
    except OSError:
        pass
    return counts


def main() -> int:
    only = sys.argv[1] if len(sys.argv) > 1 else None
    timeout_s = int(sys.argv[2]) if len(sys.argv) > 2 else 3600
    min_msi = float(sys.argv[3]) if len(sys.argv) > 3 else 0.0
    tn = threads()
    results = load_results()
    zones = read_zones()
    print(f"[campaign] repo={REPO.name} threads={tn} zones={len(zones)} timeout/zone={timeout_s}s", flush=True)

    for name, filt in zones:
        if only and name != only:
            continue
        if name in results and results[name].get("total", 0) > 0:
            print(f"[skip] {name} already measured: MSI={results[name].get('msi')}", flush=True)
            continue
        print(f"[zone] {name} -> {filt}", flush=True)
        r = run_zone(name, filt, tn, timeout_s, min_msi)
        results[name] = r
        save_results(results)
        print(
            f"[done] {name}: total={r['total']} killed={r['killed']} escaped={r['escaped']} "
            f"nc={r['not_covered']} err={r['errored']} to={r['timed_out']} "
            f"MSI={r['msi']} coveredMSI={r['covered_msi']} ({r['duration_s']}s)",
            flush=True,
        )
    print("[campaign] finished", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
