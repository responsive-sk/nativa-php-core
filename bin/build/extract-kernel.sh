#!/bin/bash
#
# =============================================================================
# extract-kernel.sh — the upstream kernel slice, materialized or guarded.
# =============================================================================
# The kernel's source of truth is THIS repository: the work happens here, so
# this script is a one-way import aid, not a re-derivation. It cannot
# regenerate our tree from nativa-minimal — ten boots of deliberate divergence
# (ports, cuts, the kernel theme, the catalog, the harness) stand between the
# upstream slice and what is committed here.
#
# Two modes:
#
#   ./bin/build/extract-kernel.sh --to <dir>
#       Materialize the UPSTREAM slice into <dir> (must not exist or be
#       empty): the verbatim starting point a fresh import would begin from.
#       What this repo owns on top (kernel theme, tests/, docs/, bin/build/,
#       the divergence record) is never emitted — the script says so.
#
#   ./bin/build/extract-kernel.sh --check
#       Re-derive the slice into a temp dir and diff it against this tree,
#       allowing exactly the differences recorded in
#       bin/build/kernel-divergence.txt. Exit 0 when the only differences are
#       recorded ones; exit 1 on any unrecorded drift — a hand-edit outside
#       the record, or an upstream change nobody reviewed. (Task 2.1's
#       verification, adapted to a tree that is the source of truth: "exit 1
#       after a hand-edit" holds for every path the record does not name.)
#
# Upstream location: --minimal-root <dir>, default ../nativa-minimal next to
# this checkout (overridable, e.g. for CI checkouts elsewhere).
#
# No dependencies beyond bash + coreutils. Like bin/cms, what manages the
# kernel must run where the kernel's own vendor/ may not exist.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIVERGENCE="$ROOT/bin/build/kernel-divergence.txt"
MINIMAL_ROOT=""

# --- Slice definition: paths relative to either root --------------------------
# Directories are copied/compared recursively; files singly. What is NOT
# listed here is owned by one side outright: src/Themes/* (except the
# upstream-identical ThemeModule.php), tests/, docs/, modules/ contents,
# bin/build/, lekalo/, storage/ runtime files.
SLICE_DIRS=(
    "src/Modules/Core"
    "src/Modules/Database"
    "config"
)
SLICE_FILES=(
    "src/init.php"
    "src/Themes/ThemeModule.php"
    "public/index.php"
    "bin/cms"
    "composer.json"
    "composer.lock"
    "phpunit.xml"
    "phpstan.neon"
    "Makefile"
    ".gitignore"
    ".env.example"
    "README.md"
)
# The storage skeleton is structural (dirs + .gitkeep), never content:
# upstream carries runtime junk, we carry the skeleton.
STORAGE_DIRS=(
    "data"
    "logs"
    "cache"
    "sessions"
    "uploads"
)

usage() {
    awk 'NR>1 && /^set -euo pipefail$/{exit} NR>1{print}' "$0" | sed 's/^# \?//'
}

fail() {
    echo "extract-kernel: $*" >&2
    exit "${2:-1}"
}

# --- Divergence record ---------------------------------------------------------
declare -A DIVERGED=() OWNED=() DROPPED=()

load_divergence() {
    [ -f "$DIVERGENCE" ] || fail "divergence record missing: $DIVERGENCE"
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%%#*}"
        line="$(echo "$line" | awk '{$1=$1};1')"
        [ -z "$line" ] && continue
        kind="${line%% *}"
        path="${line#* }"
        case "$kind" in
            DIVERGED) DIVERGED["$path"]=1 ;;
            OWNED)    OWNED["$path"]=1 ;;
            DROPPED)  DROPPED["$path"]=1 ;;
            *) fail "bad line in $DIVERGENCE: $line" ;;
        esac
    done < "$DIVERGENCE"
}

# OWNED matches a file exactly or under an owned directory.
is_owned() {
    local path="$1"
    [ -n "${OWNED[$path]:-}" ] && return 0
    local dir
    dir="$(dirname "$path")"
    while [ "$dir" != "." ] && [ "$dir" != "/" ]; do
        [ -n "${OWNED[$dir]:-}" ] && return 0
        dir="$(dirname "$dir")"
    done
    return 1
}

resolve_minimal() {
    if [ -z "$MINIMAL_ROOT" ]; then
        MINIMAL_ROOT="$ROOT/../nativa-minimal"
    fi
    [ -d "$MINIMAL_ROOT/src/Modules/Core" ] \
        || fail "minimal root has no src/Modules/Core: $MINIMAL_ROOT (pass --minimal-root)" 2
}

materialize_slice() {
    local src="$1" dest="$2"
    local p
    for p in "${SLICE_DIRS[@]}"; do
        [ -d "$src/$p" ] || fail "upstream slice missing dir: $p"
        mkdir -p "$dest/$p"
        cp -r "$src/$p/." "$dest/$p/"
    done
    for p in "${SLICE_FILES[@]}"; do
        # phpstan.neon / Makefile may postdate the upstream checkout on old
        # clones; the slice tolerates their absence upstream but never here
        # (they are compared when present on both sides).
        if [ ! -f "$src/$p" ]; then
            echo "extract-kernel: note: upstream lacks $p — skipping in slice" >&2
            continue
        fi
        mkdir -p "$dest/$(dirname "$p")"
        cp "$src/$p" "$dest/$p"
    done
    for d in "${STORAGE_DIRS[@]}"; do
        mkdir -p "$dest/storage/$d"
        : > "$dest/storage/$d/.gitkeep"
    done
    mkdir -p "$dest/modules"
}

