<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Attributes;

use Attribute;

/**
 * Marks a class as an auth provider — the attribute `AuthProviderRegistry`
 * discovers, the same way `#[AsMiddleware]` is discovered for the pipeline
 * and `#[AsShellContextProvider]` for the shell.
 *
 * A module that owns authentication implements `AuthProvider` and marks the
 * class with this. Nothing else registers it: a provider that has to be
 * listed somewhere is a provider a removed module leaves behind in that list.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsAuthProvider
{
    public function __construct(
        /** Lower sorts first; ties keep discovery order. */
        public int $priority = 0,
    ) {
    }
}
