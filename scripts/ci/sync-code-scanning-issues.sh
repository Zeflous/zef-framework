#!/usr/bin/env bash
# Code-scanning alert → tracker-issue synchroniser.
#
# Runs inside .github/workflows/code-scanning-issues.yml. Mirrors the GitHub
# Code Scanning alerts (Security & Quality → Code scanning: Semgrep OSS,
# SnykCode, CodeQL) into ordinary tracker issues, so every open finding has a
# visible, triageable issue without anyone copying links by hand.
#
# Contract (docs/security/code-scanning-issues.md):
#   * One issue per OPEN alert, deduplicated by the stable per-repository
#     alert number via the `<!-- cs-alert: N -->` marker in the issue body.
#   * Alert becomes fixed/dismissed → its issue is commented and closed
#     automatically (reopened automatically if the alert ever reopens).
#   * An issue closed BY A HUMAN (no `[cs-sync]` comment) is never reopened —
#     a manual close means "leave this one alone".
#   * Creation is capped per run (CS_MAX_NEW, default 25) so a first run
#     against a large alert backfill cannot flood the tracker; the next sweep
#     continues where this one stopped.
#
# Environment:
#   GH_TOKEN            — token with security-events:read + issues:write
#                         (the workflow's github.token provides both).
#   GITHUB_REPOSITORY   — owner/repo (workflow default).
#   GITHUB_STEP_SUMMARY — written when present (workflow default).
#   CS_LABEL            — tracking label (default: code-scanning).
#   CS_MAX_NEW          — per-run creation cap (default: 25).
#   CS_DRYRUN           — 1 = compute and print actions, mutate nothing.
#
# Exit status: 0 = sync completed (even if zero actions); non-zero = an API
# call failed, which fails the workflow run visibly and is retried by the
# next sweep.

set -euo pipefail

repo="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
label="${CS_LABEL:-code-scanning}"
max_new="${CS_MAX_NEW:-25}"
dryrun="${CS_DRYRUN:-0}"
summary="${GITHUB_STEP_SUMMARY:-/dev/null}"

created=0
closed=0
reopened=0
noop=0
human_kept=0
capped=0

# Wraps every mutating API call so CS_DRYRUN=1 exercises the full decision
# tree (fetch, diff, title/body construction) without touching the tracker.
mutate() {
  if [ "${dryrun}" = "1" ]; then
    printf '[dryrun] %s\n' "$*"
  else
    "$@"
  fi
}

# Maps an alert severity onto the tracker severity labels. The security
# severity (critical/high/medium/low) wins when the analyzer provides one;
# plain severities map onto the same ladder.
severity_label() {
  case "${1:-}" in
    critical|high|medium|low|note) printf '%s' "${1}" ;;
    error) printf 'high' ;;
    warning) printf 'medium' ;;
    *) printf 'note' ;;
  esac
}

# ---------------------------------------------------------------------------
# 1) Ensure the tracking + severity labels exist (idempotent, cheap).
# ---------------------------------------------------------------------------
while IFS='|' read -r lname lcolor ldesc; do
  if ! gh api "repos/${repo}/labels/${lname}" >/dev/null 2>&1; then
    mutate gh api --method POST "repos/${repo}/labels" \
      -f name="${lname}" -f color="${lcolor}" -f description="${ldesc}" >/dev/null
  fi
done <<'EOF'
code-scanning|5319E7|Auto-filed finding from GitHub Code Scanning (Security and Quality)
critical|B60205|Code scanning: critical severity
high|D93F0B|Code scanning: high severity
medium|E3A34A|Code scanning: medium severity
low|94A3B8|Code scanning: low severity
note|1D76DB|Code scanning: note / informational
EOF

# ---------------------------------------------------------------------------
# 2) Fetch every code-scanning alert (all states), oldest first, and every
#    tracked issue (all states). `--paginate` emits one JSON array per page;
#    `jq -s 'add'` merges them into a single array ([] when empty). The alert
#    list is kept in a temp file and read via jq --slurpfile: with full rule
#    descriptions it can exceed the kernel argv limit when inlined as a
#    --argjson command-line argument ("Argument list too long").
# ---------------------------------------------------------------------------
alerts_file="$(mktemp)"
tracked_file="$(mktemp)"
cleanup() { rm -f "${alerts_file}" "${tracked_file}" "${actions_file:-}" "${body_file:-}"; }
trap cleanup EXIT

gh api --paginate "repos/${repo}/code-scanning/alerts?per_page=100&sort=created&direction=asc" \
  | jq -s 'add // []' >"${alerts_file}"
gh api --paginate "repos/${repo}/issues?labels=${label}&state=all&per_page=100" \
  | jq -s 'add // []' >"${tracked_file}"

