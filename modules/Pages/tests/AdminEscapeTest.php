<?php

declare(strict_types = 1);

namespace Modules\Pages\Tests;

use Modules\Pages\Interfaces\HTTP\PagesTableRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminEscapeTest — the pages table re-emits the admin `?locale=` param
 * into row URLs. The action allowlists it at the boundary, and the renderer
 * escapes it again at entry (defense in depth for future callers). row() is
 * public, so the test drives it directly with a hostile locale.
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * modules are not installed.
 */
final class AdminEscapeTest extends TestCase
{
    #[Test]
    public function rowNeutralizesHostileLocale(): void
    {
        foreach (['Pages', 'Cardboard', 'Locales'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $evil = '"><svg onload=alert(1)>';

        $html = PagesTableRenderer::row(
            ['slug' => 'about', 'title' => 'About', 'published' => true],
            $evil,
        );

        $this->assertStringNotContainsString($evil, $html);
        $this->assertStringContainsString('&quot;', $html);
    }
}
