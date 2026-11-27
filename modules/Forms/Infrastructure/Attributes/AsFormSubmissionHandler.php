<?php

declare(strict_types = 1);

namespace Modules\Forms\Infrastructure\Attributes;

/**
 * Marks a class as a form submission handler for auto-discovery.
 *
 * The priority orders handlers; the first handler that has something to do
 * with a submission does not stop the others, because two modules may each
 * keep a different derived record from one form.
 *
 * The class MUST implement FormSubmissionHandler and have no required
 * constructor parameters, like every other discovery port in this tree.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsFormSubmissionHandler
{
    public function __construct(
        public int $priority = 100,
    ) {
    }
}
