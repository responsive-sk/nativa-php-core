#!/bin/bash
#
# =============================================================================
# export-core.sh — assemble a published tree from a commit of this one.
# =============================================================================
# `responsive-sk/nativa-core` and `responsive-sk/nativa-resp` are GENERATED.
# This script is the generator, and this tree is the source of truth: a change
# to an export is made here, committed, and exported again.
#
# The PROFILE owns every fact that differs between the two trees: the
# repository, the module set, the content, the deploy identity and the marker.
# The file is read from the archive, not from the working tree, for the same
# reason the bytes are: an uncommitted edit to a profile must be invisible here.
#
# The bytes come from `git archive HEAD`, never from the working tree. That is
# the same rule `dist-check.sh` keeps for the deployment artifact: a tree
# assembled from one developer's checkout cannot be reproduced by anyone else,
# and an uncommitted edit would leak into a published repository.
#
# What the export drops is `bin/export/<slug>/prune.txt` — one path and one
# reason per line. What replaces a dropped file is
# `bin/export/<slug>/<same name>`: Makefile, phpunit.xml, .gitignore, README.md,
# AGENTS.md, the CI workflow and the composer identity. `config/deploy.php` is
# generated from the profile's `deploy` object, so the theme name is not spelled
# in a second PHP literal.
#
# Before it reports success it asserts:
#   - every prune entry removed something (a moved path is a red run);
#   - every named module exists in the catalogue, and every catalogue entry the
#     profile does not name is gone;
#   - the installed slices match the pruned catalogue;
#   - the exported composer.json keeps the source `require` block, and the
#     lockfile's content-hash is recomputed for the new name;
#   - every profile target exists in the source Makefile;
#   - every `bin/...` path an exported recipe names exists in the export;
#   - every `make` call in the exported workflow is an exported target;
#   - every phpunit exclusion names a real test file, and that file is not
#     shipped at all;
#   - no exported surface names a pruned path.
#
# Usage:
#   bin/build/export-core.sh                          # → the profile's default
#   bin/build/export-core.sh --profile <dir>          # default bin/export/core
#   bin/build/export-core.sh --to <dir>               # → <dir> (must be empty)
#   bin/build/export-core.sh --check                  # → temp dir, then lint + suite
#   bin/build/export-core.sh --ref <commit>           # export a commit (default HEAD)
#   bin/build/export-core.sh --force                  # replace a non-empty --to dir
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROFILE="bin/export/core"
TARGET=""
REF="HEAD"
CHECK=false
FORCE=false
CLEANUP=""
LABEL="export-core"

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
        --to) TARGET="${2:-}"; [ -n "$TARGET" ] || fail "--to needs a directory"; shift ;;
        --ref) REF="${2:-}"; [ -n "$REF" ] || fail "--ref needs a commit"; shift ;;
        --check) CHECK=true ;;
        --force) FORCE=true ;;
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

git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1 || fail "not a git work tree" 1
COMMIT="$(git -C "$ROOT" rev-parse "$REF^{commit}")" || fail "cannot resolve --ref=$REF to a commit" 1
SHORT="$(git -C "$ROOT" rev-parse --short "$COMMIT")"
COMMIT_DATE="$(git -C "$ROOT" show -s --format=%cI "$COMMIT")"

PROFILE_JSON="$(git -C "$ROOT" show "$COMMIT:$PROFILE/profile.json" 2>/dev/null)" \
    || fail "the commit does not carry $PROFILE/profile.json" 1

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

        if (is_bool($profile)) {
            echo $profile ? "true" : "false";
        } else {
            echo (string) $profile;
        }
    ' "$1"
}

SLUG="$(profile_field slug)"
SOURCE="$(profile_field source)"
MARKER="$(profile_field marker)"
DEFAULT_TARGET="$(profile_field default_target)"
INSTALL_MODULES="$(profile_field install_modules)"
MIGRATE_BEFORE_TESTS="$(profile_field migrate_before_tests)"
MINIMUM_TESTS="$(profile_field minimum_tests)"
DEPLOY_THEME="$(profile_field deploy.theme)"

