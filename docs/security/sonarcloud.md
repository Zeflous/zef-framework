# SonarCloud gate

Cloud-side quality analysis for the PHP code in this repository, run by
`.github/workflows/sonarcloud.yml` and configured by `sonar-project.properties`
at the repository root.

This document records **what is analysed, by what, which verdict blocks a pull
request, what this gate explicitly is not, and the one-time setup it needs.** It
is the reference for the scope and policy decisions, so those decisions are
reviewable instead of implicit in a workflow file — same contract as
`docs/security/php-sast.md` for the Semgrep gate.

---

## 1. Position in the pipeline

The quality pipeline has two independent lanes, and a pull request must satisfy
both:

```
[ pull request opened ]
        |
        +---> CI lane (ci.yml): PHPStan max level, Deptrac, mutation testing,
        |     coverage gate, style, platform smoke. Proves the code WORKS and
        |     the architecture stays clean — locally, deterministically.
        |
        +---> Security/Review lane:
              - php-sast.yml  : Semgrep (pinned engine + ruleset, SARIF to
                                code scanning) — see docs/security/php-sast.md
              - snyk-security.yml : SCA / IaC (SARIF to code scanning)
              - sonarcloud.yml: SonarCloud — bugs, vulnerabilities, code
                                smells, duplications, security hotspots,
                                new-code conditions, with pull-request
                                decoration comments.
```

The lanes are deliberately redundant: Semgrep, Snyk Code, CodeQL and SonarCloud
use different engines with different blind spots. A finding any one of them
reports is actionable; a finding none of them reports is the only kind that
ships silently.

## 2. What this gate is

- **Cloud-side analysis** of `src/` (production) and `tests/` (test code —
  analysed on purpose: it runs with repository credentials on CI and on
  developer machines).
- **Blocking on the SonarCloud quality-gate verdict**: `sonar.qualitygate.wait=true`
  makes the scanner wait for the server-side computation; a red gate fails the
  job. The default "Sonar way" gate applies (new-code reliability, security,
  maintainability, duplications).
- **Pull-request decoration**: once the GitHub ALM binding is configured
  (section 3, step 5), SonarCloud posts inline comments and a quality summary
  on every pull request it analyses.
- **Always conclusive; green-with-notice when uncredentialed**:
  whenever the job cannot scan credentialed — `SONAR_TOKEN` not set yet, or a
  `pull_request` event where GitHub withholds repository secrets (a
  `dependabot[bot]` author, or a head from a fork) — the job is green **with a
  visible notice** (workflow step summary + `::notice::`), never red and never
  silently green. Because this context is a *required* check, the job always
  runs and concludes: PR #208 (2026-09-28) measured that a job-level skip is
  eventually accepted by branch protection (the dependabot PR merged
  full-auto about 15 minutes after its checks settled — GitHub's auto-merge
  evaluation is lazy, not strictly event-driven), but a deterministic success
  with an explanatory notice is a stronger contract than relying on how
  GitHub scores "skipped" conclusions, and it tells the author exactly why
  no analysis ran.

## 3. Setup checklist (one-time, ~5 minutes)

The workflow ships fully wired; only the SonarCloud account side is manual:

1. Sign in at <https://sonarcloud.io> with the GitHub account that owns this
   repository (GitHub login → SonarCloud organization is created for the
   account on first use).
