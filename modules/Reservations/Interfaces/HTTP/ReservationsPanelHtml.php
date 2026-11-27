<?php

declare(strict_types = 1);

namespace Modules\Reservations\Interfaces\HTTP;

use Modules\Cardboard\Interfaces\HTTP\CardboardLayout;
use Modules\Reservations\Domain\Entities\Reservation;
use Modules\Reservations\Domain\Model\ReservationStatus;

/**
 * ReservationsPanelHtml - the Mark inbox's markup.
 *
 * The action owns the read and the redirects; this class owns the table, the
 * escaped answers and the two decision forms. A reservation's customer text is
 * data, never markup.
 */
final class ReservationsPanelHtml
{
    /**
     * @param list<Reservation> $reservations
     * @param \Closure(string, array<string, string|int>): string $url
     */
    public static function index(array $reservations, \Closure $url): string
    {
        $counts = [
            ReservationStatus::New->label()       => 0,
            ReservationStatus::Confirmed->label() => 0,
            ReservationStatus::Cancelled->label() => 0,
        ];

        foreach ($reservations as $reservation) {
            $label = $reservation->status->label();
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $content = '<p class="m-muted">'
            . $counts[ReservationStatus::New->label()] . ' new, '
            . $counts[ReservationStatus::Confirmed->label()] . ' confirmed, '
            . $counts[ReservationStatus::Cancelled->label()] . ' cancelled.</p>';

        if ([] === $reservations) {
            return $content . CardboardLayout::emptyState(
                'No reservations yet',
                'An order form appears here after a customer submits it.',
            );
        }

        $rows = '';

        foreach ($reservations as $reservation) {
            $rows .= self::row($reservation, $url);
        }

        return $content . '<div class="m-card"><table class="m-table"><thead><tr>'
            . '<th>Received</th><th>Customer</th><th>Order</th><th>Status</th><th>Actions</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * @param \Closure(string, array<string, string|int>): string $url
     */
    private static function row(Reservation $reservation, \Closure $url): string
    {
        $order = '<strong>' . CardboardLayout::e($reservation->service) . '</strong><br>'
            . CardboardLayout::e($reservation->eventDate);

        if ('' !== $reservation->eventPlace) {
            $order .= '<br><span class="m-muted">' . CardboardLayout::e($reservation->eventPlace) . '</span>';
        }

        if ('' !== $reservation->message) {
            $order .= '<br><span class="m-muted">' . nl2br(CardboardLayout::e($reservation->message)) . '</span>';
        }

        $actions = '';

        if ($reservation->status->isNew()) {
            $actions .= self::postButton(
                $url('mark.reservations.confirm', ['id' => $reservation->id]),
                'Confirm',
            );
        }

        if (!$reservation->status->isCancelled()) {
            $actions .= self::postButton(
                $url('mark.reservations.cancel', ['id' => $reservation->id]),
                'Cancel',
                'Cancel this reservation?',
            );
        }

        return '<tr>'
            . '<td>' . CardboardLayout::e($reservation->createdAt->format('Y-m-d H:i')) . '</td>'
            . '<td>' . CardboardLayout::e($reservation->name)
            . '<br><span class="m-muted">' . CardboardLayout::e($reservation->email) . '</span>'
            . ('' === $reservation->phone ? '' : '<br><span class="m-muted">' . CardboardLayout::e($reservation->phone) . '</span>')
            . '</td>'
            . '<td>' . $order . '<br><span class="m-muted">' . CardboardLayout::e($reservation->formSlug) . ' / ' . CardboardLayout::e($reservation->locale) . '</span></td>'
            . '<td><span class="m-badge">' . CardboardLayout::e($reservation->status->label()) . '</span></td>'
            . '<td>' . $actions . '</td>'
            . '</tr>';
    }

    private static function postButton(string $action, string $label, ?string $confirm = null): string
    {
        $onsubmit = null === $confirm ? '' : ' onsubmit="return confirm(\'' . CardboardLayout::e($confirm) . '\')"';

        return '<form method="POST" action="' . CardboardLayout::e($action) . '" style="display:inline"' . $onsubmit . '>'
            . CardboardLayout::csrfField()
            . '<button class="m-btn m-btn-ghost" type="submit">' . CardboardLayout::e($label) . '</button></form> ';
    }
}