LABEL="export-$SLUG"

case "$SLUG" in
    [a-z]*) ;;
    *) fail "the profile slug must start with a lowercase letter: $SLUG" 1 ;;
esac

case "$SLUG" in
    *[!a-z0-9-]*) fail "the profile slug may contain only a-z, 0-9 and -: $SLUG" 1 ;;
esac

case "$MARKER" in
    .*.json) ;;
    *) fail "the profile marker must be a dotfile ending in .json: $MARKER" 1 ;;
esac

case "$INSTALL_MODULES" in true|false) ;; *) fail "install_modules must be true or false" 1 ;; esac
case "$MIGRATE_BEFORE_TESTS" in true|false) ;; *) fail "migrate_before_tests must be true or false" 1 ;; esac
case "$MINIMUM_TESTS" in ''|*[!0-9]*) fail "minimum_tests must be a number" 1 ;; esac
[ -n "$DEPLOY_THEME" ] || fail "deploy.theme is empty" 1
[ -n "$DEFAULT_TARGET" ] || fail "default_target is empty" 1
[ -n "$SOURCE" ] || fail "source is empty" 1

if [ "$CHECK" = true ]; then
    TARGET="$(mktemp -d "${TMPDIR:-/tmp}/nativa-$SLUG-check.XXXXXX")"
    CLEANUP="$TARGET"
    FORCE=true
elif [ -z "$TARGET" ]; then
    TARGET="$ROOT/$DEFAULT_TARGET"
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

step "Exporting $REF ($SHORT) with profile $SLUG into $TARGET"
git -C "$ROOT" archive --format=tar "$COMMIT" | tar -x -C "$TARGET"

[ -d "$TARGET/$PROFILE" ] || fail "the exported archive carries no $PROFILE" 1
[ -f "$TARGET/$PROFILE/profile.json" ] || fail "the exported archive carries no $PROFILE/profile.json" 1

# The profile is read from the archive, not from the working tree: an
# uncommitted edit to it must be invisible here, like every other source file.
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/nativa-$SLUG-profile.XXXXXX")"
cp -a "$TARGET/$PROFILE/." "$STAGE/"
cp "$TARGET/toolchain.json" "$STAGE/toolchain.source.json"
cp "$TARGET/composer.json" "$STAGE/composer.source.json"
cp "$TARGET/phpunit.xml" "$STAGE/phpunit.source.xml"

for file in profile.json prune.txt phpunit.exclude composer.identity.json Makefile .gitignore README.md AGENTS.md .github/workflows/ci.yml; do
    [ -f "$STAGE/$file" ] || fail "the profile is missing $file" 1
done

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

