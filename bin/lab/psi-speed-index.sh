#!/usr/bin/env bash
#
# psi-speed-index.sh — run the same mobile profile PageSpeed Insights uses, N
# times, and print the median Speed Index with the filmstrip of the worst run.
#
# Why this exists: PSI answers with ONE run, and Speed Index is a
# screenshot-weighted metric — a single sample moves when one paint in one
# execution lands late, even if the page sends the same six requests every time.
# "SI 3.8" is therefore unanswerable from a single PSI screenshot: either the
# page has something that paints late, or that run did. Five runs under one
# fixed profile separate the two, because a late paint is a property of the page
# and repeats, while a slow run does not.
#
# The profile is written out, not inherited. PSI passes nothing that overrides
# Lighthouse's `lanternMobileSlow4G` throttle, so the numbers here are only
# comparable to PSI if the values are the same ones; leaving them implicit means
# a later CLI default would silently change what "PSI says" means. The values
# below are that preset's: RTT 150 ms, 1638.4 kbps down, 675 kbps up, the
# 562.5 ms request latency and 1474.56 kbps throughput simulation derives from
# them, CPU x4, and Moto G Power-shaped mobile emulation. The run asserts the
# profile actually took effect (see the configSettings check below) instead of
# trusting the flag — a config that silently fell back to desktop throttling
# would print numbers that look fine and mean nothing.
#
# Speed Index comes from `screenshot-thumbnails`, so this prints the thumbnails
# as well: a median says "the page is fast", a filmstrip says which area was
# still arriving. The filmstrip is an HTML file next to the raw reports, one
# frame per thumbnail with its timestamp — open it, do not guess.
#
# Usage:
#   bin/lab/psi-speed-index.sh                       # https://responsive.sk/
#   bin/lab/psi-speed-index.sh https://example.com/blog
#
# Environment:
#   RUNS     how many runs (default 5)
#   OUT_DIR  where the reports and filmstrip go (default /tmp/psi-si)
#   LH       the Lighthouse command (default: npx -y lighthouse@13)
#   CHROME_PATH  a Chromium binary, if Lighthouse cannot find one itself
#
# Output: one line per run (FCP, LCP, TBT, CLS, SI), the median SI across the
# runs, and the worst run with the path to its filmstrip. A measurement, not a
# gate: nothing here asserts a budget, and a slow page exits 0.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

