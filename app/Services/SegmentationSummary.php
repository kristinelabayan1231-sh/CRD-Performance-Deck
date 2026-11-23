<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Product Performance → Segmentation Summary: the tracker's leads for a period added up (catered, converted,
 * Customer's Feedback, statuses, tags, contact hours, per CRA and per product).
 *
 * Catered = a status, a date of contact or marked catered. Converted = Repeat Purchase Yes, or No with
 * feedback PURCHASED (Lead::scopeConverted). Customer's Feedback counts every choice ticked, so a lead with two
 * counts in both; Top feedback per CRA / product is the most common main choice.
 */
class SegmentationSummary
{
    /**
     * @param  Builder<Lead>  $scope  the leads to sum up (period and filters already applied)
     * @param  Collection<int, User>  $cras  CRAs listed in the per-CRA table
     * @param  Builder<LeadMove>|null  $departed  leads the backlog fill moved off these days (LeadMove::departed()):
     *                                            counted as leads, pending, in the totals and per CRA / product
     * @return array<string, mixed>
     */
    public function for(Builder $scope, Collection $cras, ?Builder $departed = null): array
    {
        $departedPer = fn (string $column) => $departed
            ? (clone $departed)->selectRaw("{$column} as owner, count(*) as n")->groupBy('owner')->toBase()->pluck('n', 'owner')
            : collect();
        $departedPerCra = $departedPer('lead_moves.assigned_to');
        $departedPerProduct = $departedPer('leads.product_name');

        $catered = '(status is not null or contact_date is not null or processed_at is not null)';
        $converted = "(repeat_purchase = 'yes' or (repeat_purchase = 'no' and feedback = 'purchased'))";
        $sum = fn (string $condition) => "sum(case when {$condition} then 1 else 0 end)";

        $totals = (clone $scope)
            ->selectRaw('count(*) as leads')
            ->selectRaw("{$sum($catered)} as catered")
            ->selectRaw("{$sum($converted)} as converted")
            ->selectRaw("{$sum('feedback is not null')} as with_feedback")
            ->toBase()->first();
        $leads = (int) $totals->leads + (int) $departedPerCra->sum();

        $countBy = fn (string $column) => (clone $scope)->whereNotNull($column)
            ->selectRaw("{$column} as value, count(*) as total")->groupBy($column)->toBase()->pluck('total', 'value')
            ->map(fn ($total) => (int) $total);

        $byHour = (clone $scope)->whereNotNull('contact_time')
            ->selectRaw('contact_time as hour, count(*) as contacts')
            ->selectRaw("{$sum($converted)} as converted")
            ->groupBy('contact_time')->toBase()->get()->keyBy('hour');

        $perCra = (clone $scope)->whereIn('assigned_to', $cras->pluck('id'))
            ->selectRaw('assigned_to, count(*) as leads')
            ->selectRaw("{$sum($catered)} as catered")
            ->selectRaw("{$sum($converted)} as converted")
            ->groupBy('assigned_to')->toBase()->get()->keyBy('assigned_to');
        $topFeedbackBy = fn (string $column) => (clone $scope)->whereNotNull('feedback')
            ->selectRaw("{$column} as owner, feedback, count(*) as total")->groupBy($column, 'feedback')->toBase()->get()
            ->groupBy('owner')->map(fn (Collection $rows) => $rows->sortByDesc('total')->first()->feedback);
        $craFeedback = $topFeedbackBy('assigned_to');

        $perProduct = (clone $scope)
            ->selectRaw('product_name, count(*) as leads')
            ->selectRaw("{$sum($catered)} as catered")
            ->selectRaw("{$sum($converted)} as converted")
            ->groupBy('product_name')->orderByDesc('leads')->toBase()->get();
        $productFeedback = $topFeedbackBy('product_name');

        // Every choice ticked on the leads with feedback, in all and per product.
        $feedbackRows = (clone $scope)->whereNotNull('feedback')->toBase()->get(['product_name', 'phone_number', 'qty', 'feedback', 'feedbacks'])
            ->map(fn (object $row) => [
                'product_name' => $row->product_name, 'phone' => $row->phone_number, 'qty' => (int) $row->qty,
                'all' => Lead::storedFeedbacks($row->feedbacks, $row->feedback),
            ]);
        // Customers (by contact number), shipments (leads: one delivered order each) and their Qty (pieces), per product
        // and per choice ticked.
        $customersAndQty = fn (Collection $rows) => ['customers' => $rows->pluck('phone')->unique()->count(), 'shipments' => $rows->count(), 'qty' => $rows->sum('qty')];

        $rate = fn (int $part, int $whole) => $whole > 0 ? $part / $whole : null;

        $summary = [
            'leads' => $leads,
            'catered' => (int) $totals->catered,
            'pending' => $leads - (int) $totals->catered,
            'converted' => (int) $totals->converted,
            'with_feedback' => (int) $totals->with_feedback,
            'catered_rate' => $rate((int) $totals->catered, $leads),
            'converted_rate' => $rate((int) $totals->converted, $leads),
            'feedback_rate' => $rate((int) $totals->with_feedback, (int) $totals->catered),
            'feedback_picks' => $feedbackRows->flatMap(fn (array $row) => $row['all'])->countBy(),
            'feedback_picks_by_product' => $feedbackRows->groupBy('product_name')
                ->map(fn (Collection $rows) => $rows->flatMap(fn (array $row) => $row['all'])->countBy()->sortDesc())
                ->sortByDesc(fn (Collection $counts) => $counts->sum()),
            'feedback_reasons_by_product' => $feedbackRows->groupBy('product_name')
                ->map(fn (Collection $rows) => [
                    ...$customersAndQty($rows),
                    'reasons' => $rows->flatMap(fn (array $row) => array_map(fn (string $key) => [...$row, 'reason' => $key], $row['all']))
                        ->groupBy('reason')->map($customersAndQty)->sortByDesc('customers'),
                ])
                ->sortByDesc('customers'),
            'statuses' => $countBy('status'),
            'tags' => $countBy('customer_tag'),
            'repeat_purchase' => $countBy('repeat_purchase'),
            'hours' => collect(config('segmentation.contact_times'))->map(fn (string $label, string $hour) => [
                'label' => $label,
                'contacts' => (int) ($byHour[$hour]->contacts ?? 0),
                'converted' => (int) ($byHour[$hour]->converted ?? 0),
            ]),
            'cras' => $cras->map(function (User $cra) use ($perCra, $craFeedback, $rate, $departedPerCra) {
                $row = $perCra[$cra->id] ?? null;
                $craLeads = (int) ($row->leads ?? 0) + (int) ($departedPerCra[$cra->id] ?? 0);

                return [
                    'name' => $cra->displayName(),
                    'leads' => $craLeads,
                    'catered_rate' => $rate((int) ($row->catered ?? 0), $craLeads),
                    'converted' => (int) ($row->converted ?? 0),
                    'converted_rate' => $rate((int) ($row->converted ?? 0), $craLeads),
                    'top_feedback' => $craFeedback[$cra->id] ?? null,
                ];
            })->values(),
            'products' => $perProduct->map(fn (object $row) => [...(array) $row, 'leads' => (int) $row->leads + (int) ($departedPerProduct[$row->product_name] ?? 0)])
                ->map(fn (array $row) => (object) $row)->sortByDesc('leads')->values()->map(fn (object $row) => [
                    'name' => $row->product_name,
                    'leads' => (int) $row->leads,
                    'catered_rate' => $rate((int) $row->catered, (int) $row->leads),
                    'converted' => (int) $row->converted,
                    'converted_rate' => $rate((int) $row->converted, (int) $row->leads),
                    'top_feedback' => $productFeedback[$row->product_name] ?? null,
                ]),
        ];

        return [...$summary, 'feedback_reasons' => $this->feedbackReasons($scope)];
    }

