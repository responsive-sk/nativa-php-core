#!/bin/bash
#
# =============================================================================
# mago-modules-report.sh — publish the catalogue's mago counts, behind no gate.
# =============================================================================
# What it does: runs `bin/build/mago-spike.sh --scope=modules` — lint + analyze
# over `modules/` alone — then renders the counts as markdown: appended to
# `$GITHUB_STEP_SUMMARY` when that is set (the CI job), printed either way.
# The numbers are the same ones `make mago-modules` shows a person; this script
# exists so a run publishes them where the run is, not only in a log nobody
# scrolls.
#
# What it is NOT: a gate, and not a second renderer of a different number. The
# markdown table is read straight out of the spike's own `summary.tsv`, so a
# count cannot appear here that the tool did not report. Every path ends in
# `exit 0` — including "the binary did not download", which publishes that fact
# instead of failing: a publisher that can redden a pull request is a gate
# wearing a different name, and the gates over this tree are `make mago-lint`
# (ceiling 0) and `make mago-guard`.
#
# Usage:
#   bin/build/mago-modules-report.sh
#   GITHUB_STEP_SUMMARY=/tmp/step.md bin/build/mago-modules-report.sh
#
# Exit: always 0. See docs/mago-evaluation.md for what the numbers mean.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

REPORT_DIR="storage/cache/mago-spike/modules"
SUMMARY="$REPORT_DIR/summary.tsv"

# A summary left over from an earlier run — CI restores `storage/cache/` from a
# cache key that does not change per run — would be published as this run's
# numbers if the binary failed to arrive. Removing it first makes "no summary"
# mean exactly that.
rm -f "$SUMMARY"

# Informational on purpose: `--scope=modules` already refuses to look like a
# gate, and `|| true` keeps this script's own promise true even if the spike's
# shape changes. Its output still reaches the log for a reader who wants it.
"$ROOT/bin/build/mago-spike.sh" --scope=modules || true

if [ ! -f "$SUMMARY" ]; then
    # No binary, no measurement. Saying so is the honest report; inventing a
    # zero would read as "the catalogue is clean".
    report="### Module catalogue (mago, informational)

Not measured this run — \`bin/build/mago-spike.sh\` could not provide a verified
mago binary. Nothing about the catalogue is implied by this line."
else
    table="$(awk -F'\t' '
        BEGIN { print "| tool | mago | issues | wall |"; print "|---|---:|---:|---:|" }
        { printf "| `%s` | %s | %s | %s ms |\n", $1, $2, $4, $5 }
    ' "$SUMMARY")"

    # The analyzer's own breakdown, quoted rather than recomputed: `1384` alone
    # hides whether they are errors or suggestions.
    breakdown="$(grep -m1 -E '^(error|warning): found' "$REPORT_DIR/analyze.txt" 2>/dev/null || true)"

    report="### Module catalogue (mago, informational)

Not a gate — these numbers are published, they never fail this run. The gates
over the same tree are \`make mago-lint\` (ceiling 0) and \`make mago-guard\`.

$table"

    if [ -n "$breakdown" ]; then
        report="$report

\`analyze\`: ${breakdown#error: }"
    fi

    report="$report

Reproduce: \`make mago-modules\` → \`$REPORT_DIR/\`."
fi

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    printf '%s\n' "$report" >> "$GITHUB_STEP_SUMMARY"
fi

printf '%s\n' "$report"
exit 0