URL=${1:-${PSI_URL:-https://responsive.sk/}}
RUNS=${RUNS:-5}
OUT_DIR=${OUT_DIR:-/tmp/psi-si}
LH=${LH:-npx -y lighthouse@13}

case "$RUNS" in
    ''|*[!0-9]*) echo "psi-speed-index: RUNS must be a positive integer, got '$RUNS'" >&2; exit 2 ;;
esac
if [ "$RUNS" -lt 1 ]; then
    echo "psi-speed-index: RUNS must be at least 1" >&2
    exit 2
fi

mkdir -p "$OUT_DIR"
CONFIG="$OUT_DIR/lantern-mobile.json"
cat > "$CONFIG" <<'JSON'
{
  "extends": "lighthouse:default",
  "settings": {
    "onlyCategories": ["performance"],
    "formFactor": "mobile",
    "throttlingMethod": "simulate",
    "throttling": {
      "rttMs": 150,
      "throughputKbps": 1638.4,
      "requestLatencyMs": 562.5,
      "downloadThroughputKbps": 1474.56,
      "uploadThroughputKbps": 675,
      "cpuSlowdownMultiplier": 4
    },
    "screenEmulation": {
      "mobile": true,
      "width": 412,
      "height": 823,
      "deviceScaleFactor": 1.75,
      "disabled": false
    }
  }
}
JSON

echo "psi-speed-index: $URL"
echo "psi-speed-index: $RUNS run(s), lanternMobileSlow4G + mobile emulation (PSI's profile)"
echo "psi-speed-index: reports in $OUT_DIR"
echo

rm -f "$OUT_DIR"/run-*.json

for i in $(seq 1 "$RUNS"); do
    # Lighthouse exits non-zero for a page that failed to load at all; that is
    # the one failure this script does not average away.
    if ! $LH "$URL" \
        --config-path="$CONFIG" \
        --output=json --output-path="$OUT_DIR/run-$i.json" \
        --quiet --chrome-flags="--headless=new --no-sandbox --disable-dev-shm-usage" \
        >"$OUT_DIR/run-$i.log" 2>&1
    then
        echo "psi-speed-index: run $i failed — see $OUT_DIR/run-$i.log" >&2
        tail -5 "$OUT_DIR/run-$i.log" >&2 || true
        exit 1
    fi
    echo "psi-speed-index: run $i/$RUNS done"
done

echo
OUT_DIR="$OUT_DIR" URL="$URL" RUNS="$RUNS" php <<'PHP'
<?php
$dir = getenv('OUT_DIR');
$runs = [];

foreach (glob($dir . '/run-*.json') as $file) {
    $report = json_decode((string) file_get_contents($file), true);
    if (!is_array($report)) {
        fwrite(STDERR, sprintf("psi-speed-index: %s is not JSON\n", $file));
        exit(1);
    }

    $settings = $report['configSettings'] ?? [];
    $throttle = $settings['throttling'] ?? [];

    // The point of writing the profile down is that it is then checkable. A
    // run under some other throttle is not a PSI run and must not be averaged
    // into the median above the others.
    $expected = [
        'rttMs' => 150,
        'throughputKbps' => 1638.4,
        'requestLatencyMs' => 562.5,
        'downloadThroughputKbps' => 1474.56,
        'uploadThroughputKbps' => 675,
        'cpuSlowdownMultiplier' => 4,
    ];
    foreach ($expected as $key => $want) {
        $got = $throttle[$key] ?? null;
        if ($got === null || abs((float) $got - $want) > 0.001) {
            fwrite(STDERR, sprintf(
                "psi-speed-index: %s ran with throttling.%s=%s, expected %s — the profile did not apply\n",
                basename($file),
                $key,
                var_export($got, true),
                $want
            ));
            exit(1);
        }
    }
    if (($settings['throttlingMethod'] ?? '') !== 'simulate' || ($settings['formFactor'] ?? '') !== 'mobile') {
        fwrite(STDERR, sprintf(
            "psi-speed-index: %s ran %s/%s, expected simulate/mobile\n",
            basename($file),
            $settings['throttlingMethod'] ?? '?',
            $settings['formFactor'] ?? '?'
        ));
        exit(1);
    }

    $audits = $report['audits'] ?? [];
    $value = static function (string $id) use ($audits): float {
        return (float) ($audits[$id]['numericValue'] ?? NAN);
    };

    $runs[] = [
        'file' => $file,
        'score' => (int) round(((float) ($report['categories']['performance']['score'] ?? 0)) * 100),
        'fcp' => $value('first-contentful-paint'),
        'lcp' => $value('largest-contentful-paint'),
        'tbt' => $value('total-blocking-time'),
        'cls' => $value('cumulative-layout-shift'),
        'si' => $value('speed-index'),
        'thumbs' => $audits['screenshot-thumbnails']['details']['items'] ?? [],
        'final' => $audits['final-screenshot']['details']['data'] ?? null,
    ];
}

if ($runs === []) {
    fwrite(STDERR, "psi-speed-index: no reports to analyse\n");
    exit(1);
}

$s = static fn (?float $ms): string => $ms === null || is_nan($ms) ? 'n/a' : sprintf('%.2f s', $ms / 1000);
$ms = static fn (?float $ms): string => $ms === null || is_nan($ms) ? 'n/a' : sprintf('%.0f ms', $ms);

printf("%-4s %6s %8s %8s %8s %6s %8s\n", 'run', 'score', 'FCP', 'LCP', 'TBT', 'CLS', 'SI');
foreach ($runs as $i => $run) {
    printf(
        "%-4d %6d %8s %8s %8s %6s %8s\n",
        $i + 1,
        $run['score'],
        $s($run['fcp']),
        $s($run['lcp']),
        $ms($run['tbt']),
        is_nan($run['cls']) ? 'n/a' : sprintf('%.3f', $run['cls']),
        $s($run['si'])
    );
}

$sis = array_map(static fn (array $r): float => $r['si'], $runs);
sort($sis);
$count = count($sis);
$median = $count % 2 === 1
    ? $sis[intdiv($count, 2)]
    : ($sis[$count / 2 - 1] + $sis[$count / 2]) / 2;

$worst = $runs[0];
foreach ($runs as $run) {
    if ($run['si'] > $worst['si']) {
        $worst = $run;
    }
}

echo "\n";
printf(
    "Speed Index: median %s over %d run(s), range %s – %s\n",
    $s($median),
    $count,
    $s($sis[0]),
    $s($sis[$count - 1])
);

// A spread wider than the median is the answer "one run tells you nothing",
// said in numbers rather than in prose.
$spread = $sis[$count - 1] - $sis[0];
if ($count > 1 && $spread > $median * 0.5) {
    printf(
        "Spread %s is %.0f%% of the median — the metric is moving between runs here; one sample is not a measurement.\n",
        $s($spread),
        $median > 0 ? $spread / $median * 100 : 0
    );
}

printf("Worst run: %s, Speed Index %s\n", basename($worst['file']), $s($worst['si']));

$filmstrip = $dir . '/filmstrip.html';
$esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
$frames = '';
foreach ($worst['thumbs'] as $thumb) {
    $timing = (float) ($thumb['timing'] ?? 0);
    $frames .= sprintf(
        "<figure><img src=\"%s\" alt=\"frame at %.0f ms\"><figcaption>%.0f ms</figcaption></figure>\n",
        $esc((string) ($thumb['data'] ?? '')),
        $timing,
        $timing
    );
}
if ($worst['final'] !== null) {
    $frames .= sprintf(
        "<figure><img src=\"%s\" alt=\"final screenshot\"><figcaption>final</figcaption></figure>\n",
        $esc((string) $worst['final'])
    );
}

$html = sprintf(
    "<!doctype html>\n<meta charset=\"utf-8\">\n<title>Filmstrip — %s</title>\n"
    . "<body style=\"margin:0;padding:24px;background:#111;color:#eee;font:14px/1.4 system-ui,sans-serif\">\n"
    . "<h1 style=\"font-size:16px;font-weight:600\">%s — worst of %d runs, Speed Index %s</h1>\n"
    . "<p style=\"color:#aaa\">Frames are the same thumbnails Lighthouse uses for Speed Index, in the order it scored them.</p>\n"
    . "<div style=\"display:flex;gap:12px;overflow-x:auto;align-items:flex-start\">\n%s</div>\n"
    . "<style>figure{margin:0;text-align:center}img{display:block;width:120px;image-rendering:auto}"
    . "figcaption{color:#888;font-size:11px;margin-top:4px}</style>\n",
    $esc(getenv('URL') ?: ''),
    $esc(getenv('URL') ?: ''),
    $count,
    $s($worst['si']),
    $frames
);
file_put_contents($filmstrip, $html);

printf("Filmstrip: %s\n", $filmstrip);
PHP
