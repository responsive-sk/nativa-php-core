#!/bin/bash
#
# =============================================================================
# mago-spike.sh — the Mago spike, on demand, out of CI's way.
# =============================================================================
# What it does: downloads (once, checksum-pinned) the Mago binary, runs the four
# tools over `mago.toml`'s scope, and writes one report per tool plus a
# `summary.tsv` the evaluation document quotes.
#
# What it is NOT: a gate. It exits 0 whatever the tools say, for the same reason
# `kernel-size.sh` and `lekalo-verify` do — a spike whose verdict depends on a
# number nobody has triaged yet would be a gate that fails on day one and gets
# ignored by day three. `--strict` propagates the tools' exit codes, so the
# switch to a gate is one flag, deliberately not taken here.
#
# The binary is pinned by version AND by the sha256 of its release archive. The
# checksum is the identity: a re-rolled upstream asset fails here instead of
# silently changing what the report measured. Same pattern as the `lekalo` step
# in `.github/workflows/ci.yml`; the difference is that this script caches the
# archive and binary under `storage/cache/` (gitignored), so the second run is
# offline.
#
# The pinned target is `x86_64-unknown-linux-gnu` — this repo's CI and dev
# floor. On any other platform the script warns and measures nothing; it does
# not guess a target, because an unverifiable binary is worse than no number.
#
# Usage:
#   bin/build/mago-spike.sh                # all four tools, always exit 0
#   bin/build/mago-spike.sh --strict       # exit non-zero when a tool does
#   bin/build/mago-spike.sh --only=analyze # one tool: analyze|lint|format|guard
#
# Reports: storage/cache/mago-spike/<tool>.txt, summary.tsv
# Rationale and measurements: docs/mago-evaluation.md
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

OUT="$ROOT/storage/cache/mago-spike"
BIN="$OUT/bin/mago"
CONFIG="$ROOT/mago.toml"
SUMMARY="$OUT/summary.tsv"

MAGO_VERSION="1.58.2"
MAGO_TARGET="x86_64-unknown-linux-gnu"
# sha256 of mago-1.58.2-x86_64-unknown-linux-gnu.tar.gz, recorded 2026-10-22.
MAGO_ARCHIVE_SHA256="80b6e6ed3cd2fe2da348f9cd83d23e638f2ccd44988d48bebd766c20ccd30dc2"

STRICT=0
ONLY=""

for arg in "$@"; do
    case "$arg" in
        --strict) STRICT=1 ;;
        --only=*) ONLY="${arg#--only=}" ;;
        -h|--help)
            sed -n '3,30p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "usage: bin/build/mago-spike.sh [--strict] [--only=analyze|lint|format|guard]" >&2
            exit 2
            ;;
    esac
done

case "$ONLY" in
    ""|analyze|lint|format|guard) ;;
    *)
        echo "mago-spike: unknown tool '$ONLY' — expected analyze, lint, format or guard" >&2
        exit 2
        ;;
esac

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

