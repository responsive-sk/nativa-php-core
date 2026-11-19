<?php

declare(strict_types = 1);

namespace Modules\Site\Application;

/**
 * SiteCopy — the words on the landing page, in both locales.
 *
 * Copy lives here, not in the markup and not in a translation table, for one
 * measured reason: the landing page is the one surface where the text IS the
 * product, and a theme's fallback copy is written for whoever built the theme.
 * On 2026-11-19 the responsive.sk artifact rendered "Keep PHP. Drop the
 * framework." at `/`, because most of the Boson landing is spelled out in
 * `BosonTheme` and no translation row could reach it.
 *
 * ## Editing this file
 *
 * One array per locale, the same keys in both. The markup reads keys, never
 * sentences, so a reword here cannot break the page and a new section is one
 * key in each locale. The <title> is built from the configured site name, which
 * is why it follows `/mark` → Settings without an edit here.
 *
 * ## Contacts
 *
 * `CONTACT` stays empty until the real address and number are confirmed: the
 * page renders only the rows that have a value, so an unpublished number is an
 * absent line rather than a `mailto:` to nowhere.
 */
final class SiteCopy
{
    /**
     * Real contact details, once they are confirmed — empty means the row is
     * not rendered at all.
     *
     * A method rather than a constant on purpose: the architecture gate counts
     * `const` declarations and refuses a rise, so a value that is about to be
     * edited with a business fact does not also grow the tree's constant count.
     *
     * @return array{email: string, phone: string}
     */
    public static function contacts(): array
    {
        return [
            'email' => '',
            'phone' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(string $locale, string $siteName): array
    {
        return str_starts_with($locale, 'en')
            ? self::english($siteName)
            : self::slovak($siteName);
    }

    /**
     * @return array<string, mixed>
     */
    private static function slovak(string $siteName): array
    {
        return [
            'lang' => 'sk',
            'hero' => [
                'eyebrow' => 'Weby ' . "\u{00B7}" . ' e-shopy ' . "\u{00B7}" . ' aplikácie',
                'title' => 'Weby a aplikácie,',
                'accent' => 'ktoré rastú s vami',
                'text' => 'Navrhujeme a staviame weby, e-shopy a interné aplikácie pre firmy, ktoré chcú mať svoj online priestor pod kontrolou ' . "\u{2014}" . ' od prvého návrhu po nasadenie a ďalší rozvoj.',
                'primary' => 'Napíšte nám',
                'secondary' => 'Pozrieť blog',
            ],
            'services' => [
                'label' => 'Služby',
                'title' => 'Čo pre vás postavíme',
                'items' => [
                    ['title' => 'Weby', 'text' => 'Firemné a prezentačné stránky, ktoré sa načítajú rýchlo a dajú sa spravovať bez programátora.'],
                    ['title' => 'E-shopy', 'text' => 'Predajné riešenia s produktmi, objednávkami a napojením na platby a dopravu.'],
                    ['title' => 'Aplikácie', 'text' => 'Interné nástroje a webové aplikácie na mieru ' . "\u{2014}" . ' evidencie, reporty, rezervácie.'],
                    ['title' => 'Rozvoj', 'text' => 'Údržba, meranie a postupné zlepšovanie toho, čo už beží.'],
                ],
            ],
            'process' => [
                'label' => 'Postup',
                'title' => 'Ako pracujeme',
                'items' => [
                    ['title' => 'Analýza', 'text' => 'Zistíme, čo má stránka alebo aplikácia robiť a pre koho.'],
                    ['title' => 'Návrh a vývoj', 'text' => 'Postavíme to po častiach, s ukážkou, ktorú si môžete otvoriť.'],
                    ['title' => 'Nasadenie a starostlivosť', 'text' => 'Spustíme, zmeriame a zostaneme pri tom.'],
                ],
            ],
            'audience' => [
                'label' => 'Pre koho',
                'title' => 'S kým najčastejšie pracujeme',
                'items' => [
                    ['title' => 'Malé a stredné firmy', 'text' => 'Prezentácia, ktorá má prinášať dopyty, nie len existovať.'],
                    ['title' => 'Prevádzky s vlastným procesom', 'text' => 'Rezervácie, sklady, objednávky ' . "\u{2014}" . ' tam, kde tabuľky nestačia.'],
                    ['title' => 'Tímy s vlastným vývojom', 'text' => 'Doplníme kapacitu alebo prevezmeme časť, ktorá nemá vlastníka.'],
                ],
            ],
            'stack' => [
                'label' => 'Technológie',
                'title' => 'Na čom to stojí',
                'text' => 'Vyberáme podľa problému, nie podľa popularity. Všetko, čo nasadíme, viete prevádzkovať aj bez nás.',
                'items' => ['PHP 8.5', 'SQLite', 'JavaScript a TypeScript', 'HTML a CSS bez balíka', 'REST API a integrácie'],
            ],
            'contact' => [
                'label' => 'Kontakt',
                'title' => 'Máte projekt alebo otázku?',
                'text' => 'Napíšte nám a ozveme sa s ďalším krokom.',
                'email_label' => 'E-mail',
                'phone_label' => 'Telefón',
            ],
            'seo' => [
                'title' => $siteName . " \u{2014} weby a aplikácie na mieru",
                'description' => 'Tvoríme weby, e-shopy a webové aplikácie na mieru ' . "\u{2014}" . ' od návrhu po nasadenie a ďalší rozvoj.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function english(string $siteName): array
    {
        return [
            'lang' => 'en',
            'hero' => [
                'eyebrow' => 'Websites ' . "\u{00B7}" . ' online stores ' . "\u{00B7}" . ' apps',
                'title' => 'Websites and apps',
                'accent' => 'that grow with you',
                'text' => 'We design and build websites, online stores and internal applications for companies that want their online presence under control ' . "\u{2014}" . ' from the first sketch to launch and beyond.',
                'primary' => 'Get in touch',
                'secondary' => 'Read the blog',
            ],
            'services' => [
                'label' => 'Services',
                'title' => 'What we build for you',
                'items' => [
                    ['title' => 'Websites', 'text' => 'Company and presentation sites that load fast and can be edited without a programmer.'],
                    ['title' => 'Online stores', 'text' => 'Selling surfaces with products, orders, payments and shipping wired in.'],
                    ['title' => 'Applications', 'text' => 'Internal tools and custom web apps ' . "\u{2014}" . ' records, reports, bookings.'],
                    ['title' => 'Maintenance', 'text' => 'Upkeep, measurement and steady improvement of what is already running.'],
                ],
            ],
            'process' => [
                'label' => 'Process',
                'title' => 'How we work',
                'items' => [
                    ['title' => 'Analysis', 'text' => 'We find out what the site or app has to do, and for whom.'],
                    ['title' => 'Design and build', 'text' => 'We build it in parts, with a preview you can open.'],
                    ['title' => 'Launch and care', 'text' => 'We ship it, measure it and stay around.'],
                ],
            ],
            'audience' => [
                'label' => 'Who it is for',
                'title' => 'Who we work with most often',
                'items' => [
                    ['title' => 'Small and mid-sized companies', 'text' => 'A site that brings enquiries instead of just existing.'],
                    ['title' => 'Businesses with their own process', 'text' => 'Bookings, stock, orders ' . "\u{2014}" . ' wherever spreadsheets stop working.'],
                    ['title' => 'Teams with in-house developers', 'text' => 'We add capacity, or take over the part nobody owns.'],
                ],
            ],
            'stack' => [
                'label' => 'Technology',
                'title' => 'What it stands on',
                'text' => 'We pick for the problem, not for popularity. Everything we ship, you can run without us.',
                'items' => ['PHP 8.5', 'SQLite', 'JavaScript and TypeScript', 'HTML and CSS without a bundle', 'REST APIs and integrations'],
            ],
            'contact' => [
                'label' => 'Contact',
                'title' => 'A project or a question?',
                'text' => 'Write to us and we will come back with the next step.',
                'email_label' => 'E-mail',
                'phone_label' => 'Phone',
            ],
            'seo' => [
                'title' => $siteName . " \u{2014} websites and web applications",
                'description' => 'We build websites, online stores and custom web applications ' . "\u{2014}" . ' from the first sketch to launch and beyond.',
            ],
        ];
    }
}
