#!/bin/bash
#
# =============================================================================
# kernel-size.sh — the kernel's measured shape, on demand.
# =============================================================================
# What this tree IS, not what a plan says it is: bytes and file counts for the
# kernel slice's two PHP trees (`src/Modules/Core`, `src/Modules/Database`),
# broken down by layer, plus the interface/concrete split a contract kernel is
# measured against.
#
# It measures and never gates — exit 0 whatever the numbers say. The gate is
# `tests/Architecture/KernelShapeTest` (the allow-list of approved concrete
# classes); this script is the evidence that test, `docs/kernel-shape.md` and
# the implementation plan all quote, so they cannot drift into three different
# stories.
#
# Two lines carry the whole movement:
#
#   total:    what the tree measures now
#   baseline: what it measured on 2026-10-15, before the refactor began
#
# The baseline is a CONSTANT written down before the first change, deliberately
# not recomputed — a baseline that tracks the tree measures nothing. `delta:`
# appears only when the two differ, which is the answer to "did it shrink?".
#
# The 573 KB figure that started this work never had a scope: it was orientation,
# not a target. This script is what replaces it — a number with a definition.
#
# Usage:
#   bin/build/kernel-size.sh      (or: make kernel-size)
#
# No dependencies beyond bash + find + awk (the same floor the other build scripts
# holds), because what measures the kernel must run where the kernel runs.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CORE="$ROOT/src/Modules/Core"
DATABASE="$ROOT/src/Modules/Database"

# Recorded before the refactor: `src/Modules/Core` + `src/Modules/Database`,
# every `*.php`, summed with `find -printf '%s'`. Types are Core's own split —
# Database ships two concrete classes and no interfaces, and folding that into
# one number would hide which tree moved.
BASELINE_BYTES=338192
BASELINE_FILES=79
BASELINE_INTERFACES=10
BASELINE_CONCRETE=67

# Declaration patterns, read as the first real keyword on a line — prose in a
# docblock cannot open with `interface ` because a docblock line opens with `*`.
# `interface Foo` and `final readonly class Foo` are the two poles; the modifier
# groups cover what this tree actually declares.
IFACE_RE='^[[:space:]]*interface[[:space:]]'
CONCRETE_RE='^[[:space:]]*(final[[:space:]]+|abstract[[:space:]]+)?(readonly[[:space:]]+)?(class|trait|enum)[[:space:]]'

# Byte sum and file count for one tree. `-printf` is find, not shell: `wc -c`
# over a glob would count bytes only after the shell expanded it, and 79 files
# is already more than one argv wants.
bytes_of() {
    find "$1" -name '*.php' -printf '%s\n' 2>/dev/null | awk '{ s += $1 } END { print s + 0 }'
}

files_of() {
    find "$1" -name '*.php' -printf '.' 2>/dev/null | wc -c
}

# Count files whose text matches $2 under $1. `grep -c` exits 1 on zero matches,
# which `set -e` would treat as fatal mid-loop — hence `|| true`, keeping the
# printed 0 and dropping only the status.
count_matching() {
    local dir="$1" pattern="$2" n=0 f
    while IFS= read -r -d '' f; do
        if grep -qE "$pattern" "$f" 2>/dev/null; then
            n=$((n + 1))
        fi
    done < <(find "$dir" -name '*.php' -print0 2>/dev/null)
    printf '%s\n' "$n"
}

for dir in "$CORE" "$DATABASE"; do
    if [ ! -d "$dir" ]; then
        echo "kernel-size: missing kernel tree $dir" >&2
        exit 1
    fi
done

# Layers are whatever the tree's own top level is — a sixth directory added
# tomorrow reports itself rather than silently falling outside the breakdown
# (which would make the layer lines stop summing to `total:`).
echo "Layers under src/Modules/Core:"
layer_total_bytes=0
layer_total_files=0
while IFS= read -r layer; do
    name="$(basename "$layer")"
    b="$(bytes_of "$layer")"
    f="$(files_of "$layer")"
    printf '  layer %-15s: %s B / %s files\n' "$name" "$b" "$f"
    layer_total_bytes=$((layer_total_bytes + b))
    layer_total_files=$((layer_total_files + f))
done < <(find "$CORE" -mindepth 1 -maxdepth 1 -type d | sort)

# A `*.php` sitting directly in Core belongs to no layer; if one appears the
# breakdown above stops adding up, so it gets named rather than absorbed.
root_files="$(find "$CORE" -mindepth 1 -maxdepth 1 -name '*.php' -printf '.' 2>/dev/null | wc -c)"
if [ "$root_files" -gt 0 ]; then
    root_bytes="$(find "$CORE" -mindepth 1 -maxdepth 1 -name '*.php' -printf '%s\n' | awk '{ s += $1 } END { print s + 0 }')"
    printf '  layer %-15s: %s B / %s files\n' "(root)" "$root_bytes" "$root_files"
    layer_total_bytes=$((layer_total_bytes + root_bytes))
    layer_total_files=$((layer_total_files + root_files))
fi

db_bytes="$(bytes_of "$DATABASE")"
db_files="$(files_of "$DATABASE")"
printf '  layer %-15s: %s B / %s files\n' "Database" "$db_bytes" "$db_files"

core_bytes="$(bytes_of "$CORE")"
core_files="$(files_of "$CORE")"

# The split the contract kernel is judged on. Reported per tree because the
# plan's baseline (10 interface / 67 concrete) is Core's, and a merged 69 would
# read as drift when nothing moved.
echo "Types:"
printf '  core     : %s interface, %s concrete\n' \
    "$(count_matching "$CORE" "$IFACE_RE")" \
    "$(count_matching "$CORE" "$CONCRETE_RE")"
printf '  database : %s interface, %s concrete\n' \
    "$(count_matching "$DATABASE" "$IFACE_RE")" \
    "$(count_matching "$DATABASE" "$CONCRETE_RE")"

total_bytes=$((core_bytes + db_bytes))
total_files=$((core_files + db_files))

# Core's layer sum vs Core's own sum: they must agree, and a mismatch means the
# walk missed something rather than that the tree shrank. Database is printed
# alongside the layers but is walked separately, so it is NOT in this comparison
# — folding it in made the very first run report a phantom 11 469 B gap.
if [ "$layer_total_bytes" -ne "$core_bytes" ] || [ "$layer_total_files" -ne "$core_files" ]; then
    printf '  warning   : Core layer breakdown (%s B / %s) disagrees with Core itself (%s B / %s)\n' \
        "$layer_total_bytes" "$layer_total_files" "$core_bytes" "$core_files" >&2
fi

printf 'total: %s B / %s files\n' "$total_bytes" "$total_files"

delta=$((total_bytes - BASELINE_BYTES))
if [ "$delta" -ne 0 ]; then
    if [ "$delta" -lt 0 ]; then
        printf 'delta: %s B vs baseline\n' "$delta"
    else
        printf 'delta: +%s B vs baseline\n' "$delta"
    fi
fi

printf 'baseline: %s B / %s files\n' "$BASELINE_BYTES" "$BASELINE_FILES"
printf 'baseline types: %s interface, %s concrete (Core)\n' \
    "$BASELINE_INTERFACES" "$BASELINE_CONCRETE"

exit 0
