# Snyk security gate

Security scanning for this repository by the Snyk platform — Open Source (SCA),
Code (SAST) and IaC — run by `.github/workflows/snyk-security.yml`.

This document records **what is scanned, by what, which status check actually
blocks a pull request, and how the Snyk-related check runs relate to each
other.** It is the reference for the evidence-consolidation and
required-context decisions (2026-09-30), so those decisions are reviewable
instead of implicit in a workflow file or a branch-protection setting.

---

## 1. What this gate is

| Question it answers | Gate |
|---|---|
| Does the **first-party source** contain a security-relevant flow? | **Snyk Code (SAST)** — `snyk code test`, severity floor `low` |
| Does the **installed dependency set** carry a known vulnerability or an unaccepted license? | **Snyk Open Source (SCA)** — `snyk test --all-projects`, severity floor `low` |
| Do the **deploy manifests** (Dockerfile, compose, k8s) contain a misconfiguration? | **Snyk IaC** — `snyk iac test`, severity floor `low` |

All three scanners run in **one workflow job named `snyk`**, sequentially, with
`SNYK_TOKEN` scoped to that job. The job's pass/fail is decided by a single
explicit **fail-closed verdict step** that evaluates the three scanner exit
codes — nothing else:

| Scanner outcome | Verdict |
|---|---|
| exit 0 (clean) | PASS |
| exit 1 (any finding, even LOW) | FAIL |
| exit > 1 (scanner error) | FAIL |
| no exit code reported (missing result) | FAIL |

Findings are blocking. There is no severity tier that is silently tolerated, no
`continue-on-error`, and the verdict step runs `if: always()` so a failure
upstream can never be swallowed.

## 2. Evidence pipeline (one check run, not four)

Every scanner writes its own SARIF file (`snyk-code.sarif`, `snyk-sca.sarif`,
`snyk-iac.sarif`). Two CI-only scripts then shape the evidence:

1. `scripts/ci/normalize_sarif_severity.py` rewrites non-numeric
   `security-severity` values (Snyk emits the literal string `"undefined"` for
   license issues, which GitHub Code Scanning rejects) to `"0.0"`.
2. `scripts/ci/merge_snyk_sarif.py` concatenates the three documents into ONE
   SARIF whose runs all carry the single tool name **`Snyk`**.

A single `upload-sarif` step (category `snyk`) then publishes the merged
document, so the whole Snyk evidence set renders as **one** check run:
`Code scanning results / Snyk`. This is the same pattern the CodeQL
default-setup analyses already use for their multi-language runs — one tool
name, one upload, one check — and it replaced the three per-scanner check runs
(`SnykCode`, `Snyk Open Source`, `Snyk IaC`) that the separate uploads used to
produce.

The evidence upload runs **before** the verdict step and `if: always()`: the
Code Scanning tab is populated regardless of the verdict outcome, and the
verdict is computed from scanner exit codes, never from the merged evidence.
The `sync-code-scanning-issues.sh` mirror is unaffected by the merge: it reads
the alert set of all tools without filtering by tool name.

Alert identity note: the tool rename (`SnykCode` → `Snyk`) does not migrate
alert history. At the cutover (2026-09-30) there were **0 open** Snyk code
scanning alerts in any state except 15 `dismissed` historical SnykCode alerts
(pre-`.snyk` triage, listed in `.snyk` today as path exclusions); those stay
dismissed under the old tool name. If one of those paths ever regresses, the
CLI gate fails the job before the alert identity matters.

## 3. Status-check strategy on the protected `main`

Before 2026-09-30, branch protection required three Snyk-related contexts at
once: `snyk` (the workflow job), `SnykCode` (a Code Scanning check run fed by
this workflow's own upload), and `code/snyk (Zeflous)` (the Snyk GitHub App's
PR check). The audit that collapsed them to one (the App check was later
re-promoted as an out-of-band backstop — see the decision below):

* **`SnykCode` was a strict shadow of `snyk`.** Its SARIF came from the same
  CLI run the verdict already gates on: scanner exit 0 → empty SARIF → the
  check passes; scanner exit 1 → the `snyk` job is already red. It also has a
  missing-result hole (a scan that errors before writing SARIF never creates
  the check), and it was the only one of the three uploaded evidence checks
  that was required — `Snyk Open Source` and `Snyk IaC` were not, an
  asymmetry with no documented reason.
