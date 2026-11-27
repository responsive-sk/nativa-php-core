<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Contracts;

use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;

/**
 * FormSubmissionHandler - a module's reaction to a stored form submission.
 *
 * Forms owns the definition, the validation and the stored row. A module that
 * needs to turn one kind of submission into its own record joins through this
 * port instead of editing Forms or reading forms.db behind its back.
 *
 * The submission is already stored when a handler runs. A handler failure is
 * therefore logged and skipped, never allowed to turn a successful form
 * submission into a 500.
 */
interface FormSubmissionHandler
{
    public function handle(Form $form, FormSubmission $submission): void;
}
