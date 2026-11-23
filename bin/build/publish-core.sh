#!/bin/bash
#
# =============================================================================
# publish-core.sh — replace a generated repository with a fresh export.
# =============================================================================
# `responsive-sk/nativa-core` (public) and `responsive-sk/nativa-resp` (private)
# are generated, and this is the only writer. The sequence is deliberate:
#
#   1. refuse a dirty source tree, because the export carries the committed
#      profile and HEAD is what gets published;
#   2. run `export-core.sh --check` (structure, lint, composer validate and the
#      exported PHPUnit suite). A failed check publishes nothing;
#   3. export into a temporary directory;
#   4. create the repository when it does not exist, otherwise clone it and
#      replace every tracked file except .git;
#   5. commit only when the tree differs, with a message naming the source
#      commit, and push.
#
# A hand edit in the published repository is therefore overwritten on the next
# publish, and its history still says which source commit produced it.
#
# Usage:
#   bin/build/publish-core.sh --dry-run   # export + check, print, no network
#   bin/build/publish-core.sh --yes       # create or replace, then push
#
#   bin/build/publish-core.sh --profile bin/export/resp --yes
#   REPO=owner/name BRANCH=master bin/build/publish-core.sh --yes
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROFILE="bin/export/core"
BRANCH="${BRANCH:-master}"
DRY_RUN=false
CONFIRMED=false
CLEANUP=""
LABEL="publish-core"

usage() {
    awk 'NR>1 && /^set -euo pipefail$/{exit} NR>1{print}' "$0" | sed 's/^# \?//'
}

fail() {
    echo "$LABEL: $1" >&2
    exit "${2:-1}"
}

step() {
    echo "==> $*"
}

cleanup() {
    # An `if`, not `[ -n ] && rm`: a trap that ends on a false test returns 1,
    # and bash makes the EXIT trap's status the script's status.
    if [ -n "$CLEANUP" ]; then
        rm -rf "$CLEANUP"
    fi
}
trap cleanup EXIT

while [ $# -gt 0 ]; do
    case "$1" in
        --profile) PROFILE="${2:-}"; [ -n "$PROFILE" ] || fail "--profile needs a directory"; shift ;;
        --profile=*) PROFILE="${1#*=}" ;;
        --dry-run) DRY_RUN=true ;;
        --yes) CONFIRMED=true ;;
        --help|-h) usage; exit 0 ;;
        *) fail "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

PROFILE="${PROFILE%/}"

case "$PROFILE" in
    /*|..|../*|*/../*|*/..) fail "--profile must be a repo-relative directory, got: $PROFILE" 2 ;;
    "") fail "--profile needs a directory" 2 ;;
esac

if [ "$DRY_RUN" != true ] && [ "$CONFIRMED" != true ]; then
    fail "a real publish needs --yes (or use --dry-run to see it)" 2
fi

command -v gh >/dev/null 2>&1 || fail "gh is not on PATH" 2

COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
SHORT="$(git -C "$ROOT" rev-parse --short HEAD)"

if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
    fail "the source tree is dirty; commit or stash before publishing (the export reads HEAD)" 2
fi

PROFILE_JSON="$(git -C "$ROOT" show "HEAD:$PROFILE/profile.json" 2>/dev/null)" \
    || fail "HEAD does not carry $PROFILE/profile.json" 2

profile_field() {
    printf '%s' "$PROFILE_JSON" | php -r '
        $profile = json_decode((string) stream_get_contents(STDIN), true);
        $path = explode(".", $argv[1]);

        foreach ($path as $key) {
            if (!is_array($profile) || !array_key_exists($key, $profile)) {
                fwrite(STDERR, "missing profile key: " . $argv[1] . PHP_EOL);
                exit(1);
            }

            $profile = $profile[$key];
        }

        echo (string) $profile;
    ' "$1"
}

SLUG="$(profile_field slug)"
REPO="${REPO:-$(profile_field repository)}"
VISIBILITY="$(profile_field visibility)"
DESCRIPTION="$(profile_field description)"

LABEL="publish-$SLUG"

case "$VISIBILITY" in
    public|private) ;;
    *) fail "visibility must be public or private, got: $VISIBILITY" 2 ;;
esac

[ -n "$REPO" ] || fail "the profile has no repository" 2
[ -n "$DESCRIPTION" ] || fail "the profile has no description" 2

step "Checking the $SLUG export from $SHORT"
"$ROOT/bin/build/export-core.sh" --check --profile "$PROFILE"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/nativa-$SLUG-publish.XXXXXX")"
CLEANUP="$WORK"
EXPORT="$WORK/export"

step "Exporting into $EXPORT"
"$ROOT/bin/build/export-core.sh" --to "$EXPORT" --profile "$PROFILE"

files="$(find "$EXPORT" -type f | wc -l | tr -d ' ')"
size="$(du -sh "$EXPORT" | cut -f1)"

if [ "$DRY_RUN" = true ]; then
    step "Dry run: nothing was published"
    echo "    profile:    $PROFILE ($SLUG)"
    echo "    repository: $REPO ($VISIBILITY, branch $BRANCH)"
    echo "    source:     $SHORT"
    echo "    tree:       $size, $files files"
    echo "    next:       bin/build/publish-core.sh --profile $PROFILE --yes"
    exit 0
fi

if gh repo view "$REPO" >/dev/null 2>&1; then
    step "Cloning $REPO"
    git clone --quiet --branch "$BRANCH" "https://github.com/$REPO.git" "$WORK/repo" 2>/dev/null \
        || { git clone --quiet "https://github.com/$REPO.git" "$WORK/repo"; git -C "$WORK/repo" checkout -B "$BRANCH"; }

    step "Replacing the tracked tree (the .git directory stays)"
    find "$WORK/repo" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
    cp -a "$EXPORT/." "$WORK/repo/"

    cd "$WORK/repo"

    if [ -z "$(git status --porcelain)" ]; then
        step "No change: $REPO already carries this export"
        exit 0
    fi
else
    step "Creating $REPO ($VISIBILITY)"
    gh repo create "$REPO" "--$VISIBILITY" --description "$DESCRIPTION" >/dev/null

    mkdir -p "$WORK/repo"
    cp -a "$EXPORT/." "$WORK/repo/"
    cd "$WORK/repo"
    git init --quiet -b "$BRANCH"
    git remote add origin "https://github.com/$REPO.git"
fi

git add -A

git commit --quiet -m "$(cat <<EOF
chore: export from nativa-php-core@$SHORT

Generated by bin/build/publish-core.sh in responsive-sk/nativa-php-core.
Source commit: $COMMIT
Profile: $PROFILE
EOF
)"

step "Pushing $SHORT to $REPO ($BRANCH)"
git push --quiet -u origin "$BRANCH"

step "Published"
echo "    repository: https://github.com/$REPO"
echo "    visibility: $VISIBILITY"
echo "    source:     $SHORT"
echo "    tree:       $size, $files files"
echo "    next:       gh run list -R $REPO --limit 1"
