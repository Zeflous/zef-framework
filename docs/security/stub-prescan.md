# Stub pre-scan (the tests/ fixture corpus gate)

This document is the scope-and-enforcement contract for the stub pre-scan lane.
It was committed BEFORE the implementation it describes (same matrix-first
sequence used by `docs/OPENAPI-GATE-PARITY.md` in v2.33.0): the matrix below is
the reviewed decision record, and the workflow that ships afterwards is its
executable form. Nothing in the workflow may widen, narrow or invert a cell of
this matrix without a matching change to this file in the same pull request.

## 1. What this gate is, and the gap it closes

`sonar-project.properties` keeps `tests/**` out of the main SonarCloud analysis
entirely — the v4 scope decision (see the scope note in that file and
`docs/security/sonarcloud.md`). The measured reasons stand unchanged: including
the fixture corpus in the MAIN project collapses overall coverage (33.6% vs
97.0% real src coverage, because phpunit does not instrument fixtures as
product code) and floods the reliability rating (976 bugs, 16 BLOCKER-class
`exit()`/loose-function fixtures — the fixture IDIOM, not defects).

That exclusion had a blind spot the v4 note did not claim to close: the
SonarCloud **bug detectors and vulnerability/hotspot rules never look at the
fixture corpus at all**. Test doubles and fixtures are executable code that
runs inside the CI lane with repository credentials and network reach — the
same rationale that made php-sast scan `tests/` deliberately (blocking since
2026-09-24) applies to every other analyzer that is simply not pointed at the
tree.

The stub pre-scan closes that gap WITHOUT touching the main project's metrics:
the corpus is analysed by a dedicated, quarantined SonarCloud project whose
quality gate enforces exactly the dimensions this matrix marks INCLUDED and
whose scope is pinned positively (`sonar.inclusions=tests/**`), so no tree can
enter the pre-scan without a reviewed change to this document.

## 2. The corpus: structural, not heuristic

The corpus is every PHP file under `tests/` (190 files at v2.34.0, measured
`find tests -name '*.php' | wc -l`).

The boundary is STRUCTURAL on purpose. A name-or-annotation classifier
(`class *Stub*`, `*Fake*`, `*Spy*`, `@fixture`) fails OPEN: a double named
`RecordingThing` escapes the corpus silently, and the escape is invisible to
any count. A structural boundary fails CLOSED: every new file under `tests/`
changes the count asserted against `tests/fixtures.limit`, every moved file
changes it, and the scanner's positively-pinned inclusions cannot drift
without a workflow change that diff review sees.

This mirrors the bin/ enumeration philosophy in `sonar-project.properties`
(a new file requires an explicit decision to enter scope) while staying
maintainable at fixture scale (190 enumerated lines would be churn, not
review; the count ratchet is the decision point).

## 3. The twelve-dimension INCLUDED/EXCLUDED matrix

| # | Dimension | Engine & lane | src/ production trees | tests/ corpus | Enforcement |
|---|-----------|---------------|----------------------|---------------|-------------|
| 1 | SAST, security rules, ERROR floor | Semgrep `php/lang/security` + house rules — php-sast "production source" and "tests at ERROR severity" lanes | INCLUDED | INCLUDED | Blocking (both lanes, `--error`) |
| 2 | SAST, security rules, WARNING floor | Semgrep `php/lang/security` + house rules — php-sast "production source at WARNING" and "tests and tooling" lanes | INCLUDED | INCLUDED | Blocking since 2026-09-24 |
| 3 | Bugs, correctness family | Semgrep `php/lang/correctness` + house rules — all four php-sast lanes (config ADDED in v2.34.0) | INCLUDED | INCLUDED | Blocking; measured before enabling: 0 findings / 652 production targets, 0 / 190 corpus targets |
| 4 | Bugs, deep static analysis | PHPStan level max + strict-rules + frozen baseline — ci.yml | INCLUDED | INCLUDED | Blocking; the strictly stronger engine for the corpus (v4 rationale: PHPStan analyses tests too) |
| 5 | Bugs, reliability rules | SonarCloud MAIN project (`zeflous_zef-framework`) | INCLUDED | OUT (v4 exclusion, unchanged) | Main project gate; corpus ownership delegated to rows 3–4 and 7 |
| 6 | Security, vulnerabilities + hotspots | SonarCloud MAIN project | INCLUDED | OUT (v4 exclusion, unchanged) | Main project gate; corpus ownership delegated to rows 1–2 and 7 |
| 7 | Bugs + Security, quarantined | SonarCloud STUB project (`zeflous_zef-framework-stubs`) — NEW in v2.34.0 | N/A (scope excludes src by pin) | INCLUDED | Custom gate "ZEF stub pre-scan": overall `bugs` ≤ ratchet, overall `vulnerabilities` = 0, overall `security_hotspots` (to review) = 0; scanner waits, fail-closed |
| 8 | CPD, duplication | SonarCloud copy-paste detector | INCLUDED (visible; 3% new-code condition in the main gate) | **EXCLUDED** (`sonar.cpd.exclusions=**/*` on the stub project) | Deliberate: see §4 |
| 9 | Coverage | PHPUnit clover bridge | INCLUDED (90% statements floor in ci.yml; bridged into the main project) | EXCLUDED (not instrumented; v4 rationale unchanged) | No condition on the stub project |
| 10 | Secrets | gitleaks (required check) | INCLUDED | INCLUDED | Repo-wide, blocking |
| 11 | CodeQL (actions, python suites) | CodeQL default setup | actions/python trees INCLUDED | PHP OUT — CodeQL default setup analyses `actions` + `python` only; PHP coverage is rows 1–7 | Weekly + PR |
| 12 | Fixture count | `tests/fixtures.limit` ratchet — stub pre-scan job | N/A | INCLUDED, fail-closed BOTH directions | Disk count must equal the committed limit exactly; any drift fails the job |

