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
   organization, set the project key to `mbetixz_zef-framework` (or note the
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
   a member of the organization **mbetixz** works — the personal-mode key
   (`mbetixz_zef-framework`) and the org-mode key (`zeflous_zef-framewrok`)
   both authenticate, because a SonarCloud token carries the permissions of
   the *user*, across every organization that user belongs to. The token
   currently stored (updated 2026-09-27 23:01 UTC) is verified green against
   `mbetixz_zef-framework` by the scan on `main` at 23:03 UTC the same day.
   The parallel project **`zeflous_zef-framework`** in the SonarCloud
   organization `zeflous` is *not* referenced by this repository and should be
   deleted (Administration → Deletion) so only one project carries the history:
   a SonarCloud organization can only decorate pull requests of GitHub repos
   owned by the account/org it is bound to, and this repository lives under
   the personal account `mbetixz`, not under the GitHub org `Zeflous`.
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
| Analysis scope | `src/`, `tests/` | `sonar-project.properties`, reviewable in-tree |
| Quality gate | server-side "Sonar way" (new code) | SonarCloud project settings |

There is no local engine to pin because the analysis runs server-side; the
reviewable, immutable input is the action commit plus the properties file.
Engine upgrades happen by re-pinning the action SHA in a reviewed pull request,
mirroring how the Semgrep engine digest is upgraded in `php-sast.yml`.

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
CI run whose head SHA matches this run's SHA (both workflows trigger on the
same `pull_request`/`push` events, so the SHAs coincide — the merge-commit
SHA on pull requests, the branch head on pushes), downloads the artifact and
passes `-Dsonar.php.coverage.reportPaths=build/clover.xml` to the scanner.
The quality gate's coverage-on-new-code condition is therefore computed on
real PHPUnit data measured by the exact execution the CI lane already gated.

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

## 7. Failure modes and their meaning

| Symptom | Meaning |
| --- | --- |
| Red job, "quality gate failed" | SonarCloud verdict was red — triage the findings via the PR decoration or the project dashboard; fix the code or adjust the gate in SonarCloud (a reviewed decision, not a workflow edit) |
| Red job, "you are running CI analysis while Automatic Analysis is enabled" | section 3 step 4 was skipped |
| Red job, log shows a tiny `N files indexed` count, `CPD Executor Calculating CPD for 0 files`, or no `Quality profile for php` line | organization is on the **Free plan**: its basic analysis set has no PHP analyzer, so PHP sources are dropped from indexing — switch the organization to the **OSS plan** (section 3 step 2) |
| Red job, "project not found" / 401 | `sonar.projectKey` / `sonar.organization` / `SONAR_TOKEN` mismatch — section 3 |
| Green job with the no-credentials notice | `SONAR_TOKEN` not set, or a pull request whose secrets GitHub withholds (`dependabot[bot]` author, fork head) — section 2 / section 3 step 6 |
| Green job, step summary says "Coverage bridge (v2) — verdict: **fallback**" | no coverage artifact for this commit (CI red, no CI run in 45 min, or artifact unavailable) — coverage condition skipped for that run, everything else enforced; the CI lane is the authoritative coverage gate in that case |
| Red job, "quality gate failed", coverage condition | new code measured below the Sonar way floor on the bridged clover data — fix the code or review the gate in SonarCloud (a reviewed decision, not a workflow edit) |
| Job runs ~20 minutes before the verdict | normal: the bridge waits for the CI lane of the same commit — see section 6 |
