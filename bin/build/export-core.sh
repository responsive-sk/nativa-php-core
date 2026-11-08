#!/bin/bash
#
# =============================================================================
# export-core.sh — assemble the nativa-core tree from a commit of this one.
# =============================================================================
# The public repository `responsive-sk/nativa-core` is GENERATED. This script is
# the generator, and this tree is the source of truth: a change to the export is
# made here, committed, and exported again.
#
# The bytes come from `git archive HEAD`, never from the working tree. That is
# the same rule `dist-check.sh` keeps for the deployment artifact, for the same
# reason: a tree assembled from one developer's checkout cannot be reproduced by
# anyone else, and an uncommitted edit would leak into a public repository.
#
# What the export drops is `bin/export/core/prune.txt` — one path and one
# reason per line. What replaces a dropped file is `bin/export/core/<same
# name>`: Makefile, phpunit.xml, .gitignore, README.md, AGENTS.md and the CI
# workflow. The profile travels INSIDE the archive, so editing it is a tracked
# change like any other.
#
# Before it reports success it asserts:
#   - every prune entry removed something (a moved path is a red run);
#   - the exported composer.json keeps the source `require` block, and the
#     lockfile's content-hash is recomputed for the new name;
#   - every profile target exists in the source Makefile;
#   - every `bin/...` path an exported recipe names exists in the export;
#   - every `make` call in the exported workflow is an exported target;
#   - every phpunit exclusion names a real test file, and every excluded file
#     is removed from the run;
#   - no exported surface names a pruned path.
#
# Usage:
#   bin/build/export-core.sh                 # → ../nativa-core
#   bin/build/export-core.sh --to <dir>      # → <dir> (must be empty)
#   bin/build/export-core.sh --check         # → temp dir, then lint + suite
#   bin/build/export-core.sh --ref <commit>  # export a commit (default HEAD)
#   bin/build/export-core.sh --force         # replace a non-empty --to dir
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROFILE="bin/export/core"
TARGET=""
REF="HEAD"
CHECK=false
FORCE=false
CLEANUP=""

usage() {
    awk 'NR>1 && /^set -euo pipefail$/{exit} NR>1{print}' "$0" | sed 's/^# \?//'
}

fail() {
    echo "export-core: $1" >&2
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
        --to) TARGET="${2:-}"; [ -n "$TARGET" ] || fail "--to needs a directory"; shift ;;
        --ref) REF="${2:-}"; [ -n "$REF" ] || fail "--ref needs a commit"; shift ;;
        --check) CHECK=true ;;
        --force) FORCE=true ;;
        --help|-h) usage; exit 0 ;;
        *) fail "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1 || fail "not a git work tree" 1
COMMIT="$(git -C "$ROOT" rev-parse "$REF^{commit}")" || fail "cannot resolve --ref=$REF to a commit" 1
SHORT="$(git -C "$ROOT" rev-parse --short "$COMMIT")"
COMMIT_DATE="$(git -C "$ROOT" show -s --format=%cI "$COMMIT")"

if [ "$CHECK" = true ]; then
    TARGET="$(mktemp -d "${TMPDIR:-/tmp}/nativa-core-check.XXXXXX")"
    CLEANUP="$TARGET"
    FORCE=true
elif [ -z "$TARGET" ]; then
    TARGET="$ROOT/../nativa-core"
fi