now_ms() {
    # bash 5 exposes EPOCHREALTIME (seconds.microseconds). Older bash falls back
    # to whole seconds — a coarse wall time is still a wall time, and requiring
    # a newer shell than the tree's floor would be worse.
    if [ -n "${EPOCHREALTIME:-}" ]; then
        local t="${EPOCHREALTIME/,/.}"
        local sec="${t%.*}"
        local frac="${t#*.}"
        while [ "${#frac}" -lt 6 ]; do frac="${frac}0"; done
        echo $(( sec * 1000 + 10#${frac:0:3} ))
    else
        echo $(( $(date +%s) * 1000 ))
    fi
}

ensure_mago() {
    if [ -x "$BIN" ] && "$BIN" --version 2>/dev/null | grep -qx "mago $MAGO_VERSION"; then
        return 0
    fi

    mkdir -p "$OUT/bin"

    local archive="$OUT/mago-${MAGO_VERSION}-${MAGO_TARGET}.tar.gz"
    if [ ! -f "$archive" ]; then
        echo "INFO [mago-spike] downloading mago ${MAGO_VERSION} (${MAGO_TARGET})" >&2
        if ! curl -fsSL -o "$archive" \
            "https://github.com/carthage-software/mago/releases/download/${MAGO_VERSION}/mago-${MAGO_VERSION}-${MAGO_TARGET}.tar.gz"; then
            rm -f "$archive"
            echo "WARN [mago-spike] download failed — no binary, nothing measured" >&2
            return 1
        fi
    fi

    local actual
    actual="$(sha256_of "$archive")"
    if [ "$actual" != "$MAGO_ARCHIVE_SHA256" ]; then
        # Two possibilities, one answer: either the download is corrupt or
        # upstream re-rolled the asset. Both mean this is not the binary the
        # report was measured with, so neither is installed.
        rm -f "$archive"
        echo "WARN [mago-spike] checksum mismatch for mago ${MAGO_VERSION}: expected ${MAGO_ARCHIVE_SHA256}, got ${actual}" >&2
        return 1
    fi

    tar xzf "$archive" -C "$OUT"
    install -m 0755 "$OUT/mago-${MAGO_VERSION}-${MAGO_TARGET}/mago" "$BIN"

    # A checksum proves the archive, not that this kernel can execute it: a
    # binary for another platform installs fine and fails on the first call.
    if ! "$BIN" --version 2>/dev/null | grep -qx "mago $MAGO_VERSION"; then
        rm -f "$BIN"
        echo "WARN [mago-spike] mago ${MAGO_VERSION} (${MAGO_TARGET}) does not run here — nothing measured" >&2
        return 1
    fi

    echo "INFO [mago-spike] installed $("$BIN" --version)" >&2
}

# The four tools print their verdict differently; one counter each, and a zero
# when the tool says it found nothing (which is a line, not an absence of one).
issue_count() {
    local tool="$1" report="$2"
    case "$tool" in
        format)
            grep -oE 'Found [0-9]+ file' "$report" | tail -1 | awk '{print $2}' | grep -E '^[0-9]+$' || echo 0
            ;;
        *)
            if grep -q 'No issues found' "$report"; then
                echo 0
            else
                grep -oE 'found [0-9]+ issues?' "$report" | tail -1 | awk '{print $2}' || echo 0
            fi
            ;;
    esac
}

FAILED=0
run_tool() {
    local tool="$1"
    shift
    local report="$OUT/${tool}.txt"
    local started finished exit_code issues

    started="$(now_ms)"
    set +e
    "$BIN" --config "$CONFIG" "$tool" "$@" > "$report" 2>&1
    exit_code=$?
    set -e
    finished="$(now_ms)"

    issues="$(issue_count "$tool" "$report")"
    printf '%s\t%s\t%s\t%s\t%s\n' "$tool" "$MAGO_VERSION" "$exit_code" "$issues" "$((finished - started))" >> "$SUMMARY"
    printf 'INFO [mago-spike] tool=%s exit=%s issues=%s wall=%sms\n' "$tool" "$exit_code" "$issues" "$((finished - started))"

    if [ "$tool" = "guard" ] && grep -q 'skipped due to missing configuration' "$report"; then
        echo "WARN [mago-spike] guard has no perimeter rules configured — nothing was checked" >&2
    fi
    if [ "$exit_code" -ne 0 ] && [ "$STRICT" -eq 1 ]; then
        FAILED=1
    fi
}

mkdir -p "$OUT"
: > "$SUMMARY"

if ! ensure_mago; then
    echo "WARN [mago-spike] mago ${MAGO_VERSION} unavailable — spike not measured" >&2
    [ "$STRICT" -eq 1 ] && exit 2
    exit 0
fi

if [ -z "$ONLY" ] || [ "$ONLY" = "analyze" ]; then run_tool analyze; fi
if [ -z "$ONLY" ] || [ "$ONLY" = "lint" ]; then run_tool lint; fi
if [ -z "$ONLY" ] || [ "$ONLY" = "format" ]; then run_tool format --dry-run; fi
if [ -z "$ONLY" ] || [ "$ONLY" = "guard" ]; then run_tool guard; fi

echo "INFO [mago-spike] reports in ${OUT#"$ROOT"/} (informational — see docs/mago-evaluation.md)"

if [ "$STRICT" -eq 1 ] && [ "$FAILED" -eq 1 ]; then
    exit 1
fi

exit 0