    /**
     * Why customers gave each Customer's Feedback choice (all but PURCHASED), from the CRAs' notes, with every note,
     * latest contact first. NO VERBAL CONV: each lead in one group, the first whose keyword is in the note
     * (config segmentation.no_verbal_reasons); a lead with no note goes by its status's label the same way, else under
     * its status, else "No note"; a note matching none is "Other". Every other choice: a note counts in each reason with
     * a keyword in it (config segmentation.feedback_reasons); one matching none is "Other", a lead with no note "No note".
     * A lead with two choices is under both.
     *
     * @param  Builder<Lead>  $scope
     * @return array<string, array{reasons: list<array{key: string, label: string, total: int}>, total: int, notes: list<array{customer: string, cra: ?string, contacted: ?string, status: ?string, product: ?string, note: ?string, reasons: list<string>, keywords: list<string>}>}>
     */
    private function feedbackReasons(Builder $scope): array
    {
        $statuses = config('segmentation.statuses');
        $noVerbal = config('segmentation.no_verbal_reasons');
        $others = config('segmentation.feedback_reasons');
        $labels = [
            ...array_map(fn (array $group) => $group[0], $noVerbal), ...array_map(fn (array $group) => $group[0], $others),
            'other' => 'Other', 'no_note' => 'No note',
        ];
        $statusLabel = fn (?string $status) => $status ? ($statuses[$status][0] ?? $status) : null;
        // Every group whose keyword is in $text: [group => the keyword found].
        $matches = function (string $text, array $groups): array {
            $text = mb_strtolower($text);
            $found = [];
            foreach ($groups as $key => [, $keywords]) {
                foreach ($keywords as $keyword) {
                    if (str_contains($text, mb_strtolower($keyword))) {
                        $found[$key] = $keyword;

                        break;
                    }
                }
            }

            return $found;
        };

        $noVerbalReason = function (?string $note, ?string $status) use ($matches, $noVerbal, $statusLabel, &$labels): array {
            if (trim((string) $note) !== '') {
                $found = $matches($note, $noVerbal);

                return $found ? [array_key_first($found), [reset($found)]] : ['other', []];
            }
            if (! $status) {
                return ['no_note', []];
            }
            if ($found = $matches((string) $statusLabel($status), $noVerbal)) {
                return [array_key_first($found), []];
            }
            $labels['status_'.$status] = $statusLabel($status);

            return ['status_'.$status, []];
        };

        $byChoice = [];
        $leads = (clone $scope)->whereNotNull('feedback')->with('assignee')
            ->orderByDesc('contact_date')->orderByDesc('id')
            ->get(['id', 'customer_name', 'assigned_to', 'contact_date', 'status', 'product_name', 'notes', 'feedback', 'feedbacks']);

        foreach ($leads as $lead) {
            $note = trim((string) $lead->notes) === '' ? null : $lead->notes;
            $row = [
                'customer' => $lead->customer_name,
                'cra' => $lead->assignee?->displayName(),
                'contacted' => $lead->contact_date?->format('M j'),
                'status' => $statusLabel($lead->status),
                'product' => $lead->product_name,
                'note' => $note,
            ];

            foreach ($lead->feedbackKeys() as $choice) {
                if ($choice === 'purchased') {
                    continue;
                }
                if ($choice === 'no_verbal_conv') {
                    [$reason, $keywords] = $noVerbalReason($note, $lead->status);
                    $byChoice[$choice][] = [...$row, 'reasons' => [$reason], 'keywords' => $keywords];

                    continue;
                }
                $found = $note === null ? [] : $matches($note, $others);
                $byChoice[$choice][] = [...$row, 'reasons' => $found ? array_keys($found) : [$note === null ? 'no_note' : 'other'], 'keywords' => array_values($found)];
            }
        }

        return collect($byChoice)->map(function (array $notes) use ($labels) {
            $order = array_flip(array_keys($labels));

            return [
                'total' => count($notes),
                'reasons' => collect($notes)->flatMap(fn (array $note) => $note['reasons'])->countBy()
                    ->sortBy(fn (int $total, string $key) => [-$total, $order[$key] ?? PHP_INT_MAX])
                    ->map(fn (int $total, string $key) => ['key' => $key, 'label' => $labels[$key] ?? $key, 'total' => $total])->values()->all(),
                'notes' => $notes,
            ];
        })->all();
    }
}
