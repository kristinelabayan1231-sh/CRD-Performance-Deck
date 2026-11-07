<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\PancakeReservation;
use App\Support\WorkingDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Pending full view's follow-up lists, in the Segmentation Tracker and the Exclusive Lead Tracker: callbacks due
 * today, Reactivation, Reserved and Reserved - Pancake. They're catered leads, so they're never in Pending itself.
 * Callbacks and Reactivation never overlap: Reactivation leaves out leads with a callback date still to be followed,
 * and Reserved ones.
 */
class LeadFollowUps
{
    /**
     * @param  Builder<Lead>  $leads  the leads of the page's CRA, type and product filters, any lead day
     * @param  Builder<PancakeReservation>  $reservations  the reservations of those same filters
     * @return array{callbacks: LengthAwarePaginator, reactivation: LengthAwarePaginator, reserved: LengthAwarePaginator, pancakeReserved: LengthAwarePaginator}
     */
    public function lists(Builder $leads, Builder $reservations, int $perPage): array
    {
        $realToday = WorkingDate::realToday();
        $leads = $leads->with(['assignee', 'notesAuthor', 'transfers.fromUser', 'callRecordings', 'attempts.user']);

        return [
            'callbacks' => (clone $leads)->callbackDue($realToday)->orderBy('callback_date')->orderBy('customer_name')->paginate($perPage, ['*'], 'callback_page')->withQueryString(),
            'reactivation' => (clone $leads)->reactivation(Lead::today(), $realToday)
                ->orderBy('listed_on')->orderBy('customer_name')
                ->paginate($perPage, ['*'], 'reactivation_page')->withQueryString(),
            'reserved' => (clone $leads)->reserved()
                ->orderBy('listed_on')->orderBy('customer_name')
                ->paginate($perPage, ['*'], 'reserved_page')->withQueryString(),
            // Reserved in a Pancake conversation, added by the CRAs; latest first.
            'pancakeReserved' => $reservations
                ->with(['assignee', 'notesAuthor'])
                ->latest('id')
                ->paginate($perPage, ['*'], 'pancake_reserved_page')->withQueryString(),
        ];
    }
}