* **`code/snyk (Zeflous)` is a third, weaker copy of the same SAST verdict.**
  It is produced by the Snyk GitHub App out-of-band, with the Snyk
  organization's own thresholds rather than the in-repo fail-closed policy, so
  it can pass where this gate fails. Its Details link resolves to
  `app.snyk.io/org/mbetixz/...` — at audit time the Snyk organization was the
  **personal** default account, so the check's availability was coupled to
  that account's quota/plan and to Snyk cloud availability. A required context
  that can silently stop being reported (plan lapse, integration removed,
  outage) blocks every merge with no in-repo signal, which is exactly the
  silent-disappearance failure mode this repo avoids elsewhere by keeping
  every other gate repo-native.

**Decision (2026-09-30):** two Snyk-related required contexts, one in-repo
and one out-of-band:

* `snyk` — the in-repo, fail-closed, severity-floor-`low` job — remains the
  primary gate.
* `code/snyk (Zeflous)` — the Snyk GitHub App PR check — was promoted back to
  required as a **tamper-resistant backstop**: its verdict is computed in
  Snyk's cloud out-of-band, so it survives a compromised workflow file, a
  leaked `SNYK_TOKEN`, or an edited `.snyk` policy — the failure modes an
  in-repo-only gate cannot defend against.

The promotion followed the preconditions this document set when the check was
demoted: the Snyk integration now lives in the user-managed Snyk organization
"Zeflous" (renamed from the personal default; the org slug in
`app.snyk.io/org/mbetixz/...` URLs does not change on rename), and the
check's posting behavior was verified empirically before the protection
change. It is a **commit status** (not a check run), typically posted within a
minute of a pull request opening (observed: 0.3 min on the three most recent
PRs at promotion time), and it posts on human and dependabot pull requests
alike. Slower patches were observed only during the integration's first hours
(20.9 min and 163 min on 2026-09-29) and delay, not break, auto-merge.

Residual risks, accepted knowingly: the backstop couples merge availability
to Snyk cloud availability and the org's plan standing — a lapsed integration
blocks merges with no in-repo signal. The App check is delta-based with the
Snyk org's thresholds, so it can pass where the in-repo gate fails; it is an
independent second opinion, never a replacement for the fail-closed job. The
merged evidence check (`Code scanning results / Snyk`) stays advisory.

Rollback is one branch-protection edit: remove the context from the required
list.

## 4. Policy file (`.snyk`) — reviewed boundaries, not silent skips

The `.snyk` file carries two independent scopes, each entry with a written
reason:

* `exclude:` — Snyk Code path exclusions for CLI-only developer tooling whose
  path arguments are already lexically validated and containment-checked
  (the `python/PT` rule recognises no sanitizer for legitimate CLI path
  arguments), and for unit-test fixtures whose literal throwaway credentials
  are the *input* to the behaviour under test.
* `ignore:` — Snyk Open Source **license** ignores for MIT dependencies of
  the RoadRunner/PSR stack, each with a reason and an expiry so nothing is
  waived permanently. **No vulnerability is ignored** — a real advisory still
  exits 1.

Compensating controls for every exclusion: CodeQL's Python analysis and the
Semgrep `scripts/` pass still scan the excluded Python files, and the Semgrep
`tests/` pass still scans the excluded fixtures — on every push and pull
request.

## 5. What this gate is not

* It is **not** a secret scanner — Gitleaks (`secret-scan.yml`) owns that.
* It is **not** the PHP SAST gate — Semgrep OSS (`php-sast.yml`,
  `php-sast.md`) owns source-pattern analysis with in-repo pinned rules; the
  two overlap deliberately as independent engines with different rule sets.
* It is **not** a dependency-change gate — Dependency Review
  (`dependency-review.yml`) diffs the manifest of a pull request against
  base; Snyk SCA audits the actually-installed set on every push and PR.
* It does **not** replace the Composer audit in `ci.yml` — that gate runs
  offline with no third-party credential, and keeps passing when Snyk is
  unreachable.
