#!/bin/bash
#
# =============================================================================
# sync-themes.sh — the theme catalogue, copied from its repository or verified.
# =============================================================================
# The five kernel themes (Boson, Erik, Griffe, Jednotka, Mono) are edited in
# `evan70/nativa-themes` under `modules/Themes/<Name>/`. This tree keeps a
# catalogue at the same path because `bin/cms module:add <Name>` installs from
# here — but the catalogue is a **copy**, never a second source. Without a gate
# the two drift the way a catalogue and an installed slice drift: someone fixes
# a theme here, `git status` stays clean, and the next copy overwrites it.
#
# Two modes:
#
#   ./bin/build/sync-themes.sh --check [--from <dir>]
#       Compare every theme file by SHA-256 against the theme repository.
#       Exit 0 only when the catalogue is identical; print one DIVERGED line
#       per differing path and exit 1 otherwise (`missing`, `changed`,
#       `only-here`, `theme-only-here`). Reads nothing else, writes nothing.
#
#   ./bin/build/sync-themes.sh --write [--from <dir>]
#       Replace `modules/Themes/<Name>/` with the repository's copy: delete the
#       directory, then copy its files back. Deleting first is deliberate — a
#       file removed upstream must not survive here as a stale extra.
#
# Theme repository location: `--from <dir>` for an explicit checkout (CI passes
# its own), otherwise the repository's HEAD, cloned into the gitignored
# `storage/cache/nativa-themes` and fast-forwarded on each run.
#
# The default used to be a sibling clone (`../nativa-themes`), and that is how a
# stale copy passed here and failed in CI: Boson's critical CSS was synced from
# a checkout that was behind, `make themes-check` reported identical, and the
# pushed commit went red against the fresh clone. A gate that compares against
# whatever happens to sit next to it measures the neighbour, not the repository.
# Only the explicit `--from` still trusts a path, because the caller named it.
#
# `git` is needed for the fetch path; `--from` keeps the coreutils-only path
# CI uses. `.git` inside a theme directory is skipped: the catalogue is source,
# not a nested repository.
#
# No dependencies beyond bash + coreutils, same as `bin/cms` and
# `extract-kernel.sh`: the tool that guards the tree runs where `vendor/` may
# not exist.
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
THEMES_ROOT=""
MODE=""
THEMES_URL="https://github.com/evan70/nativa-themes"
THEMES_CACHE="$ROOT/storage/cache/nativa-themes"

# The catalogue is an allow-list, not a glob: a new theme has to be named here
# (and in the contract in docs/themes.md) before this script will copy it. A
# theme repository that grows a sixth directory therefore fails `--check`
# loudly instead of being half-synced.
THEMES=(
    "Boson"
    "Erik"
    "Griffe"
    "Jednotka"
    "Mono"
)

usage() {
    awk 'NR>1 && /^set -euo pipefail$/{exit} NR>1{print}' "$0" | sed 's/^# \?//'
}

fail() {
    echo "sync-themes: $*" >&2
    exit "${2:-1}"
}

hash_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
    else
        fail "neither sha256sum nor shasum is available"
    fi
}

# The checkout the gate compares against, and the commit it is at.
#
# `--from` wins outright: CI hands its own checkout in, and a contributor
# comparing against a local branch can too. Without it the repository's HEAD is
# fetched, so both sides of a code review compare the same bytes.
fetch_source() {
    if [ -d "$THEMES_ROOT/.git" ]; then
        if git -C "$THEMES_ROOT" fetch --quiet --depth 1 origin >/dev/null 2>&1; then
            git -C "$THEMES_ROOT" reset --quiet --hard FETCH_HEAD >/dev/null 2>&1 \
                || fail "could not move the cached theme clone to the fetched commit"
        else
            printf 'sync-themes: could not reach %s; comparing against the cached clone\n' "$THEMES_URL" >&2
        fi

        return
    fi

    mkdir -p "$(dirname "$THEMES_ROOT")"
    git clone --quiet --depth 1 "$THEMES_URL" "$THEMES_ROOT" >&2 \
        || fail "cannot fetch $THEMES_URL and no cached clone exists (pass --from <dir>)" 2
}

# `@<commit>` when the source is a git checkout, so the line itself names what
# was compared: a report of a red gate carries the upstream commit it saw.
source_revision() {
    if [ -d "$THEMES_ROOT/.git" ]; then
        local sha
        sha="$(git -C "$THEMES_ROOT" rev-parse --short HEAD 2>/dev/null)" \
            && printf '@%s' "$sha"
    fi
}

resolve_source() {
    if [ -z "$THEMES_ROOT" ]; then
        THEMES_ROOT="$THEMES_CACHE"
        fetch_source
    fi
    [ -d "$THEMES_ROOT/modules/Themes" ] \
        || fail "no theme repository at $THEMES_ROOT (expected modules/Themes/; pass --from)" 2
    # Copying a tree onto itself would delete the catalogue before reading it.
    local src dest
    src="$(cd "$THEMES_ROOT/modules/Themes" && pwd -P)"
    dest="$(cd "$ROOT/modules/Themes" 2>/dev/null && pwd -P || echo "$ROOT/modules/Themes")"
    [ "$src" != "$dest" ] || fail "--from resolves to this tree's own catalogue: $src"
}

