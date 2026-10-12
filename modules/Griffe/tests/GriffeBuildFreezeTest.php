<?php

declare(strict_types = 1);

namespace Modules\Griffe\Tests;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GriffeBuildFreezeTest — the frozen `griffe` bundle is committed, resolves,
 * and stays a single Chrome-70-safe file inside its size budget.
 *
 * `public/dist/` is gitignored everywhere EXCEPT `dist/griffe/` (see the
 * freeze exception in .gitignore): a deploy without Node serves these bytes,
 * so their absence is a defect, not a build that has not run — nothing here
 * skips when the manifest is missing. Edit the bundle only by rebuilding
 * (`make assets`); a hand edit passes every assertion below except the
 * rebuild-identity the summary asks for, which is why phase 4.7 checks
 * `git status` after a rebuild instead of asserting it here.
 */
final class GriffeBuildFreezeTest extends TestCase
{
    private const string BUNDLE = 'griffe';

    /** Raw-byte budgets with ~30% headroom over the freezing build (JS 10 798, CSS 9 261). */
    private const int JS_BUDGET = 14000;

    private const int CSS_BUDGET = 12000;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Griffe')) {
            $this->markTestSkipped('Griffe is not installed — the `griffe` theme ships with this module.');
        }
    }

    #[Test]
    public function theFrozenManifestIsCommitted(): void
    {
        $manifest = AppPaths::instance()->public('dist/' . self::BUNDLE . '/manifest.json');

        $this->assertFileExists(
            $manifest,
            'The frozen griffe manifest is not committed — rebuild it (`make assets`), never by hand.',
        );
    }

    #[Test]
    public function assetManagerResolvesTheFrozenEntry(): void
    {
        $assets = new AssetManager(self::BUNDLE);

        $this->assertTrue($assets->isAvailable(), 'AssetManager did not resolve the committed griffe manifest.');

        $jsUrl = $assets->jsUrl(self::BUNDLE);

        $this->assertNotNull($jsUrl, 'The frozen manifest names no `griffe` JS entry.');
        $this->assertFileExists(
            AppPaths::instance()->public(ltrim($jsUrl, '/')),
            'The manifest points at a JS file that is not committed.',
        );

        $cssUrls = $assets->cssUrls(self::BUNDLE);

        $this->assertNotSame([], $cssUrls, 'The frozen manifest names no `griffe` CSS.');

        foreach ($cssUrls as $url) {
            $this->assertFileExists(
                AppPaths::instance()->public(ltrim($url, '/')),
                sprintf('The manifest points at a CSS file that is not committed: %s.', $url),
            );
        }
    }

    /**
     * One self-contained file per entry: a shared Rollup chunk would emit
     * `import` statements, and the deferred `<script>` (no `type="module"`)
     * cannot execute them on the Chrome 70 baseline.
     */
    #[Test]
    public function theFrozenScriptIsASingleFileWithNoModuleSyntax(): void
    {
        $js = $this->frozenJs();

        $this->assertDoesNotMatchRegularExpression(
            '/(^|[^.\w])import[\s(]/',
            $js,
            'The frozen bundle carries an import — a shared chunk leaked in, and Chrome 70 cannot execute it.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(^|[^.\w])export[\s{]/',
            $js,
            'The frozen bundle carries an export — it is a module, not a classic script.',
        );
    }

    #[Test]
    public function theFrozenBundleHoldsItsSizeBudget(): void
    {
        $js = $this->frozenJs();
        $css = $this->frozenCss();

        $this->assertLessThanOrEqual(
            self::JS_BUDGET,
            strlen($js),
            sprintf('Frozen griffe JS is %d bytes over a %d budget.', strlen($js), self::JS_BUDGET),
        );
        $this->assertLessThanOrEqual(
            self::CSS_BUDGET,
            strlen($css),
            sprintf('Frozen griffe CSS is %d bytes over a %d budget.', strlen($css), self::CSS_BUDGET),
        );
    }

    /**
     * The built stylesheet is a superset of the critical fallback: every
     * `gr-*` class the fallback file declares must exist in the build, or a
     * page served from the manifest loses rules the no-build page keeps.
     */
    #[Test]
    public function theBuiltStylesheetCoversEveryCriticalFallbackClass(): void
    {
        $fallback = InlineCss::file('Modules/Griffe/assets/critical/griffe.css');
        $css = $this->frozenCss();

        preg_match_all('/\.gr-[a-z0-9-]+/', $fallback, $matches);

        $classes = array_values(array_unique($matches[0]));

        $this->assertNotSame([], $classes, 'The fallback file declares no gr-* class — the extractor is broken.');

        $missing = [];

        foreach ($classes as $class) {
            if (!str_contains($css, $class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Built griffe CSS is missing fallback classes: ' . implode(', ', $missing),
        );

        foreach (['keyword', 'string', 'comment', 'number', 'function', 'operator'] as $token) {
            $this->assertStringContainsString(
                '.tok-' . $token,
                $css,
                'The token palette lost .' . $token . ' — highlighted blocks fall back to one colour.',
            );
        }
    }

    #[Test]
    public function theFrozenBundleShipsNoSourcemaps(): void
    {
        $maps = glob(AppPaths::instance()->public('dist/' . self::BUNDLE . '/assets/*.map') ?: []);

        $this->assertSame([], $maps, 'Sourcemaps are debug weight in a frozen production bundle.');
    }

    private function frozenJs(): string
    {
        $url = (new AssetManager(self::BUNDLE))->jsUrl(self::BUNDLE);

        $this->assertNotNull($url, 'The frozen manifest names no `griffe` JS entry.');

        $js = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

        $this->assertIsString($js, 'The frozen JS file is unreadable.');

        return $js;
    }

    private function frozenCss(): string
    {
        $urls = (new AssetManager(self::BUNDLE))->cssUrls(self::BUNDLE);

        $this->assertNotSame([], $urls, 'The frozen manifest names no `griffe` CSS.');

        $css = '';

        foreach ($urls as $url) {
            $content = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

            $this->assertIsString($content, sprintf('The frozen CSS file is unreadable: %s.', $url));

            $css .= $content;
        }

        return $css;
    }
}
