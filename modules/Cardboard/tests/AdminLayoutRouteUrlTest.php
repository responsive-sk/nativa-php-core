<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminLayoutRouteUrlTest — templates ask for a route NAME.
 *
 * Task 2.9: `dashboard-audit.phtml` (and the other admin fragments) spelled
 * `/mark/...` by hand. They receive `$url` from the render context now, and
 * this test renders the real template through the real Router: the path in the
 * output comes from the route table, not from the template.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminLayoutRouteUrlTest extends TestCase
{
    #[Test]
    public function aTemplateResolvesLinksThroughTheInjectedUrlBuilder(): void
    {
        $router = Router::fromEntries([], [
            ['path' => '/mark/users/create', 'method' => 'GET', 'name' => 'mark.users.create', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/articles/create', 'method' => 'GET', 'name' => 'articles.create', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/pages/create', 'method' => 'GET', 'name' => 'pages.create', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/media/upload', 'method' => 'GET', 'name' => 'media.upload', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/settings', 'method' => 'GET', 'name' => 'mark.settings', 'handlerMethod' => null, 'action' => self::class],
        ]);

        $html = ViewRenderer::render(
            'Modules/Cardboard/Interfaces/HTTP/templates/dashboard-audit.phtml',
            ['url' => AdminLayout::urlBuilder($router)],
        );

        self::assertStringContainsString('href="/mark/users/create"', $html);
        self::assertStringContainsString('href="/mark/articles/create"', $html);
        self::assertStringContainsString('href="/mark/pages/create"', $html);
        self::assertStringContainsString('href="/mark/media/upload"', $html);
        self::assertStringContainsString('href="/mark/settings"', $html);
    }

    /**
     * The module list is a separate page (`mark.settings.modules`), so its
     * entry in the settings tab bar must be a LINK rendered with the same
     * `m-tabs__btn` class — not a JS panel button that has no panel.
     */
    #[Test]
    public function theSettingsTabsLinkToTheModulesScreen(): void
    {
        $html = ViewRenderer::render(
            'Modules/Cardboard/Interfaces/HTTP/templates/settings-form.phtml',
            [
                'url'            => static fn (string $name): string => 'mark.settings.modules' === $name ? '/mark/settings/modules' : '/route/' . $name,
                'errorHtml'      => '',
                'csrf'           => '',
                'siteName'       => '',
                'siteDescription' => '',
                'adminEmail'     => '',
                'timezone'       => '',
                'locale'         => '',
                'layoutSelect'   => '',
                'themeSelect'    => '',
                'regChecked'     => '',
                'tokenTable'     => '',
                'navTable'       => '',
                'adminNavTable'  => '',
                'iconOptions'    => '',
                'parentOptions'  => '',
            ],
        );

        self::assertMatchesRegularExpression(
            '/<a class="m-tabs__btn" href="\/mark\/settings\/modules">Modules<\/a>/',
            $html,
            'The Modules entry must be a tab-styled link to its own route.',
        );
    }

    /**
     * Task 2.9: the bulk bar is rendered by an Application-layer service but
     * its form targets are admin navigation — the caller's Router-owned URLs
     * must reach the markup unchanged, with nothing left to spell /mark.
     */
    #[Test]
    public function theBulkBarRendersTheUrlsTheCallerResolved(): void
    {
        $html = BulkActions::actionBarWithPublish(
            'articles',
            '/routes/bulk-publish',
            '/routes/bulk-delete',
            'sk',
        );

        self::assertStringContainsString('action="/routes/bulk-publish"', $html);
        self::assertStringContainsString('action="/routes/bulk-delete"', $html);
        self::assertStringNotContainsString('/mark', $html);
    }

    #[Test]
    public function thePublishSwitchRendersTheUrlTheCallerResolved(): void
    {
        $html = BulkActions::markSwitch(
            '/routes/articles/publish',
            'sk',
            '',
            true,
            ['draft' => 'Draft', 'published' => 'Published', 'tooltip_publish' => 'Publish', 'tooltip_unpublish' => 'Unpublish'],
        );

        self::assertStringContainsString('action="/routes/articles/publish?locale=sk"', $html);
        self::assertStringContainsString('hx-post="/routes/articles/publish?locale=sk"', $html);
        self::assertStringNotContainsString('/mark', $html);
    }

    #[Test]
    public function aLayoutWithoutARouterRefusesToBuildALink(): void
    {
        $url = AdminLayout::urlBuilder(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without a Router');

        $url('mark.settings');
    }
}