cmd_to() {
    local dest="$1"
    resolve_minimal
    if [ -e "$dest" ] && [ -n "$(ls -A "$dest" 2>/dev/null)" ]; then
        fail "refusing to write into non-empty dir: $dest"
    fi
    mkdir -p "$dest"
    materialize_slice "$MINIMAL_ROOT" "$dest"
    echo "Upstream kernel slice materialized in $dest."
    echo "Not emitted (owned by this repo, see kernel-divergence.txt): src/Themes/kernel, tests/, docs/, bin/build/, modules/ contents."
}

cmd_check() {
    resolve_minimal
    load_divergence
    local stage failures=0
    stage="$(mktemp -d)"
    # shellcheck disable=SC2064
    trap "rm -rf '$stage'" EXIT
    materialize_slice "$MINIMAL_ROOT" "$stage"

    local recorded=0 identical=0
    local f rel

    # 1. Every slice file must exist here, identical unless DIVERGED.
    while IFS= read -r -d '' rel; do
        f="$stage/$rel"
        if [ ! -e "$ROOT/$rel" ]; then
            if [ -n "${DROPPED[$rel]:-}" ]; then
                recorded=$((recorded + 1))
            else
                echo "DRIFT: upstream slice has $rel, missing here (not DROPPED)" >&2
                failures=$((failures + 1))
            fi
        elif [ -n "${DIVERGED[$rel]:-}" ]; then
            recorded=$((recorded + 1))
        elif cmp -s "$f" "$ROOT/$rel"; then
            identical=$((identical + 1))
        else
            echo "DRIFT: $rel differs from upstream (not DIVERGED):" >&2
            diff -u "$f" "$ROOT/$rel" 2>/dev/null | head -10 >&2 || true
            failures=$((failures + 1))
        fi
    done < <(cd "$stage" && find . -mindepth 1 -type f -printf '%P\0')

    # 2. Every file here under the compared scope must be upstream's or OWNED.
    # Drift lines collect into a file (a subshell `exit` would abort the walk
    # and undercount); the count is reconciled after the loop.
    local drift="$stage/drift.log"
    : > "$drift"
    {
        for p in "${SLICE_DIRS[@]}" "${SLICE_FILES[@]}"; do
            [ -e "$ROOT/$p" ] && echo "$p"
        done
    } | while IFS= read -r p; do
        if [ -f "$ROOT/$p" ]; then
            rel="$p"
            if [ ! -e "$stage/$rel" ] && ! is_owned "$rel"; then
                echo "DRIFT: $rel exists only here (not OWNED)" >> "$drift"
            fi
        else
            while IFS= read -r -d '' f; do
                rel="${f#$ROOT/}"
                if [ ! -e "$stage/$rel" ] && ! is_owned "$rel"; then
                    echo "DRIFT: $rel exists only here (not OWNED)" >> "$drift"
                fi
            done < <(find "$ROOT/$p" -type f -print0)
        fi
    done
    if [ -s "$drift" ]; then
        cat "$drift" >&2
        failures=$((failures + $(wc -l < "$drift")))
    fi

    # 3. Storage skeleton: dirs + .gitkeep, identical. Runtime files ignored.
    for d in "${STORAGE_DIRS[@]}"; do
        if [ ! -d "$ROOT/storage/$d" ]; then
            echo "DRIFT: storage/$d skeleton dir missing" >&2
            failures=$((failures + 1))
        elif [ ! -f "$ROOT/storage/$d/.gitkeep" ]; then
            echo "DRIFT: storage/$d/.gitkeep missing" >&2
            failures=$((failures + 1))
        elif ! cmp -s "$stage/storage/$d/.gitkeep" "$ROOT/storage/$d/.gitkeep"; then
            echo "DRIFT: storage/$d/.gitkeep differs" >&2
            failures=$((failures + 1))
        fi
    done
    [ -d "$ROOT/modules" ] || { echo "DRIFT: modules/ catalog dir missing" >&2; failures=$((failures + 1)); }

    if [ "$failures" -gt 0 ]; then
        echo "extract-kernel --check: $failures unrecorded difference(s). Record them in bin/build/kernel-divergence.txt or revert." >&2
        exit 1
    fi
    echo "extract-kernel --check: OK ($identical identical, $recorded recorded divergences)."
}

MODE="" TARGET=""
while [ $# -gt 0 ]; do
    case "$1" in
        --to) TARGET="${2:-}"; [ -n "$TARGET" ] || fail "--to needs a directory"; MODE="to"; shift 2 ;;
        --check) MODE="check"; shift ;;
        --minimal-root) MINIMAL_ROOT="${2:-}"; [ -n "$MINIMAL_ROOT" ] || fail "--minimal-root needs a directory"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) fail "unknown argument: $1 (see --help)" ;;
    esac
done

case "$MODE" in
    to) cmd_to "$TARGET" ;;
    check) cmd_check ;;
    *) fail "nothing to do (see --help)" ;;
esac