# Every file under a theme directory, relative to it, in a stable order.
theme_files() {
    local dir="$1"
    [ -d "$dir" ] || return 0
    (cd "$dir" && find . -type f ! -path './.git/*' -printf '%P\n' | LC_ALL=C sort)
}

check_theme() {
    local name="$1"
    local src="$THEMES_ROOT/modules/Themes/$name"
    local dest="$ROOT/modules/Themes/$name"
    local rel src_hash dest_hash
    local -A src_files=() dest_files=()
    local diverged=0 identical=0

    if [ ! -d "$src" ]; then
        echo "DIVERGED theme=$name path=. state=theme-missing-in-source" >&2
        return 1
    fi

    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        src_files["$rel"]=1
        if [ ! -f "$dest/$rel" ]; then
            echo "DIVERGED theme=$name path=$rel state=missing" >&2
            diverged=$((diverged + 1))
            continue
        fi
        src_hash="$(hash_file "$src/$rel")"
        dest_hash="$(hash_file "$dest/$rel")"
        if [ "$src_hash" != "$dest_hash" ]; then
            echo "DIVERGED theme=$name path=$rel state=changed" >&2
            diverged=$((diverged + 1))
        else
            identical=$((identical + 1))
        fi
    done < <(theme_files "$src")

    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        if [ -z "${src_files[$rel]:-}" ]; then
            echo "DIVERGED theme=$name path=$rel state=only-here" >&2
            diverged=$((diverged + 1))
        fi
    done < <(theme_files "$dest")

    printf '%s %s\n' "$identical" "$diverged"
}

cmd_check() {
    resolve_source
    local total_identical=0 total_diverged=0
    local name counts identical diverged
    local -A known=()

    for name in "${THEMES[@]}"; do
        known["$name"]=1
    done

    # A directory the allow-list does not name is drift too: either the theme
    # repository grew a theme the contract has not accepted, or this catalogue
    # has one the repository never had.
    local extra
    for extra in "$THEMES_ROOT"/modules/Themes/*/; do
        [ -d "$extra" ] || continue
        name="$(basename "$extra")"
        if [ -z "${known[$name]:-}" ]; then
            echo "DIVERGED theme=$name path=. state=theme-not-in-allow-list" >&2
            total_diverged=$((total_diverged + 1))
        fi
    done
    for extra in "$ROOT"/modules/Themes/*/; do
        [ -d "$extra" ] || continue
        name="$(basename "$extra")"
        if [ -z "${known[$name]:-}" ]; then
            echo "DIVERGED theme=$name path=. state=theme-only-here" >&2
            total_diverged=$((total_diverged + 1))
        fi
    done

    for name in "${THEMES[@]}"; do
        counts="$(check_theme "$name")" || true
        if [ -n "$counts" ]; then
            identical="${counts%% *}"
            diverged="${counts##* }"
            total_identical=$((total_identical + identical))
            total_diverged=$((total_diverged + diverged))
        else
            total_diverged=$((total_diverged + 1))
        fi
    done

    echo "sync-themes --check: identical=$total_identical diverged=$total_diverged (source: $THEMES_ROOT$(source_revision))"
    if [ "$total_diverged" -gt 0 ]; then
        echo "sync-themes: the catalogue is not the repository's copy — run 'make themes-sync' or revert the edit." >&2
        exit 1
    fi
}

cmd_write() {
    resolve_source
    local name src dest files
    for name in "${THEMES[@]}"; do
        src="$THEMES_ROOT/modules/Themes/$name"
        dest="$ROOT/modules/Themes/$name"
        [ -d "$src" ] || fail "theme $name is missing in $THEMES_ROOT/modules/Themes"
        rm -rf "${dest:?}"
        mkdir -p "$dest"
        files=0
        while IFS= read -r rel; do
            [ -n "$rel" ] || continue
            mkdir -p "$dest/$(dirname "$rel")"
            cp "$src/$rel" "$dest/$rel"
            files=$((files + 1))
        done < <(theme_files "$src")
        echo "copied theme=$name files=$files"
    done
    echo "sync-themes --write: themes=${THEMES[*]} source=$THEMES_ROOT"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --check) MODE="check"; shift ;;
        --write) MODE="write"; shift ;;
        --from) THEMES_ROOT="${2:-}"; [ -n "$THEMES_ROOT" ] || fail "--from needs a directory"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) fail "unknown argument: $1 (see --help)" ;;
    esac
done

case "$MODE" in
    check) cmd_check ;;
    write) cmd_write ;;
    *) fail "nothing to do (see --help)" ;;
esac