# alert-number → tracked issue record. The marker lives in the BODY, so
# retitling an issue cannot break the dedup. Pull requests carrying the
# label are ignored.
map_file="$(mktemp)"
jq -c '
  [ .[] | select(.pull_request == null)
  | (.body // "") as $b
  | ($b | scan("cs-alert:[[:space:]]*([0-9]+)")) as $m
  | select($m != null)
  | { alert: ($m[0] | tonumber), issue: .number, state: .state } ]
  | sort_by(.alert)' <"${tracked_file}" >"${map_file}"

# ---------------------------------------------------------------------------
# 3) Diff alerts against tracked issues → the action list.
#    open alert + no issue        → create
#    open alert + closed issue    → reopen (only when cs-sync closed it)
#    open alert + open issue      → noop
#    closed alert + open issue    → close
#    closed alert + no/closed     → noop
# ---------------------------------------------------------------------------
actions_file="$(mktemp)"
# -n: every input arrives via --slurpfile; without it jq blocks reading stdin.
jq -c -n --slurpfile alerts "${alerts_file}" --slurpfile map "${map_file}" '
    ($map[0] | map({key: (.alert | tostring), value: .}) | from_entries) as $m
    | [ $alerts[0][]
      | .number as $num
      | ($m[($num | tostring)] // null) as $t
      | if .state == "open" then
          if $t == null then {op: "create", alert: .}
          elif $t.state == "closed" then {op: "reopen", alert: ., issue: $t.issue}
          else {op: "noop-open"}
          end
        else
          if $t != null and $t.state == "open" then {op: "close", alert: ., issue: $t.issue}
          else {op: "noop-closed"}
          end
        end ]' >"${actions_file}"
rm -f "${map_file}"

total_actions="$(jq 'length' <"${actions_file}")"

# ---------------------------------------------------------------------------
# 4) Execute the action list.
# ---------------------------------------------------------------------------
idx=0
while [ "${idx}" -lt "${total_actions}" ]; do
  act="$(jq -c ".[${idx}]" <"${actions_file}")"
  idx=$((idx + 1))
  op="$(jq -r '.op' <<<"${act}")"

  case "${op}" in

    create)
      if [ "${created}" -ge "${max_new}" ]; then
        capped=$((capped + 1))
        continue
      fi

      a_num="$(jq -r '.alert.number' <<<"${act}")"
      tool="$(jq -r '.alert.tool.name // "?"' <<<"${act}")"
      rule="$(jq -r '.alert.rule.id // "?"' <<<"${act}")"
      sev="$(jq -r '.alert.rule.severity // "note"' <<<"${act}")"
      ssev="$(jq -r '.alert.rule.security_severity_level // ""' <<<"${act}")"
      seen="$(jq -r '.alert.created_at // "?"' <<<"${act}")"
      state="$(jq -r '.alert.state // "open"' <<<"${act}")"
      url="$(jq -r ".alert.html_url // \"https://github.com/${repo}/security/code-scanning/${a_num}\"" <<<"${act}")"
      path="$(jq -r '.alert.most_recent_instance.location.path // "?"' <<<"${act}" | sed 's#^/##')"
      line="$(jq -r '.alert.most_recent_instance.location.start_line // 0' <<<"${act}")"
      msg="$(jq -r '
        (.most_recent_instance.message.text // null) as $i
        | (.rule.description // null) as $r
        | (if ($i != null and ($i | type) == "string") then $i
           elif ($r != null and ($r | type) == "string") then $r
           else "(no message)" end)' <<<"$(jq '.alert' <<<"${act}")" | head -c 700)"
      lbl="$(severity_label "${ssev:-${sev}}")"

      title="[CS-${a_num}][${tool}][${lbl}] ${rule} — ${path}:${line}"
      body_file="$(mktemp)"
      {
        printf '<!-- cs-alert: %s -->\n' "${a_num}"
        printf '\n'
        printf 'Auto-filed from **GitHub Code Scanning** (Security & Quality → Code scanning) by `code-scanning-issues.yml`.\n'
        printf '\n'
        printf '| | |\n|---|---|\n'
        printf '| Alert | [#%s](%s) |\n' "${a_num}" "${url}"
        printf '| Tool | %s |\n' "${tool}"
        printf '| Rule | `%s` |\n' "${rule}"
        printf '| Severity | %s%s |\n' "${sev}" "$([ -n "${ssev}" ] && printf ' (security: %s)' "${ssev}")"
        printf '| Location | `%s:%s` |\n' "${path}" "${line}"
        printf '| First seen | %s |\n' "${seen}"
        printf '| State | %s |\n' "${state}"
        printf '\n'
        printf '**Message:**\n\n> %s\n' "${msg}"
        printf '\n'
        printf '**How to close this issue:** fix the code (the alert turns `fixed`) or dismiss the alert with a reason — the next sync closes this issue automatically. Do not delete the `cs-alert` marker in this body: it is the dedup key.\n'
        printf '\n'
        printf '<sub>Managed by `.github/workflows/code-scanning-issues.yml` — docs: `docs/security/code-scanning-issues.md`</sub>\n'
      } >"${body_file}"

      mutate gh api --method POST "repos/${repo}/issues" \
        -f title="${title}" \
        -F body=@"${body_file}" \
        -f "labels[]=${label}" \
        -f "labels[]=${lbl}" >/dev/null
      rm -f "${body_file}"
      created=$((created + 1))
      printf 'Created issue: %s\n' "${title}"
      ;;

    close)
      a_num="$(jq -r '.alert.number' <<<"${act}")"
      issue="$(jq -r '.issue' <<<"${act}")"
      state="$(jq -r '.alert.state' <<<"${act}")"
      reason="$(jq -r '.alert.dismissed_reason // ""' <<<"${act}")"
      a_url="$(jq -r ".alert.html_url // \"https://github.com/${repo}/security/code-scanning/${a_num}\"" <<<"${act}")"

      mutate gh api --method POST "repos/${repo}/issues/${issue}/comments" \
        -f body="[cs-sync] Alert [#${a_num}](${a_url}) is now **${state}**$([ -n "${reason}" ] && printf ' (%s)' "${reason}") — closing this tracker issue automatically. If the alert ever reopens, this issue is reopened too." >/dev/null
      mutate gh api --method PATCH "repos/${repo}/issues/${issue}" \
        -f state=closed >/dev/null
      closed=$((closed + 1))
      printf 'Closed issue #%s (alert #%s → %s)\n' "${issue}" "${a_num}" "${state}"
      ;;

    reopen)
      a_num="$(jq -r '.alert.number' <<<"${act}")"
      issue="$(jq -r '.issue' <<<"${act}")"
      a_url="$(jq -r ".alert.html_url // \"https://github.com/${repo}/security/code-scanning/${a_num}\"" <<<"${act}")"

      # Reopen only issues THIS workflow closed (a `[cs-sync]` comment is the
      # audit trail). An issue a human closed stays closed — their decision
      # outranks the mirror.
      sync_closed="$(gh api "repos/${repo}/issues/${issue}/comments?per_page=100" \
        | jq '[.[] | select((.body // "") | contains("[cs-sync]"))] | length')"

      if [ "${sync_closed}" -gt 0 ]; then
        mutate gh api --method POST "repos/${repo}/issues/${issue}/comments" \
          -f body="[cs-sync] Alert [#${a_num}](${a_url}) is open again — reopening this tracker issue." >/dev/null
        mutate gh api --method PATCH "repos/${repo}/issues/${issue}" \
          -f state=open >/dev/null
        reopened=$((reopened + 1))
        printf 'Reopened issue #%s (alert #%s open again)\n' "${issue}" "${a_num}"
      else
        human_kept=$((human_kept + 1))
        printf 'Kept issue #%s closed (closed by a human, not by cs-sync)\n' "${issue}"
      fi
      ;;

    noop-open|noop-closed)
      noop=$((noop + 1))
      ;;

    *)
      printf 'Unknown op: %s\n' "${op}" >&2
      exit 2
      ;;
  esac
done

# ---------------------------------------------------------------------------
# 5) Step summary.
# ---------------------------------------------------------------------------
open_count="$(jq '[.[] | select(.state == "open")] | length' <"${alerts_file}")"
closed_count="$(jq '[.[] | select(.state != "open")] | length' <"${alerts_file}")"
{
  echo "### Code scanning → tracker issues"
  echo ""
  echo "- Alerts: **${open_count} open** / ${closed_count} resolved (of $(jq 'length' <"${alerts_file}") fetched)"
  echo "- Issues created: **${created}**$( [ "${capped}" -gt 0 ] && printf ' (cap %s hit — %s deferred to the next sweep)' "${max_new}" "${capped}" )"
  echo "- Issues closed: **${closed}** · reopened: **${reopened}** · already in sync: ${noop} · human-closed kept: ${human_kept}"
  echo ""
  echo "Tracker: https://github.com/${repo}/labels/${label} · Alerts: https://github.com/${repo}/security/code-scanning"
} >>"${summary}"

printf 'Sync complete: %s created, %s closed, %s reopened, %s noop, %s capped, %s human-kept.\n' \
  "${created}" "${closed}" "${reopened}" "${noop}" "${capped}" "${human_kept}"
