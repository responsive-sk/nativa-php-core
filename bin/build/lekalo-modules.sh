#!/usr/bin/env bash
# lekalo-modules.sh — the whole model, and every module on its own.
#
# `lekalo validate` covers the whole project; `lekalo validate --module <id>` adds
# that module's own report. Four things about the pinned CLI were measured on
# v0.6.5 (2026-10-16) and re-checked on v1.2.0 (2026-11-18), and shape this script:
#
#   1. `--module` scopes the REPORT, not the validation. The whole project is
#      validated either way, so a healthy module answers exit 1 while another
#      module is red:
#
#          $ lekalo validate --module media        # while blog is broken
#          invalid error [LEK-SEM-019] ... lekalo/modules/blog/commands.yaml:12:19
#          exit 1
#
#      A loop that trusted that exit code would print twenty-one failures and name
#      none of them.
#
#   2. The TEXT output does not always name the file. A structural refusal reads
#      `invalid error [LEK-STR-004] structure.document-missing: ...` with no path at
#      all, while `validate --json` carries `source.path` for the same diagnostic —
#      `lekalo/modules/media/module.yaml` for a slice that lost its document, and
#      `lekalo/modules/_probe` for a directory the path policy rejects. So
#      attribution reads the JSON: the text is for humans, the JSON is for naming.
#
#      And the JSON is not where a well-behaved status stream would be: on a VALID
#      model it is on stdout, on an INVALID one it is on stderr, with stdout empty.
#      Hence the `2>&1` below — reading only stdout means reading nothing exactly
#      when something is wrong, and a silently empty attribution is how the first
#      version of this script reported twenty-one clean modules in a red tree.
#
#   3. A refusal is not a pass. `--module <id>` answers with the marker line
#      `... (module <id>)` when it reported on that module, and with nothing of the
#      kind when it refused — so a module is only `ok` when BOTH no diagnostic names
#      it and its scoped run confirmed it. Without that, a rejected directory
#      printed `ok` for a module nothing had validated.
#
#   4. A definition is not checked against the code (no adapter), and a
#      symbol-level `authorization.yaml` reference is not checked at all — the
#      default profile resolves those only as far as the module. This script
#      validates the model; it does not make the model true.
#
# Diagnostics outside every module (the authorization file, `project.yaml`) are
# reported rather than dropped.
#
# Usage: bin/build/lekalo-modules.sh
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

LEKALO="${LEKALO:-$(command -v lekalo 2>/dev/null || echo "$HOME/.cargo/bin/lekalo")}"

if ! command -v "$LEKALO" > /dev/null 2>&1; then
    echo "lekalo-modules: FAILED — no lekalo CLI at '$LEKALO'; install it or pass LEKALO=<path>" >&2
    exit 1
fi

printf '=== whole project ===\n'

whole_status=0
if whole="$( "$LEKALO" validate 2>&1 )"; then
    printf 'ok    %s\n' "$(printf '%s' "$whole" | tail -1)"
else
    whole_status=1
    printf '%s\n' "$whole" | sed 's/^/      /'
fi

# code|message_id|path, one per diagnostic. Parsed with php rather than jq: the CLI
# is pinned but jq is not, and this gate runs where lekalo does.
# php's own `$` variables are meant to reach php unexpanded, which is what SC2016
# warns about: the single quotes are the point, not an oversight.
# shellcheck disable=SC2016
diagnostics="$(
    "$LEKALO" validate --json 2>&1 | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        foreach (($d["diagnostics"] ?? []) as $x) {
            printf("%s|%s|%s\n", $x["code"] ?? "", $x["message_id"] ?? "", $x["source"]["path"] ?? "");
        }
    ' || true
)"

owned_by() {
    printf '%s\n' "$diagnostics" \
        | awk -F'|' -v id="$1" '$3 ~ ("^lekalo/modules/" id "(/|$)") { print }'
}

printf '\n=== every module on its own ===\n'

failures=0
modules=0

for dir in lekalo/modules/*/; do
    [ -d "$dir" ] || continue
    id="$(basename "$dir")"
    modules=$((modules + 1))

    # The exit code is discarded: it is the whole project's, not this module's
    # (property 1). What is used is the marker line — proof this run reported on
    # this module rather than refusing it (property 3).
    scoped="$( "$LEKALO" validate --module "$id" 2>&1 )" || true
    own="$(owned_by "$id")"

    if [ -n "$own" ]; then
        printf 'FAIL  %-14s %s diagnostic(s)\n' "$id" "$(printf '%s\n' "$own" | grep -c .)"
        printf '%s\n' "$own" | awk -F'|' '{ printf "      %s %s %s\n", $1, $2, $3 }'
        failures=$((failures + 1))
    elif [ "$whole_status" != 0 ]; then
        # The project is red somewhere else; this module owns none of it. Say `ok`,
        # not `refused`: the marker line is only printed on a successful run, so
        # demanding it here would blame all twenty-one modules for one bad slice.
        printf 'ok    %s\n' "$id"
    elif printf '%s' "$scoped" | grep -qF "(module ${id})"; then
        printf 'ok    %s\n' "$id"
    else
        # A green project where a module still gets no scoped report is an anomaly
        # worth failing on rather than a module worth calling clean (property 3).
        printf 'FAIL  %-14s the model reported nothing for this module\n' "$id"
        printf '%s\n' "$scoped" | grep ' error ' | head -2 | sed 's/^/      /'
        failures=$((failures + 1))
    fi
done

# A diagnostic that belongs to no module is still a diagnostic.
unattributed="$(printf '%s\n' "$diagnostics" | awk -F'|' '$3 == "" || $3 !~ /^lekalo\/modules\// { print }')"

if [ -n "$unattributed" ]; then
    printf '\n      not owned by any module:\n'
    printf '%s\n' "$unattributed" | awk -F'|' '{ printf "      %s %s %s\n", $1, $2, $3 }'
fi

printf '\n'

if [ "$failures" != 0 ]; then
    echo "lekalo-modules: $failures of $modules module(s) red" >&2
    exit 1
fi

if [ "$whole_status" != 0 ]; then
    echo "lekalo-modules: every module is clean, but the whole project is not (see above)" >&2
    exit 1
fi

echo "lekalo-modules: $modules modules validated on their own, whole project valid"
