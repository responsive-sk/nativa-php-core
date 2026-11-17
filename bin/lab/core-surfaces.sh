#!/usr/bin/env bash
#
# core-surfaces.sh — build the clean core once per dependency surface, then
# measure what that surface actually answers.
#
# The four surfaces are the ones the minimal tree named (see
# `.ai-factory/plans/fix-nativa-core-scope.md`): the theme vote (`Settings`),
# the copy (`Locales`), the content links (`Blog`, `Pages`) and the panel
# (`Users`, `Cardboard`). A module is code, so no `.env` can switch one on:
# each row is a fresh export with Nativa only, the surface's closure installed
# with `module:add`, and `bin/lab/core.env` copied in for runtime determinism.
#
# Usage:
#   bin/lab/core-surfaces.sh                 # rows: none theme copy content admin
#   bin/lab/core-surfaces.sh theme admin     # only those rows
#
# Environment:
#   CORE_TREE  published nativa-core checkout (default: ../nativa-core)
#   LAB_ROOT   where rows are built            (default: /tmp/core-lab)
#   LAB_THEME  theme written into the profile  (default: nativa)
#
# Output: one TSV row per surface — HTTP status of the public paths, the theme
# marker, the card count and whether the Slovak copy got through. A row is a
# measurement, not a test: nothing here asserts, it prints what the tree does.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
CORE_TREE=${CORE_TREE:-$ROOT/../nativa-core}
LAB_ROOT=${LAB_ROOT:-/tmp/core-lab}
LAB_THEME=${LAB_THEME:-nativa}

PATHS=(/ /blog /cookies /privacy /en/ /mark /docs)

surface_modules() {
    case "$1" in
        none) : ;;
        theme) printf '%s\n' Settings ;;
        copy) printf '%s\n' Settings Locales ;;
        content) printf '%s\n' Blog Pages ;;
        admin) printf '%s\n' Users Cardboard ;;
        *) return 1 ;;
    esac
}

