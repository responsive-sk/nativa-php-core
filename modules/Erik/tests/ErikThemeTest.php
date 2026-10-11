<?php

declare(strict_types = 1);

namespace Modules\Erik\Tests;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Erik\Theme\ErikTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ErikThemeTest (Boot 22) — the first theme this tree did not ship, and the
 * asset defect it was the first to hit.
 *
 * `erik` renders correctly and loads nothing. That is the whole failure: with
 * `AssetManager::knownBundles()` a literal list of the KERNEL's themes, the
 * theme resolved its assets as bundle `kernel`, looked for
 * `public/dist/kernel/manifest.json` (absent — the kernel theme is hand-written
 * and has no build), and inlined the 706-char critical subset where its own
 * build has 35 KB. HTTP 200, right markup, wrong stylesheet, empty log.
 *
 * So this file pins the three things that made it invisible:
 *
 * - the bundle each discovered theme declares is a KNOWN bundle (the list is
 *   derived from the runtime theme set, not written out a fourth time);
 * - the layout inlines the theme's own BUILD, byte for byte, and not the
 *   critical fallback;
 * - the document wears the theme's own vocabulary and no sibling's.
 *
 * ## Why it lives here and not under `tests/`
 *
 * Erik is a module. A kernel-only tree has no ErikTheme to construct, so the
 * suite SKIPS rather than fails — the same contract TaxonomyRegistryTest and
 * KernelOnlyBootTest use. `tests/` is inside PHPStan's scope and `modules/` is
 * not, so a test naming an optional module's class cannot make the analyser of
 * a tree without that module fail.
 */
final class ErikThemeTest extends TestCase
{
    private const string MODULE = 'Erik';

    private const string THEME = 'erik';

    /** The markup prefix the theme owns (`er-`), against its siblings'. */
    private const string PREFIX = 'er';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `erik` theme ships with this module.');
        }

        // Discovery memoizes statically and the module set is what it reads:
        // a test that ran after an install/remove must not read that cache.
        ThemeRegistry::reset();
    }

    protected function tearDown(): void
    {
        ThemeRegistry::reset();

        parent::tearDown();
    }

    #[Test]
    public function themeIsDiscoveredAndItsBundleIsKnownToAssetManager(): void
    {
        $this->assertTrue(ThemeRegistry::has(self::THEME), 'Erik ships a theme; discovery did not find it.');

        $theme = ThemeRegistry::get(self::THEME);

        $this->assertInstanceOf(ErikTheme::class, $theme);
        $this->assertSame(self::THEME, $theme->name());
        $this->assertSame(self::THEME, $theme->bundle());

        $this->assertContains(
            self::THEME,
            ThemeRegistry::bundles(),
            'The discovered theme does not contribute its bundle to the registry.',
        );

        $this->assertContains(
            self::THEME,
            AssetManager::knownBundles(),
            'AssetManager does not know the bundle this theme declares, so its'
            . ' manifest resolves as `kernel` — a bundle with no build — and the'
            . ' page silently inlines the critical-CSS fallback instead.',
        );
    }

    /**
     * The regression itself, measured: the bytes in the document are the bytes
     * in `public/dist/erik/assets/erik-*.css`.
     */
    #[Test]
    public function layoutInlinesTheBuiltStylesheetAndNotTheCriticalSubset(): void
    {
        $assets = $this->requireBuiltBundle();

        $build = (string) file_get_contents($this->assetPath($assets->cssUrls(self::THEME)[0]));

        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);
        $inlined = $this->styleBlock($html);

        $this->assertSame(
            $build . "\n",
            $inlined,
            'The inlined CSS is not the theme build — the layout resolved another bundle.'
            . ' (The critical subset is ' . strlen(InlineCss::files((new ErikTheme())->criticalCss())) . ' chars.)',
        );

        // The fallback is what the defect served, so name it explicitly: an
        // equal-bytes assertion on the build is enough only as long as the
        // fallback is genuinely smaller — which this says out loud.
        $this->assertNotSame(
            InlineCss::files((new ErikTheme())->criticalCss()),
            $inlined,
            'The layout inlined the critical subset instead of the build.',
        );

        $this->assertGreaterThan(
            100,
            substr_count($inlined, '.' . self::PREFIX . '-'),
            'The inlined CSS carries almost none of the theme vocabulary.',
        );

        $this->assertStringContainsString(
            'src="/dist/' . self::THEME . '/assets/',
            $html,
            'The theme build is inlined, so its JS entry must be referenced too.',
        );
    }

    #[Test]
    public function documentWearsTheErikVocabularyAndNoSiblings(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);

        $this->assertStringContainsString('<body class="' . self::THEME . '-shell">', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-header"', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-main"', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-footer"', $html);

        foreach (['k-header', 'k-footer', 'mo-header', 'mo-footer'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, 'Another theme reached this document: ' . $foreign);
        }
    }

    /**
     * The one assertion that has to survive the module set changing: whichever
     * theme the providers vote for, the live surface serves THAT theme's build.
     *
     * Written against the resolver's answer rather than against `erik`, so
     * installing another theme provider re-points the test instead of breaking
     * it — while the defect (a page carrying the kernel fallback) still fails.
     */
    #[Test]
    public function liveSurfacesInlineTheActiveThemesOwnBuild(): void
    {
        $active = ThemeResolver::resolvePath('/');
        $theme = ThemeKernel::for($active);

        $this->assertContains(
            $theme->name(),
            AssetManager::knownBundles(),
            sprintf('The active theme `%s` is not a known asset bundle.', $theme->name()),
        );

        $assets = new AssetManager($theme->name());

        if (!$assets->isAvailable()) {
            $this->markTestSkipped(sprintf('No build for bundle `%s` — run `make assets`.', $theme->name()));
        }

        $build = (string) file_get_contents($this->assetPath($assets->cssUrls($theme->jsKey())[0]));

        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            $build,
            $response->getContent(),
            sprintf('The live homepage does not serve the `%s` build — it fell back to critical CSS.', $theme->name()),
        );
    }

    /**
     * A missing manifest on DISK is a build that has not run, not a defect —
     * `public/dist/` is gitignored. Skips with the command to run.
     *
     * The guard is the file, never `AssetManager::isAvailable()`: that call is
     * exactly what the defect breaks, so guarding on it turns the regression
     * into a skip instead of a failure — measured, and the reason this helper
     * reads the path itself before the assertions that follow.
     */
    private function requireBuiltBundle(): AssetManager
    {
        $manifest = AppPaths::instance()->public('dist/' . self::THEME . '/manifest.json');

        if (!is_file($manifest)) {
            $this->markTestSkipped('No `erik` build in public/dist/erik — run `make assets`.');
        }

        $this->assertContains(
            self::THEME,
            AssetManager::knownBundles(),
            'The build is on disk but the bundle is unknown, so AssetManager reads the kernel manifest instead.',
        );

        $assets = new AssetManager(self::THEME);

        $this->assertTrue(
            $assets->isAvailable(),
            'The `erik` build exists on disk but AssetManager did not resolve its manifest.',
        );

        return $assets;
    }

    private function assetPath(string $url): string
    {
        return AppPaths::instance()->public(ltrim($url, '/'));
    }

    /** The body of the single `<style>` the layout emits, without its trailing newline. */
    private function styleBlock(string $html): string
    {
        $matched = preg_match('~<style nonce="[^"]*">\n(.*?)\n</style>~s', $html, $matches);

        $this->assertSame(1, $matched, 'The document has no inlined <style> block.');

        return $matches[1];
    }
}