if [ "$INSTALL_MODULES" = true ]; then
    step "Pruning the catalogue to the profile's module set"
    php -r '
        $root = $argv[1];
        $profile = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($profile) || !isset($profile["modules"]) || !is_array($profile["modules"]) || [] === $profile["modules"]) {
            fwrite(STDERR, "export-core: install_modules is true but modules is empty\n");
            exit(1);
        }

        $allowed = array_fill_keys(array_map("strval", $profile["modules"]), true);
        $found = [];

        $remove = static function (string $dir): void {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }

            rmdir($dir);
        };

        foreach (glob($root . "/modules/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);

            if ("Themes" === $name) {
                foreach (glob($dir . "/*", GLOB_ONLYDIR) ?: [] as $themeDir) {
                    $theme = basename($themeDir);

                    if (isset($allowed[$theme])) {
                        $found[$theme] = true;
                    } else {
                        $remove($themeDir);
                    }
                }

                continue;
            }

            if (isset($allowed[$name])) {
                $found[$name] = true;
            } else {
                $remove($dir);
            }
        }

        $missing = array_values(array_diff(array_keys($allowed), array_keys($found)));

        if ([] !== $missing) {
            fwrite(STDERR, "export-core: module(s) named by the profile are not in the catalogue: " . implode(", ", $missing) . "\n");
            exit(1);
        }

        printf("    %d module(s) kept\n", count($found));
    ' "$TARGET" "$STAGE/profile.json"
fi

# The source index names every module in the source catalogue; the export has a
# smaller one. Regenerate it AFTER the module prune and BEFORE the install, so
# the catalogue and its generated index describe the same tree.
step "Rebuilding the module catalogue index"
( cd "$TARGET" && php bin/build/module-catalogue.php --write )

if [ "$INSTALL_MODULES" = true ]; then
    step "Installing the profile's slices"
    ( cd "$TARGET" && bash bin/build/install-catalogue.sh )
fi

step "Installing the export profile"
mkdir -p "$TARGET/.github/workflows" "$TARGET/config"
for file in Makefile .gitignore README.md AGENTS.md; do
    cp "$STAGE/$file" "$TARGET/$file"
done
cp "$STAGE/.github/workflows/ci.yml" "$TARGET/.github/workflows/ci.yml"

step "Generating config/deploy.php from the profile"
php -r '
    $profile = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $deploy = $profile["deploy"] ?? null;

    if (!is_array($deploy) || "" === (string) ($deploy["theme"] ?? "")) {
        fwrite(STDERR, "export-core: the profile has no deploy.theme\n");
        exit(1);
    }

    $keys = ["theme", "site_name", "site_description", "timezone"];
    $unknown = array_diff(array_keys($deploy), $keys);

    if ([] !== $unknown) {
        fwrite(STDERR, "export-core: unknown deploy key(s): " . implode(", ", $unknown) . "\n");
        exit(1);
    }

    $lines = [];

    foreach ($keys as $key) {
        if (array_key_exists($key, $deploy)) {
            $lines[] = "    " . var_export($key, true) . " => " . var_export((string) $deploy[$key], true) . ",";
        }
    }

    $body = "<?php\n\n"
        . "declare(strict_types = 1);\n\n"
        . "/**\n"
        . " * Deployment profile for the export, generated from " . $argv[3] . "/profile.json.\n"
        . " *\n"
        . " * The theme is the identity the artifact boots with. The other keys are\n"
        . " * written only when the profile names them, so a starter tree does not\n"
        . " * silently rebrand a site an operator has not configured.\n"
        . " *\n"
        . " * @return array<string, string>\n"
        . " */\n"
        . "return [\n"
        . implode("\n", $lines) . "\n"
        . "];\n";

    file_put_contents($argv[2], $body);
' "$STAGE/profile.json" "$TARGET/config/deploy.php" "$PROFILE"

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

step "Removing the tests whose subject the export does not carry"
php -r '
    $target = $argv[1];
    $stage = $argv[2];

    $lines = file($stage . "/phpunit.exclude", FILE_IGNORE_NEW_LINES) ?: [];
    $dropped = 0;

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

        if (!unlink($target . "/" . $path)) {
            fwrite(STDERR, "export-core: could not remove the excluded test: " . $path . "\n");
            exit(1);
        }

        // A module test lives on both sides once the slice is installed. Remove
        // the installed copy too, or module:show reports the export as drifted
        // even though the test it names is no longer in the catalogue.
        if (str_starts_with($path, "modules/")) {
            $installed = "src/Modules/" . substr($path, strlen("modules/"));

            if (is_file($target . "/" . $installed) && !unlink($target . "/" . $installed)) {
                fwrite(STDERR, "export-core: could not remove the installed excluded test: " . $installed . "\n");
                exit(1);
            }
        }

        $dropped++;
    }

    if ($dropped < 10) {
        fwrite(STDERR, "export-core: fewer than ten exclusions, the file probably moved\n");
        exit(1);
    }
' "$TARGET" "$STAGE"

