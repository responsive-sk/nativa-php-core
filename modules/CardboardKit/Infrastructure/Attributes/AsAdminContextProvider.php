<?php

declare(strict_types = 1);

namespace Modules\CardboardKit\Infrastructure\Attributes;

use Attribute;

/**
 * Marks a class as the admin context provider — the attribute
 * AdminContextRegistry discovers, the same way #[AsShellContextProvider]
 * is discovered for the public shell.
 *
 * The class MUST implement AdminContextProvider and have no required
 * constructor parameters (same constraint as #[AsMiddleware]).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsAdminContextProvider
{
    public function __construct(
        /** Lower sorts first; ties keep discovery order. */
        public int $priority = 0,
    ) {
    }
}