2. **Put the organization on the OSS plan** (free for open-source
   organizations). The organization created in step 1 defaults to the **Free
   plan**, whose basic analysis set has **no PHP analyzer**: measured live on
   this repository, the scanner preprocessed 680 files in 2 languages but
   indexed only the 2 JSON files (`CPD Executor Calculating CPD for 0 files`,
   quality profile `Sonar way core` for json only) and the run ended red with
   an effectively empty code model. The OSS plan unlocks **all languages
   supported in the Team plan** — PHP included — plus unlimited branch and
   pull-request analysis, at zero cost for public repositories. During
   organization creation, use the **Get SonarQube for OSS** link ("Are you
   part of an open source organization?" section); for an existing
   organization, Administration → Subscription (Manage your subscription) →
   switch to the OSS plan.
3. **Create project manually**: "Create project → Manually", pick the
   organization, set the project key to `zeflous_zef-framework` (or note the
   key SonarCloud assigns and align `sonar-project.properties` in the same
   change). Choose **public project** — the OSS plan applies to public
   repositories.
4. **Disable Automatic Analysis**: Project Administration → Analysis Method →
   disable "Automatic Analysis". CI-based analysis (this workflow) and
   automatic analysis conflict; the scanner fails with an explicit error while
   both are enabled.
5. **Bind GitHub (ALM integration)**: Administration → ALM Integrations →
   GitHub → install the SonarCloud GitHub App on this repository. This enables
   pull-request decoration and branch links in the SonarCloud UI.
6. **Create a token** (My Account → Security → Generate Token, type "Global /
   User Token") and save it as the repository secret `SONAR_TOKEN`
   (Settings → Secrets and variables → Actions). The name is fixed by
   `.github/workflows/sonarcloud.yml`. Any token whose SonarCloud identity is
   a member of the organization **zeflous** works — the org-mode key
   (`zeflous_zef-framework`) and the legacy personal-mode key
   (`mbetixz_zef-framework`) both authenticated while both organizations
   shared a member, because a SonarCloud token carries the permissions of
   the *user*, across every organization that user belongs to. The token
   currently stored (rotated 2026-09-28, org-mode migration) is an org-key
   identity of the organization **zeflous**.
   The org-mode migration (2026-09-28) followed the repository transfer to
   the GitHub organization `Zeflous`: a SonarCloud organization can only
   decorate pull requests of GitHub repos owned by the account/org it is
   bound to, so the referenced project is now **`zeflous_zef-framework`**
   in the SonarCloud organization `zeflous`, and the legacy personal-mode
   project `mbetixz_zef-framework` in the organization `mbetixz` (first
   credentialed analysis 2026-09-27, decoration confirmed on PR #207) is no
   longer referenced and should be deleted (Administration → Deletion) so
   only one project carries the history. The migration also starts a fresh
   new-code period on the org-mode project: the previous project's
   accumulated new-code window (which had drifted to a 3.8% duplication
   density against the 3% gate on `main`, 2026-09-28) does not carry over.
7. **Verify**: Actions tab → *SonarCloud* workflow → *Run workflow* (manual
   dispatch is enabled for exactly this). The first run analyses `main` and
   must end green. If the log shows a tiny "N files indexed" count and no
   `Quality profile for php`, the organization is still on the Free plan —
   back to step 2.
8. **Promoted to a required check** (done 2026-09-28): branch protection on
   `main` lists the required status-check context **`SonarCloud Scan`** — the
   job name is load-bearing and pinned by the workflow file. With the
   green-with-notice contract above, the required context stays satisfiable
   on dependabot and fork pull requests: the job always concludes, green with
   an explanatory notice when it cannot scan credentialed.

## 4. Analysis inputs and supply chain

| Input | Value | Pinned how |
| --- | --- | --- |
| Scanner action | `SonarSource/sonarqube-scan-action` v8.2.2 | full commit SHA `ba9859eae8dd6bd29e412f25ddbbef3d032000f4` (repository policy: every action pinned to a full-length SHA) |
| Scanner engine | downloaded by the action from the pinned action's logic | version is a property of the pinned action commit; upgrades are deliberate pull requests |
| Analysis scope | `src/` only (505 files) | `sonar.exclusions` in `sonar-project.properties` — an explicit exclusion list, because SonarCloud's new analysis-scope UX ignores `sonar.sources`/`sonar.tests` and analyses the whole project by default (re-scoped 2026-09-28, measured on PR #227; `tests/` was moved out 2026-09-29 — see "Analysis scope v4" below); reviewable in-tree |
| Issue suppressions | `php:S1523` on two by-design `eval()` sites (`TinkerSession.php`, `AutowireAotCompiler.php`) | `sonar.issue.ignore.multicriteria` in `sonar-project.properties`, two file-scoped criteria with the rationale in comments — matching the inline `nosemgrep` triage the Semgrep lane already carries for the same sites |
| Checkout depth | full history (`fetch-depth: 0`) | the scanner's SCM sensor needs blame data and the `origin/main` ref to compute the new-code window; a shallow checkout makes the whole analysis read as new code |
| Coverage-path exclusions | `modules/`, `plugins/` (demo code instrumented by the clover report but outside the analysis scope) | the `**/modules/**` and `**/plugins/**` patterns of `sonar.exclusions` in `sonar-project.properties`, reviewable in-tree |
| Quality gate | server-side "Sonar way" (new code) | SonarCloud project settings |

There is no local engine to pin because the analysis runs server-side; the
reviewable, immutable input is the action commit plus the properties file.
Engine upgrades happen by re-pinning the action SHA in a reviewed pull request,
mirroring how the Semgrep engine digest is upgraded in `php-sast.yml`.

### Analysis scope v4 (2026-09-29): src/ only — why tests/ left the analysis

The v3 scope kept parity with the pre-UX analysis (`src/` + `tests/`, 680
files). The first main-branch analysis of the fresh org-mode project
(`zeflous_zef-framework`, 2026-09-28 16:10 UTC) then measured what that
parity costs on a project whose new-code window is young: **the whole
analyzed surface read as new code**, and with `tests/` inside the scope the
gate red on three counts at once —

* **coverage 33.6% vs the real 97.0%**: the scope UX has no `sonar.tests`
  equivalent, so test files are counted as production code in the coverage
  denominator while the bridged clover report (which instruments
  `src`+`modules`+`plugins` only) provides no data for them. Every test
  file measures 0%.
* **reliability E**: 976 bugs, overwhelmingly test-file findings — ~690
  CRITICAL `php:S5783` (try-block shape) and all 16 BLOCKERs
  (`php:S1799` exit() fixtures, `php:S2007` loose fixture functions).
* **security D**: test-fixture `eval()`/assert findings counting as
  vulnerabilities.

The same arithmetic would have re-run on **every future pull request that
adds tests**: new test lines are new code, their S5783/S2007 findings are
new-code issues, and the Sonar way gate (reliability A on new code) would
fail every test-writing PR. That is an unusable gate, not a strict one.

The v4 decision: **SonarCloud reviews the product code (`src/`)
only.** Test-code quality is owned by strictly stronger in-repo gates that
Sonar's test-style rules cannot complement, let alone replace: the mutation
zone ratchet (`composer mutation:zones` — per-zone floors enforced on every
push, aggregate MSI 85 / covered 90 on release tags), PHPStan level max
with strict rules over `tests/` too, and PHPUnit's `failOnRisky`/strict
policies. The two `php:S1523` suppressions above keep the src-only security
rating honest on the two deliberate, documented `eval()` features (REPL
tinker; AOT container compile) — the same triage the Semgrep lane already
records inline at those sites.


### Analysis scope v5 (2026-09-29, PR #251): ZEF-owned code, fail-closed — and the triage of the widened surface

v5 moves the six first-party trees out of `sonar.exclusions` into
`sonar.coverage.exclusions` (analysed for issues, out of the coverage
denominator): `app/**`, `**/modules/**`, `**/plugins/**`, `bin/zef`,
`bin/worker.php`, `scripts/**`, `autoload/**` — 42 → 36 exclusion patterns.
The rationale (blind spots, fail-open globs, the downloaded `bin/rr` binary)
is recorded in the PR #251 body; this section records what the widened gate
SAW and how it was triaged, because the first analysis of the widened scope
surfaced **1308 pre-existing findings** — every issue in the newly-analysed
trees reads as new code against a main that never analysed them before.

Triage (same discipline as the src/ zero-debt rounds — fix the genuine
findings, suppress the by-design noise with the rationale recorded in
`sonar-project.properties`):

| Disposition | Count | What |
|---|---|---|
| Fixed in code | ~60 | pythonsecurity:S2083 path-traversal containment (zone_campaign.py), a real reluctant-quantifier + precedence regex pair (show_escapes.py), bounded char-class regexes (mine_escapes.py), 3 python cognitive-complexity refactors, 12 shell `[[` conversions, require→require_once ×4, literal merges, extracted increments/assignments, 6 commented no-op listener bodies, 8 generic-exception → `InvalidConfigurationException` swaps in the demo app (mirroring `SecurityRateLimitWiring`), CorsMiddleware/GlobalErrorHandler/ConfigProvider return-count + complexity decompositions, 13 overlong lines wrapped, a pre-existing `createEmpty()` signature drift that had left the event-sourcing smoke script unable to run at all |
| Suppressed, generated artifact | 598 | `autoload/**` — the classmap is generated by `scripts/dev/update_classmap.php`; line length, file size, the top-level autoloader function, duplicated path fragments and declare+register side effects are properties of the artifact |
| Suppressed, procedural tooling contract | ~640 | `scripts/**` + `bin/*` — top-level helper functions, declare-then-execute file shape, `exit()` as the CLI status contract, ASCII grammar regexes, historical line length (the 120-col ratchet is a src/ discipline; no formatter owns these trees) |
| Suppressed, documented contracts | ~10 | the demo credentials-redaction + correlation-id ASCII grammars, the last-resort `@error_log` silencer, the smoke fixture's interface-mandated `$event` params and two-classes-per-file shape |
| Style rules broadened src/** → ** | 5 | S1105/S1106/S139/S1578/S1808 — the php-cs-fixer byte-ownership doctrine is repo-wide (the fixer covers src/modules/plugins/app/tests; scripts/bin/autoload have no formatter at all, and Sonar's brace/filename defaults are nobody's style here) |

The security win that justifies the widened scope: the first analysis of
`scripts/mutation/zone_campaign.py` produced a genuine
`pythonsecurity:S2083` path-traversal flag (conservative — the path was a
module constant, but the taint engine could not prove it), now closed with
an explicit repository-containment assertion in `save_results()`. Tooling
that runs with CI credentials is exactly the code this gate exists to see.

## 5. What this gate explicitly is not

- **Not a replacement for PHPStan or Deptrac.** Those run at maximum locally
  pinned levels and block earlier, with repo-specific rules SonarCloud does not
  know (layer contracts, mutation score floors). SonarCloud adds an independent
  second opinion, not a substitute.
- **Not a SAST replacement.** Semgrep (pinned ruleset, fail-closed verification,
  `docs/security/php-sast.md`) stays the merge-blocking pattern-matching gate.
  SonarCloud security hotspots complement it with taint-flow heuristics that
  require human triage.
- **Not the authoritative coverage floor.** The CI lane stays the authoritative
  coverage gate (Xdebug driver, 90% statements floor, evidence artifact).
  Since bridge v2 this lane *also* enforces the Sonar way gate's
  coverage-on-new-code condition on the same clover data, as an independent
  second opinion — see section 6.
- **Not credentialed on dependabot/fork pull requests.** GitHub withholds
  repository secrets on those `pull_request` events, so no analysis runs
  there. The check reports green with a notice explaining exactly that — a
  deterministic success conclusion instead of the "skipped" outcome a
  job-level guard would leave behind (PR #208 measured that "skipped" is
  eventually accepted by branch protection, but it is undocumented scoring
  with a grey icon and no explanation). Those PRs are still fully gated by
  the CI lane, and they change pinned action SHAs rather than PHP code this
  gate could newly judge.

## 6. Coverage bridge (v2, 2026-09-28)

The CI lane uploads `build/clover.xml` as the artifact **`coverage-clover`**
for every commit it analyses (ci.yml, "Upload coverage evidence"). This lane
consumes that artifact for the *same commit*: the bridge step waits for the
CI run triggered by the same event. The matching key is the sha the Actions
API reports runs by — the pull request **head** sha on `pull_request` events,
the branch head on `push` — which is NOT `GITHUB_SHA` inside a
`pull_request` job (that is the ephemeral merge commit of
`refs/pull/N/merge`; querying by it matches no run at all — measured live on
PR #209, where the CI lane had completed while the bridge kept polling). The
bridge therefore resolves the PR head sha from the event payload, downloads
the artifact and passes `-Dsonar.php.coverage.reportPaths=build/clover.xml`
to the scanner. The quality gate's coverage-on-new-code condition is
therefore computed on real PHPUnit data measured by the exact execution the
CI lane already gated.

The wait is bounded by the CI job's own timeout (45 minutes, matched in the
bridge step); the job timeout was raised to 60 minutes accordingly. Typical
wall time is ~20 minutes (CI ~15 + scan ~4), running in parallel with the CI
lane rather than after it.

**Fallback, announced never silent.** When coverage genuinely cannot be
bridged — the CI run ended red (no artifact, and the CI lane already reports
that commit), no completed CI run appeared within the 45-minute bound, or the
artifact could not be fetched (expired retention) — the run scans with
`-Dsonar.coverage.exclusions=**/*` for that single run, so the gate's
coverage condition is skipped while every other condition (bugs,
vulnerabilities, code smells, duplications, security hotspots) stays enforced.
Every fallback is stated in the step summary ("Coverage bridge (v2) — verdict:
fallback") and via `::notice::`. The properties file deliberately contains no
coverage keys: the posture is chosen per run, in the workflow, where the
artifact availability is actually known.

**Out-of-scope paths in the clover report.** The clover report arrives with a
wider instrumentation scope than this analysis: `phpunit.xml.dist`
instruments `src/`, `modules/` and `plugins/` (the CI coverage gate asserts on
all three), while this lane analyses `src/` and `tests/` only. Every bridged
scan therefore contains coverage entries for the 14 `modules/` + `plugins/`
demo files that resolve to no analysed file, which sonar-php logs as the WARN
`Failed to resolve 14 file path(s) in PHPUnit coverage clover.xml report.
Nothing is imported related to file(s): ...` (measured on the main scan of
2026-09-28 00:46 UTC, run 36362712437). The mismatch is deliberate — the demo
modules are not production code this gate judges — and the
`**/modules/**,**/plugins/**` patterns of `sonar.exclusions` declare it: the
PHP analyzer filters unresolved report paths through those patterns
(sonar-php's `AbstractReportImporter`), so the expected warning is
suppressed while nothing else changes — the patterns match nothing under
`src/` or `tests/`. (2026-09-28 correction: the property is
`sonar.exclusions`, PLURAL. The earlier singular `sonar.exclusion` line was
a silent no-op — it only looked effective because `sonar.sources=src` was
already keeping those directories out. SonarCloud's new analysis-scope UX,
which ignores `sonar.sources`/`sonar.tests` entirely, exposed it: the first
org-mode scan after the UX activated indexed all 883 tracked files,
`modules/` and `plugins/` included. The scope now lives entirely in the
plural property's exclusion list — see `sonar-project.properties`.)
The warning remains a useful tripwire: if it ever re-appears, a path *outside*
those two directories is emitting coverage data, and that deserves a conscious
scope decision. Two notes for future readers:

1. If the analysis scope is ever widened to include `modules/` or `plugins/`
   (their coverage would then be imported too), drop those `sonar.exclusions`
   patterns first — they would keep those files out of the analysis entirely.
2. This is not a `sonar.projectBaseDir` problem, and hardcoding a runner path
   here would not silence this warning: the scan action already passes
   `-Dsonar.projectBaseDir=.`, the log shows `Base dir:
   /home/runner/work/zef-framework/zef-framework` matching the clover paths,
   and coverage for `src/` resolves fine (97%). A wrong base directory would
   fail to resolve *every* file in the report — hundreds — not exactly the
   out-of-scope ones.

## 7. Failure modes and their meaning

| Symptom | Meaning |
| --- | --- |
| Red job, "quality gate failed" | SonarCloud verdict was red — triage the findings via the PR decoration or the project dashboard; fix the code or adjust the gate in SonarCloud (a reviewed decision, not a workflow edit) |
| Red job, "you are running CI analysis while Automatic Analysis is enabled" | section 3 step 4 was skipped |
| Red job, log shows a tiny `N files indexed` count, `CPD Executor Calculating CPD for 0 files`, or no `Quality profile for php` line | organization is on the **Free plan**: its basic analysis set has no PHP analyzer, so PHP sources are dropped from indexing — switch the organization to the **OSS plan** (section 3 step 2) |
| Red job, "project not found" / 401 | `sonar.projectKey` / `sonar.organization` / `SONAR_TOKEN` mismatch — section 3 |
| Green job with the no-credentials notice | `SONAR_TOKEN` not set, or a pull request whose secrets GitHub withholds (`dependabot[bot]` author, fork head) — section 2 / section 3 step 6 |
| Green job, step summary says "Coverage bridge (v2) — verdict: **fallback**" | no coverage artifact for this commit (CI red, no CI run in 45 min, or artifact unavailable) — coverage condition skipped for that run, everything else enforced; the CI lane is the authoritative coverage gate in that case |
| WARN `Failed to resolve N file path(s) in PHPUnit coverage clover.xml report` | the clover report references files outside the analysis scope — paths under `modules/`, `plugins/` or `tests/` are declared out of scope via `sonar.exclusions` and stay quiet (section 6); paths anywhere **else** mean a new directory is emitting coverage data: decide consciously — drop the matching `sonar.exclusions` pattern (adding the tree to the analysed scope) or exclude it from the PHPUnit instrumentation scope |
| Red job on a push to main, new-code numbers sized like the whole codebase, ratings like coverage 33% / reliability E / security D | the **first analysis of a freshly created project** (or one whose new-code window has no baseline yet): everything indexed counts as new code, so long-standing overall debt gates at once. Measured 2026-09-28 on the org-mode migration. Fixes: scope hygiene (v4 — see section 4) so the overall debt is the product code's real debt, then let the next push re-evaluate |
| Red job, "quality gate failed", new-code numbers sized like the whole codebase (`new_lines` in the six figures) | the new-code window was computed over the entire analysis instead of the pull-request diff — two measured causes on PR #227 (2026-09-28): a shallow checkout (no blame, no `origin/main` ref, so every file reads as new) and the new analysis-scope UX indexing files outside `src/`+`tests/` — check `fetch-depth: 0` on the checkout step and the `sonar.exclusions` list |
| Scanner log: "The following properties are configured but have no effect: sonar.sources, sonar.tests" | SonarCloud's new analysis-scope UX is active: scope is controlled by `sonar.exclusions` only — this is expected and documented (section 6); the properties are deliberately absent from `sonar-project.properties` |
| Red job, "quality gate failed", coverage condition | new code measured below the Sonar way floor on the bridged clover data — fix the code or review the gate in SonarCloud (a reviewed decision, not a workflow edit) |
| Job runs ~20 minutes before the verdict | normal: the bridge waits for the CI lane of the same commit — see section 6 |
