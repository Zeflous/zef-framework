# Code scanning → tracker issues baseline

Automatic mirroring of GitHub Code Scanning alerts into ordinary tracker
issues, run by `.github/workflows/code-scanning-issues.yml` and implemented
by `scripts/ci/sync-code-scanning-issues.sh`.

This document records **what is mirrored, how the mirror stays consistent,
and how to triage it.** It is the reference for the synchroniser's contract,
so those decisions are reviewable instead of implicit in a shell script.

---

## 1. What this gate is, and what it is not

| Mechanism | Question it answers |
|---|---|
| **Code scanning analysis** (Semgrep OSS, Snyk — one merged tool since the evidence consolidation, CodeQL — see `php-sast.md` and `snyk-security.md`) | Does the code contain a finding? Is the finding *open* right now? |
| **This mirror** | Does every open finding have a **visible, triageable tracker issue** — without anyone hand-copying alert links? |

The mirror is *not* an analysis and *not* a blocker: it never runs on pull
requests, is not a required status check, and cannot fail a build on its own
findings. Its only failure mode is an API error, which turns the workflow
run red and is retried by the next sweep.

The alert set it mirrors is the one shown in **Security & Quality → Code
scanning** for the default branch: all tools, all states. Alerts raised only
on pull-request merge refs are deliberately ignored — the tracker mirrors
what `main` actually ships.

## 2. Why workflow triggers, not webhooks

The documented real-time integration for code scanning is the
[`code_scanning_alert` webhook](https://docs.github.com/en/code-security/concepts/code-scanning/integration-with-code-scanning#integrations-with-webhooks).
A webhook requires an **external receiver** — a server or a GitHub App that
this repository deliberately does not operate (nothing in this repo should
depend on off-repo infrastructure to function; the same principle keeps the
PR queue on `auto-update-prs.yaml` instead of a bot service).

The repo-native equivalent, and what this workflow uses:

| Trigger | Covers | Latency |
|---|---|---|
| `workflow_run` on **PHP SAST** and **Snyk Security** completing on `main` | Semgrep OSS + Snyk (merged evidence) alerts for the new head commit | minutes (as soon as the analyzers finish) |
| `schedule` (daily, 03:23 UTC) | everything, including the CodeQL default setup (a dynamic workflow outside `.github/workflows`, which `workflow_run` cannot target) | ≤ 24 h |
| `workflow_dispatch` | manual runs, and pre-merge testing on a branch | on demand |

The cron minute (`23`, off the `:00`/`:30` peaks) follows the measured
scheduler behaviour recorded in `auto-update-prs.yaml`: GitHub routinely
drops cron slots that land on peak minutes.

## 3. The synchroniser contract

`scripts/ci/sync-code-scanning-issues.sh` is **idempotent** — running it
twice changes nothing the second time. Its decision table:

| Alert state | Tracker issue state | Action |
|---|---|---|
| open | none | **create** one issue |
| open | open | nothing (in sync) |
| open | closed by `[cs-sync]` | **reopen** (the alert came back) |
| open | closed by a human | nothing — a manual close means "leave this one alone", and the human's decision outranks the mirror |
| fixed / dismissed | open | **close**, with a `[cs-sync]` comment recording the alert's terminal state and dismissal reason |
| fixed / dismissed | none / closed | nothing |

Deduplication is by the **alert number** — stable for the repository's
lifetime and unique across tools — stored as an HTML comment marker in the
issue body:

```html
<!-- cs-alert: 126 -->
```

The marker lives in the body, not the title, so retitling an issue cannot
break the sync. Deleting the marker opts the issue out of the mirror (it
will never be touched again); the standard way to close one is in § 4.

Each created issue carries:

* title `[CS-<alert>][<tool>][<severity>] <rule> — <path>:<line>`
* the tracking label `code-scanning` plus one severity label
  (`critical`/`high`/`medium`/`low`/`note`; the analyzer's security severity
  wins, plain `error`/`warning` map onto `high`/`medium`)
* a body table with the alert link, tool, rule, severity, location, first
  detection timestamp and message text.

**Per-run creation cap** (`CS_MAX_NEW`, default 25): the first run against a
large alert backfill — or a new analyzer joining — cannot flood the tracker
with a hundred issues at once. Deferred alerts are filed by the next sweep.
The cap is logged in the step summary and in the run log, never silent.

**Concurrency**: one sync at a time (`concurrency: code-scanning-issues`,
`cancel-in-progress: false`). Two analyzers completing within the same minute
queue instead of racing each other into double-filing.

## 4. Triage guide

* **The finding is real** → fix the code on `main`. The next analysis turns
  the alert `fixed`; the next sync closes the tracker issue with a comment.
  No manual step anywhere.
* **The finding is a false positive** → dismiss the alert in the Security &
  Quality tab with a reason (the repository's established practice — see the
  dismissed Semgrep test-fixture alerts from 2026-09-24). The next sync
  closes the tracker issue, citing the dismissal reason.
* **A tracker issue is noise** → close it manually. The mirror detects
  human-closed issues (no `[cs-sync]` comment) and never reopens them, even
  if the alert stays open. This is the deliberate escape hatch.
* **Do not edit the `cs-alert` marker** — it is the dedup key.

## 5. Failure modes and measured behaviour

* **Empty alert set** — `gh api --paginate` emits nothing; `jq -s 'add // []'`
  normalises to `[]`; the sync is a no-op that still writes its step summary.
* **Large alert sets** — the alert list is passed to jq through temp files
  (`--slurpfile`), never as `--argjson` command-line arguments: with full
  rule descriptions the list exceeds the kernel `argv` limit ("Argument list
  too long" — measured on 2026-09-29 with 123 alerts / ~380 KB). The program
  itself is evaluated with `jq -n` so it never blocks reading stdin.
* **API failure** — any `gh api` call failing fails the run (visible red,
  retried by the next sweep). The sync is safe to re-run: every action is
  idempotent, and an interrupted run leaves at worst an issue without its
  follow-up comment, which the next run cannot duplicate (the marker already
  exists).
* **Alert reopened after being fixed** — the alert number stays the same;
  the sync reopens the issue it closed earlier (matched via the `[cs-sync]`
  comment left at close time).