surfaces=("$@")
if [ ${#surfaces[@]} -eq 0 ]; then
    surfaces=(none theme copy content admin)
fi
for s in "${surfaces[@]}"; do
    surface_modules "$s" >/dev/null || {
        echo "unknown surface '$s' (none|theme|copy|content|admin)" >&2
        exit 2
    }
done

mkdir -p "$LAB_ROOT"
pids=()
cleanup() {
    local p
    for p in "${pids[@]:-}"; do kill "$p" 2>/dev/null || true; done
}
trap cleanup EXIT

installed() {
    local row=$1 name=$2
    find "$row/src/Modules" -maxdepth 2 -type d -name "$name" -print -quit | grep -q .
}

# `module:show` prints the transitive closure on one line; a leaf prints
# `(none — a leaf)`. The lab reads it instead of hardcoding a mapping that
# a catalogue change would silently invalidate.
closure_of() {
    local row=$1 name=$2 line
    line=$( cd "$row" && php bin/cms module:show "$name" 2>/dev/null \
        | sed -n 's/^ *closure: *//p' | head -1 )
    case "$line" in
        ''|'(none'*) return 0 ;;
    esac
    printf '%s\n' "$line" | tr ',' '\n' | sed 's/^ *//; s/ *$//'
}

# `module:add` refuses an incomplete closure, so add the union in passes until
# a pass makes no progress. The catalogue slices are all present in the row
# (they are data, not installs); what changes is what lands in `src/Modules`.
add_closure() {
    local row=$1
    shift
    local queue=("$@") seen=() m dep
    while [ ${#queue[@]} -gt 0 ]; do
        m=${queue[0]}
        queue=("${queue[@]:1}")
        case " ${seen[*]-} " in *" $m "*) continue ;; esac
        seen+=("$m")
        while IFS= read -r dep; do
            [ -n "$dep" ] && queue+=("$dep")
        done < <(closure_of "$row" "$m")
    done

    local pending=("${seen[@]}") next=() progress=1
    while [ "$progress" -eq 1 ] && [ ${#pending[@]} -gt 0 ]; do
        progress=0
        next=()
        for m in "${pending[@]}"; do
            if ( cd "$row" && php bin/cms module:add "$m" >/dev/null 2>&1 ); then
                progress=1
            else
                next+=("$m")
            fi
        done
        pending=("${next[@]}")
    done
    if [ ${#pending[@]} -gt 0 ]; then
        echo "  cannot install: ${pending[*]}" >&2
        return 1
    fi
}

build_row() {
    local name=$1
    shift
    local row=$LAB_ROOT/$name
    rm -rf "$row"
    mkdir -p "$row"

    git -C "$CORE_TREE" archive --format=tar HEAD | tar -x -C "$row"

    # The clean core: Nativa is the only theme, and no module is installed
    # beyond the kernel. Same pruning the scope plan measures with.
    find "$row/modules" -mindepth 1 -maxdepth 1 ! -name Themes -exec rm -rf {} +
    find "$row/modules/Themes" -mindepth 1 -maxdepth 1 ! -name Nativa -exec rm -rf {} +

    # Catalogue slices travel with the row: `module:add` needs the slice it is
    # asked for, and a closure pulls in modules the surface never names.
    local d
    for d in "$ROOT"/modules/*/; do
        [ -d "$d" ] || continue
        cp -a "$d" "$row/modules/"
    done

    # NOT a symlink: Composer resolves `$baseDir` from the autoloader's own
    # `__DIR__`, so a symlinked vendor points every `Modules\...` class back at
    # the source tree — the row would run the source's installed slices and
    # measure the wrong tree (the export gate documents the same trap).
    # Hardlinks keep the bytes cheap while each row keeps its own path; a
    # different filesystem (the default /tmp) cannot hardlink, so fall back to
    # a real copy rather than a symlink for the reason above.
    if ! cp -al "$ROOT/vendor" "$row/vendor" 2>/dev/null; then
        rm -rf "$row/vendor"
        cp -a "$ROOT/vendor" "$row/vendor"
    fi
    cp "$ROOT/bin/lab/core.env" "$row/.env"

    ( cd "$row" && php bin/cms module:add Nativa >/dev/null )
    add_closure "$row" "$@"
    ( cd "$row" && php bin/cms migrate >/dev/null )

    if installed "$row" Settings; then
        printf "<?php\nreturn ['theme' => '%s', 'site_name' => 'core lab %s'];\n" \
            "$LAB_THEME" "$name" >"$row/config/deploy.php"
        ( cd "$row" && php bin/cms settings:profile >/dev/null )
    fi
    if installed "$row" Users; then
        ( cd "$row" && php bin/cms user:operator >/dev/null )
    fi
}

measure_row() {
    local name=$1 port=$2
    local row=$LAB_ROOT/$name
    local tmp=$LAB_ROOT/$name.home.html

    ( cd "$row" && exec php -S "127.0.0.1:$port" -t public >"$LAB_ROOT/$name.log" 2>&1 ) &
    pids+=($!)

    local i code
    for i in $(seq 1 40); do
        code=$(curl -s -o /dev/null -m 2 -w '%{http_code}' "http://127.0.0.1:$port/" || true)
        [ "${code:-000}" != "000" ] && break
        sleep 0.25
    done

    local statuses=() p
    for p in "${PATHS[@]}"; do
        code=$(curl -s -o /dev/null -m 5 -w '%{http_code}' "http://127.0.0.1:$port$p" || true)
        statuses+=("${code:-000}")
    done
    curl -s -m 5 "http://127.0.0.1:$port/" >"$tmp" || true

    local theme cards nav copy files
    theme=$(grep -c 'na-header__brand' "$tmp" || true)
    cards=$(grep -o 'na-card' "$tmp" | wc -l || true)
    nav=$(grep -o 'na-nav__link' "$tmp" | wc -l || true)
    copy=$(grep -c 'Minimálny PHP' "$tmp" || true)
    files=$(find "$row" -type f | wc -l)

    printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$name" "$(IFS=,; echo "${statuses[*]}")" "$theme" "$cards${nav:+/$nav}" "$copy" "$files"
}

printf 'surface\t%s\tna-header\tcard/nav\tsk-copy\tfiles\n' \
    "$(IFS=,; echo "${PATHS[*]}")"

i=0
for s in "${surfaces[@]}"; do
    modules=()
    while IFS= read -r m; do [ -n "$m" ] && modules+=("$m"); done < <(surface_modules "$s")
    build_row "$s" "${modules[@]}"
    measure_row "$s" "$((8140 + i))"
    i=$((i + 1))
done