if [ "$INSTALL_MODULES" = true ]; then
    step "Checking that catalogue and installed slice are identical after the test removal"
    drift="$(cd "$TARGET" && php bin/cms module:show)"
    printf '%s\n' "$drift"

    if printf '%s\n' "$drift" | grep -Eq ' (drifted|not-installed) '; then
        fail "a module is drifted or not installed; the export would ship a slice that is not its catalogue" 1
    fi
fi

step "Installing the exported PHPUnit configuration"
cp "$STAGE/phpunit.source.xml" "$TARGET/phpunit.xml"

step "Generating the exported toolchain registry"
php "$ROOT/bin/ops/export-core-registry.php" \
    --root="$TARGET" \
    --registry="$STAGE/toolchain.source.json" \
    --makefile="$TARGET/Makefile" \
    --out="$TARGET/toolchain.json"

step "Writing the export marker"
EXPORTED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
php -r '
    $marker = [
        "format" => 1,
        "source" => $argv[2],
        "commit" => $argv[3],
        "commit_date" => $argv[4],
        "exported_at" => $argv[5],
        "profile" => $argv[6],
    ];

    file_put_contents(
        $argv[1],
        json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    );
' "$TARGET/$MARKER" "$SOURCE" "$COMMIT" "$COMMIT_DATE" "$EXPORTED_AT" "$SLUG"

step "Verifying the exported shape"
php "$ROOT/bin/ops/export-core-verify.php" \
    --root="$TARGET" \
    --source-makefile="$ROOT/Makefile" \
    --profile="$STAGE" \
    || fail "the exported shape is wrong (problems above)" 1

if [ "$CHECK" != true ]; then
    step "OK — $TARGET"
    echo "    profile: $SLUG"
    echo "    commit:  $SHORT"
    echo "    files:   $(find "$TARGET" -type f -not -path '*/.git/*' | wc -l | tr -d ' ')"
    echo "    mark:    $MARKER"
    exit 0
fi

# Counted here, before the suite: the run leaves a result cache, an error log and
# a session behind, and a summary that names the tree after the run would report
# three files the export does not carry.
exported_files="$(find "$TARGET" -type f -not -path '*/.git/*' | wc -l | tr -d ' ')"

step "Linting every exported PHP file"
find "$TARGET" -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null

step "Validating the exported composer manifest"
( cd "$TARGET" && composer validate --no-check-publish --no-interaction ) || fail "composer validate refused the exported manifest" 1

[ -d "$ROOT/vendor" ] || fail "this tree has no vendor/; run composer install before --check" 1

step "Copying this tree's vendor/ into the export (dev tooling only)"
# A symlink would not work: PHP resolves __FILE__ through it, so Composer's
# $baseDir would point back at THIS tree and the exported suite would load the
# source classes. Copying keeps the autoloader inside the export.
cp -a "$ROOT/vendor/." "$TARGET/vendor/"

if [ "$MIGRATE_BEFORE_TESTS" = true ]; then
    step "Migrating the exported site"
    ( cd "$TARGET" && php bin/cms migrate ) || fail "the exported site did not migrate" 1
fi

step "Running the exported PHPUnit suite"
tests_before="$(cd "$TARGET" && php vendor/bin/phpunit --list-tests 2>/dev/null | grep -c '^ - ' || true)"

# A floor, not a target. It catches an exclusion list that swallowed the tree;
# the minimal export is a kernel plus one theme and one module, so its honest
# count is far below the catalogue tree's ~1 500 and the floor moved with it.
if [ "${tests_before:-0}" -lt "$MINIMUM_TESTS" ]; then
    fail "the exported suite discovered only $tests_before tests; the exclusion list probably hides the tree" 1
fi

if ! ( cd "$TARGET" && php vendor/bin/phpunit --no-progress ); then
    fail "the exported suite is red; fix the export rather than the exclusion list" 1
fi

step "Checking that every excluded test is absent from the run"
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
echo "    profile: $SLUG"
echo "    commit:  $SHORT"
echo "    files:   $exported_files"