case "$TARGET" in
    /*) ;;
    *) TARGET="$ROOT/$TARGET" ;;
esac

if [ "$CHECK" != true ] && [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null)" ]; then
    if [ "$FORCE" != true ]; then
        fail "refusing to write into non-empty $TARGET (pass --force to replace it)" 2
    fi
    rm -rf "$TARGET"
fi

mkdir -p "$TARGET"

step "Exporting $REF ($SHORT) into $TARGET"
git -C "$ROOT" archive --format=tar "$COMMIT" | tar -x -C "$TARGET"

[ -d "$TARGET/$PROFILE" ] || fail "the exported archive carries no $PROFILE" 1

# The profile is read from the archive, not from the working tree: an
# uncommitted edit to it must be invisible here, like every other source file.
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/nativa-core-profile.XXXXXX")"
cp -a "$TARGET/$PROFILE/." "$STAGE/"
cp "$TARGET/toolchain.json" "$STAGE/toolchain.source.json"
cp "$TARGET/composer.json" "$STAGE/composer.source.json"
cp "$TARGET/phpunit.xml" "$STAGE/phpunit.source.xml"

step "Pruning what belongs to the source tree"
pruned=0
unmatched=()
while IFS= read -r line || [ -n "$line" ]; do
    path="${line%%#*}"
    path="$(printf '%s' "$path" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    [ -n "$path" ] || continue

    if [ -e "$TARGET/$path" ]; then
        rm -rf "$TARGET/$path"
        pruned=$((pruned + 1))
    else
        unmatched+=("$path")
    fi
done < "$STAGE/prune.txt"

if [ "${#unmatched[@]}" -gt 0 ]; then
    echo "--- prune entries that matched nothing ---" >&2
    printf '    %s\n' "${unmatched[@]}" >&2
    fail "a prune entry matched nothing; the path moved and the rule would prune nothing" 1
fi

echo "    $pruned path(s) pruned"

step "Installing the export profile"
mkdir -p "$TARGET/.github/workflows"
for file in Makefile .gitignore README.md AGENTS.md; do
    cp "$STAGE/$file" "$TARGET/$file"
done
cp "$STAGE/.github/workflows/ci.yml" "$TARGET/.github/workflows/ci.yml"

step "Rewriting the composer identity and its lock hash"
php -r '
    $target = $argv[1];
    $stage = $argv[2];

    $composer = json_decode((string) file_get_contents($target . "/composer.json"), true);
    $source = json_decode((string) file_get_contents($stage . "/composer.source.json"), true);
    $identity = json_decode((string) file_get_contents($stage . "/composer.identity.json"), true);

    if (!is_array($composer) || !is_array($source) || !is_array($identity)) {
        fwrite(STDERR, "export-core: composer.json, its source copy or the identity is not an object\n");
        exit(1);
    }

    $composer["name"] = $identity["name"];
    $composer["description"] = $identity["description"];
    $composer["keywords"] = $identity["keywords"];

    if (($composer["require"] ?? null) !== ($source["require"] ?? null)) {
        fwrite(STDERR, "export-core: the exported composer.json changed the require block\n");
        exit(1);
    }

    // Composer::Locker::getContentHash(), reproduced so the export can rename
    // itself without a network round trip. `name` is part of the hash, so a
    // rename that skipped this would ship a lockfile composer calls stale.
    $keys = ["name", "version", "require", "require-dev", "conflict", "replace", "provide", "minimum-stability", "prefer-stable", "repositories", "extra"];
    $relevant = [];

    foreach ($keys as $key) {
        if (array_key_exists($key, $composer)) {
            $relevant[$key] = $composer[$key];
        }
    }

    if (isset($composer["config"]["platform"])) {
        $relevant["config"]["platform"] = $composer["config"]["platform"];
    }

    ksort($relevant);

    // Options 0, not the pretty flags: this is exactly
    // JsonFile::encode($relevantContent, 0), which is what Composer hashes.
    $hash = md5((string) json_encode($relevant, 0));

    file_put_contents(
        $target . "/composer.json",
        json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    );

    $lock = json_decode((string) file_get_contents($target . "/composer.lock"), true);

    if (!is_array($lock)) {
        fwrite(STDERR, "export-core: composer.lock is not an object\n");
        exit(1);
    }

    $lock["content-hash"] = $hash;

    file_put_contents(
        $target . "/composer.lock",
        json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    );
' "$TARGET" "$STAGE"

step "Generating the exported PHPUnit configuration"
php -r '
    $target = $argv[1];
    $stage = $argv[2];

    $xml = (string) file_get_contents($stage . "/phpunit.source.xml");
    $lines = file($stage . "/phpunit.exclude", FILE_IGNORE_NEW_LINES) ?: [];
    $excludes = [];

    foreach ($lines as $line) {
        $path = trim(explode("#", $line, 2)[0]);

        if ("" === $path) {
            continue;
        }

        if (!is_file($target . "/" . $path)) {
            fwrite(STDERR, "export-core: exclusion names a missing file: " . $path . "\n");
            exit(1);
        }

        if (!str_ends_with($path, "Test.php")) {
            fwrite(STDERR, "export-core: exclusion is not a *Test.php file: " . $path . "\n");
            exit(1);
        }

        $excludes[] = $path;
    }

    if (count($excludes) < 10) {
        fwrite(STDERR, "export-core: fewer than ten exclusions, the file probably moved\n");
        exit(1);
    }

    $insert = "";

    foreach ($excludes as $path) {
        $insert .= "            <exclude>" . $path . "</exclude>\n";
    }

    $position = strpos($xml, "</testsuite>");

    if (false === $position) {
        fwrite(STDERR, "export-core: phpunit.xml has no closing testsuite\n");
        exit(1);
    }

    $xml = substr($xml, 0, $position) . $insert . substr($xml, $position);

    file_put_contents($target . "/phpunit.xml", $xml);
' "$TARGET" "$STAGE"

step "Generating the exported toolchain registry"
php "$ROOT/bin/ops/export-core-registry.php" \
    --root="$TARGET" \
    --registry="$STAGE/toolchain.source.json" \
    --makefile="$TARGET/Makefile" \
    --out="$TARGET/toolchain.json"

step "Writing the export marker"
EXPORTED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
cat > "$TARGET/.nativa-core-export.json" <<JSON
{
    "format": 1,
    "source": "https://github.com/responsive-sk/nativa-php-core",
    "commit": "$COMMIT",
    "commit_date": "$COMMIT_DATE",
    "exported_at": "$EXPORTED_AT",
    "profile": "$PROFILE"
}
JSON

step "Verifying the exported shape"
php "$ROOT/bin/ops/export-core-verify.php" \
    --root="$TARGET" \
    --source-makefile="$ROOT/Makefile" \
    || fail "the exported shape is wrong (problems above)" 1

if [ "$CHECK" != true ]; then
    step "OK — $TARGET"
    echo "    commit:  $SHORT"
    echo "    files:   $(find "$TARGET" -type f -not -path '*/.git/*' | wc -l | tr -d ' ')"
    echo "    mark:    .nativa-core-export.json"
    exit 0
fi

step "Linting every exported PHP file"
find "$TARGET" -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null

step "Validating the exported composer manifest"
( cd "$TARGET" && composer validate --no-check-publish --no-interaction ) || fail "composer validate refused the exported manifest" 1

step "Copying this tree's vendor/ into the export (dev tooling only)"
# A symlink would not work: PHP resolves __FILE__ through it, so Composer's
# $baseDir would point back at THIS tree and the exported suite would load the
# source classes. Copying keeps the autoloader inside the export.
cp -a "$ROOT/vendor/." "$TARGET/vendor/"

step "Running the exported PHPUnit suite"
tests_before="$(cd "$TARGET" && php vendor/bin/phpunit --list-tests 2>/dev/null | grep -c '^ - ' || true)"

if [ "${tests_before:-0}" -lt 800 ]; then
    fail "the exported suite discovered only $tests_before tests; the exclusion list probably hides the tree" 1
fi

if ! ( cd "$TARGET" && php vendor/bin/phpunit --no-progress ); then
    fail "the exported suite is red; fix the export rather than the exclusion list" 1
fi

step "Checking that every excluded test is really removed from the run"
listed_tests="$(cd "$TARGET" && php vendor/bin/phpunit --list-tests 2>/dev/null)"

while IFS= read -r line || [ -n "$line" ]; do
    path="$(printf '%s' "${line%%#*}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    [ -n "$path" ] || continue
    class="$(basename "$path" .php)"
    count="$(printf '%s' "$listed_tests" | grep -c "${class}::" || true)"

    if [ "${count:-0}" -ne 0 ]; then
        fail "$path is in phpunit.exclude but still runs ($count test(s))" 1
    fi
done < "$STAGE/phpunit.exclude"

step "OK — export is green ($tests_before tests discovered)"
echo "    commit:  $SHORT"
echo "    files:   $(find "$TARGET" -type f -not -path '*/.git/*' -not -path '*/vendor/*' | wc -l | tr -d ' ')"