Rows 1–2 and 10 are PRE-EXISTING coverage of the corpus that this matrix
RECORDS (they were never documented as corpus guarantees before); rows 3, 7
and 12 are the new enforcement v2.34.0 adds.

## 4. Why CPD is EXCLUDED for the corpus (the design decision to challenge last)

Duplication in fixtures is not a defect signal, it is the fixture idiom: a
test double exists to mirror the shape of a production class closely enough
to satisfy a type, and a fixture row often repeats a setup block precisely so
the reader can diff it against its sibling by eye. The whole-tree duplication
burden of `tests/**` was measured during the v4 scope work and was one of the
reasons the tree was excluded from the main project.

Enforcing a duplication ceiling on that corpus would not produce deduplicated
fixtures; it would produce INHERITED fixture hierarchies — base fixtures,
abstract doubles, trait-composed setups — where the shared shape moves one
indirection away from every assertion that depends on it. That is an
anti-pattern for test code specifically: it trades visible repetition for
hidden coupling, and it is the exact trade the mutation-zone ratchet relies
on tests NOT making (a test that cannot be read standalone resists mutation
analysis).

So the stub project pins `sonar.cpd.exclusions=**/*` and carries NO
duplication condition, and this cell of the matrix is the recorded decision.
Challenging it requires a PR that changes this section, not a property edit.

## 5. The SonarCloud stub project

- **Project key**: `zeflous_zef-framework-stubs`, organization `zeflous`,
  visibility public (mirrors the main project). The key was verified free
  before this document was written.
- **Provisioning is idempotent and lives in the workflow itself**: the job
  checks `GET /api/components/show?component=...`; on 404 it creates the
  project via `POST /api/projects/create`, then assigns the "ZEF stub
  pre-scan" quality gate. Provisioning failure fails the job fail-closed
  (except the fork-secret case in §7). No manual SonarCloud UI step exists in
  the steady state — a sandbox that can run the workflow can rebuild the
  project.
- **Overall-conditions gate, not new-code conditions.** The gate is a
  deterministic ratchet: `bugs` overall must not exceed the committed budget,
  `vulnerabilities` overall must be 0, `security_hotspots` (to review) must
  be 0. This is deliberately INDEPENDENT of SonarCloud new-code period
  semantics (the main project's `new_lines` on main measured 49719 — period
  behaviour on a fresh project's baseline scan is exactly the kind of
  external state a fail-closed gate should not depend on). The PR branch
  carries main's whole corpus plus its own diff, so the overall budget
  enforces "no NEW corpus bugs over budget" at pull-request time while the
  baseline budget absorbs the pre-existing population once, at provisioning.
- **Branch naming, quarantined**: the workflow passes
  `-Dsonar.branch.name=prescan/pr-<number>` on pull requests and
  `-Dsonar.branch.name=prescan/main` on main pushes. The stub project is
  NOT bound to the repository ALM (it is API-created, not GitHub-App-bound),
  so PR-decoration mode is never used — a manual project without ALM binding
  rejects `sonar.pullrequest.*` parameters. Branch-per-run keeps concurrent
  PR analyses from overwriting each other's snapshots.
- **Scope pinned positively**: `-Dsonar.inclusions=tests/**` with the root
  `sonar-project.properties` `sonar.exclusions` overridden (CLI `-D` overrides
  the properties file per key) so the main project's exclusion list cannot
  leak into the stub scan. A new tree enters the pre-scan only by editing
  this document and the workflow together.
- **`files` measure asserted**: after the scan the job reads the project's
  `files` measure and requires it to equal `tests/fixtures.limit` — the
  scanner itself must have indexed exactly the declared corpus. An
  inclusions typo that silently narrowed the scope is a red job, not a
  silently-clean gate.

## 6. The `bugs` budget and the suppression register

The initial `bugs` budget is set from the MEASURED baseline of the first
analysis of the corpus (not estimated). Every finding behind that budget is
either (a) real and tolerated as fixture idiom with a one-line reason in the
register below, or (b) real and fixable, in which case fixing it and lowering
the budget in the same PR is preferred. The budget may only move DOWN
without review; moving it UP requires a PR that itemises every new finding
that forced the raise (same discipline as the phpstan baseline ratchet).

Register (empty at v2.34.0 — the baseline population is enumerated in the
release changelog):

<!-- entries: path:line, rule, reason -->

## 7. Failure semantics

- `SONAR_TOKEN` absent or withheld (fork pull requests, unconfigured
  repositories): the job reports the unconfigured state with a `::notice::`
  and passes, mirroring the main SonarCloud workflow's documented behaviour
  for the same condition (docs/security/sonarcloud.md, "Report unconfigured
  state"). This is the ONLY fail-open edge of the gate.
- Token present but provisioning denied (403 from the create/gate APIs): the
  job fails. A half-provisioned quarantine project must be visible, not
  green.
- Scanner indexed fewer or more files than `tests/fixtures.limit`: the job
  fails (both directions).
- Quality gate red on the stub project (bugs over budget, any vulnerability,
  any unreviewed hotspot): the job fails via `sonar.qualitygate.wait=true`.

## 8. Relationship to the existing gates

The pre-scan is a REQUIRED pull-request check ("Stub pre-scan (tests
fixtures)"). It does not replace, relax or reorder any existing gate: the
php-sast lanes keep scanning `tests/` with the security rules (and, from
v2.34.0, the correctness family), PHPStan keeps its max+strict pass over the
corpus, and the main SonarCloud project's scope and gate are untouched. Every
change shipped with this document is additive: one new config path on four
existing Semgrep lanes (row 3), one new workflow, one new count ratchet file,
one quarantined SonarCloud project.
