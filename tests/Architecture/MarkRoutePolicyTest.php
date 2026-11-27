<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Domain\MarkRoutePolicy;
use Modules\Core\Domain\Permissions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkRoutePolicyTest extends TestCase
{
    #[Test]
    public function aNonAdminPathIsDeniedAndEventsNeedsContent(): void
    {
        self::assertNull(
            MarkRoutePolicy::requiredPermission('/not-an-admin-path'),
            'A path outside the admin area has no admin permission.',
        );
        self::assertSame(Permissions::CONTENT, MarkRoutePolicy::requiredPermission('/mark/events'));
        self::assertSame(Permissions::CONTENT, MarkRoutePolicy::requiredPermission('/mark/events/letny-tabor/edit'));
        self::assertSame(Permissions::CONTENT, MarkRoutePolicy::requiredPermission('/mark/events/letny-tabor/reservations'));
    }
}
