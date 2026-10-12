<?php

declare(strict_types = 1);

namespace Modules\Locales\Tests;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Locales\Application\Services\LocaleService;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Locales\Interfaces\HTTP\LocalesShellContextProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LocalesShellContextProviderTest — the locale answers of the shell port.
 *
 * Settings echoes the raw stored key; this provider answers the RESOLVED
 * default, so the suite pins the resolution here with explicit closures
 * (no database): a stored valid code resolves, garbage falls back to the
 * built-in default, and htmlLang is always the language part of whatever
 * defaultLocale answered. The live stored-value matrix belongs to the
 * Settings provider test, which owns a temp-DB harness for it.
 */
final class LocalesShellContextProviderTest extends TestCase
{
    private const string MODULE = 'Locales';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the locale answers ship with this module.');
        }

        LocalesServices::reset();
    }

    protected function tearDown(): void
    {
        LocalesServices::reset();

        parent::tearDown();
    }

    #[Test]
    public function languagesAreTheAvailableSet(): void
    {
        $this->assertSame(
            array_keys(Locale::available()),
            (new LocalesShellContextProvider())->languages(),
            'The switcher union must be the available set itself, not a second list that drifts.',
        );
        $this->assertContains('sk_SK', (new LocalesShellContextProvider())->languages());
    }

    #[Test]
    public function htmlLangIsTheLanguageOfTheAnsweredDefault(): void
    {
        $provider = new LocalesShellContextProvider();
        $default = $provider->defaultLocale();

        $this->assertIsString($default);
        $this->assertSame(explode('_', $default)[0], $provider->htmlLang());
    }

    #[Test]
    public function everythingButLocaleAbstains(): void
    {
        $provider = new LocalesShellContextProvider();

        $this->assertNull($provider->siteName());
        $this->assertNull($provider->theme());
        $this->assertSame([], $provider->navigation('header', '/'));
        $this->assertSame([], $provider->navigation('footer', '/blog'));
    }

    #[Test]
    public function theStoredLocaleVoteSortsAfterSettings(): void
    {
        $names = array_map(
            static fn (object $provider): string => $provider::class,
            ShellContextProviderRegistry::all(),
        );

        $settings = array_search('Modules\\Settings\\Interfaces\\HTTP\\SettingsShellContextProvider', $names, true);
        $locales = array_search(LocalesShellContextProvider::class, $names, true);

        $this->assertIsInt($settings, 'The Settings provider must be installed for the ordering pin.');
        $this->assertIsInt($locales);
        $this->assertLessThan($locales, $settings, 'Settings (-100) votes first; Locales is the backstop, never the override.');
    }

    /**
     * The resolution the provider delegates to: valid stored codes resolve,
     * anything else falls back to the built-in default — never null, never
     * a fatal, pinned here without a database.
     */
    #[Test]
    public function resolutionFallsBackToTheBuiltInDefault(): void
    {
        $this->assertSame('en_US', (new LocaleService(static fn (): ?string => 'en_US'))->default()->code());
        $this->assertSame('sk_SK', (new LocaleService(static fn (): ?string => 'xx_YY'))->default()->code());
        $this->assertSame('sk_SK', (new LocaleService(static fn (): ?string => null))->default()->code());
        $this->assertSame('sk_SK', (new LocaleService())->default()->code(), 'No resolver at all still answers Slovak.');
    }
}
