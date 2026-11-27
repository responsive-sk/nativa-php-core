<?php

declare(strict_types = 1);

namespace Modules\Reservations\Application\Handlers;

use Modules\Forms\Domain\Contracts\FormSubmissionHandler;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Infrastructure\Attributes\AsFormSubmissionHandler;
use Modules\Reservations\Application\ReservationsServices;

/**
 * ReservationFormSubmissionHandler - turns an order form into a reservation.
 *
 * The handler is discovered by Forms only when this module is installed. It
 * reacts to the answers rather than to a hardcoded slug: a submission with a
 * date, a place, a name and an e-mail is an order, and every other submission
 * is left to its own module. The form title supplies the service when the
 * form has no `service` field.
 */
#[AsFormSubmissionHandler]
final readonly class ReservationFormSubmissionHandler implements FormSubmissionHandler
{
    public function handle(Form $form, FormSubmission $submission): void
    {
        ReservationsServices::service()->record($form, $submission);
    }
}
