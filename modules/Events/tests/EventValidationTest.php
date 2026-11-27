<?php

declare(strict_types = 1);

namespace Modules\Events\Tests;

use Modules\Events\Domain\Validation\EventValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventValidationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Events')) {
            $this->markTestSkipped('Events is not installed.');
        }
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even after a skipped setUp(); there is no
        // state to unwind here, but the guard keeps the pattern explicit.
    }

    #[Test]
    public function anEndBeforeTheStartIsRefused(): void
    {
        $errors = EventValidator::errors(self::data([
            'starts_at' => '2026-08-10 18:00',
            'ends_at'   => '2026-08-10 17:00',
        ]));

        self::assertContains('End time cannot be before the start time.', $errors);
    }

    #[Test]
    public function aMissingTitleAndBadCapacityAreRefused(): void
    {
        $errors = EventValidator::errors(self::data([
            'title'    => '',
            'capacity' => 0,
        ]));

        self::assertContains('Title is required.', $errors);
        self::assertContains('Capacity must be a whole number greater than zero.', $errors);
    }

    #[Test]
    public function aSlugMustBeALowercasePathSegment(): void
    {
        self::assertContains(
            'Slug may contain only lowercase letters, digits, hyphens and underscores.',
            EventValidator::errors(self::data(['slug' => 'Letny Tabor'])),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function data(array $overrides = []): array
    {
        return array_replace([
            'slug'      => 'letny-tabor',
            'title'     => 'Letný tábor',
            'starts_at' => '2026-08-10 18:00',
            'ends_at'   => '2026-08-10 20:00',
            'capacity'  => 20,
        ], $overrides);
    }
}
