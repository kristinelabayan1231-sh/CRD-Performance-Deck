<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Segmentation Tracker → Summary: the tracker's leads for a period added up (catered, converted,
 * Customer's Feedback, statuses, tags, contact hours, per CRA and per product) with plain-language insights.
 *
 * Catered = a status, a date of contact or marked catered. Converted = Repeat Purchase Yes, or No with
 * feedback PURCHASED (Lead::scopeConverted).
 */
class SegmentationSummary
{
    /** Contact hours, products and CRAs need at least this many leads before an insight names them. */
    public const MIN_FOR_INSIGHT = 5;

    /** Products and CRAs are only compared when their rates differ by at least this much (5 points). */
    public const MIN_GAP_FOR_INSIGHT = 0.05;

    /**
     * @param  Builder<Lead>  $scope  the leads to sum up (period and filters already applied)
     * @param  Collection<int, User>  $cras  CRAs listed in the per-CRA table
     * @return array<string, mixed>
     */
    public function for(Builder $scope, Collection $cras): array
    {
        $catered = '(status is not null or contact_date is not null or processed_at is not null)';
        $converted = "(repeat_purchase = 'yes' or (repeat_purchase = 'no' and feedback = 'purchased'))";
        $sum = fn (string $condition) => "sum(case when {$condition} then 1 else 0 end)";

        $totals = (clone $scope)
            ->selectRaw('count(*) as leads')
            ->selectRaw("{$sum($catered)} as catered")
            ->selectRaw("{$sum($converted)} as converted")
            ->selectRaw("{$sum('feedback is not null')} as with_feedback")
            ->selectRaw("{$sum("feedback = 'still_have_stocks' and callback_date is null")} as stocks_no_callback")
            ->toBase()->first();
        $leads = (int) $totals->leads;

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
            'stocks_no_callback' => (int) $totals->stocks_no_callback,
            'feedback' => $countBy('feedback'),
            'statuses' => $countBy('status'),
            'tags' => $countBy('customer_tag'),
            'repeat_purchase' => $countBy('repeat_purchase'),
            'hours' => collect(config('segmentation.contact_times'))->map(fn (string $label, string $hour) => [
                'label' => $label,
                'contacts' => (int) ($byHour[$hour]->contacts ?? 0),
                'converted' => (int) ($byHour[$hour]->converted ?? 0),
            ]),
            'cras' => $cras->map(function (User $cra) use ($perCra, $craFeedback, $rate) {
                $row = $perCra[$cra->id] ?? null;
                $craLeads = (int) ($row->leads ?? 0);

                return [
                    'name' => $cra->displayName(),
                    'leads' => $craLeads,
                    'catered_rate' => $rate((int) ($row->catered ?? 0), $craLeads),
                    'converted' => (int) ($row->converted ?? 0),
                    'converted_rate' => $rate((int) ($row->converted ?? 0), $craLeads),
                    'top_feedback' => $craFeedback[$cra->id] ?? null,
                ];
            })->values(),
            'products' => $perProduct->map(fn (object $row) => [
                'name' => $row->product_name,
                'leads' => (int) $row->leads,
                'catered_rate' => $rate((int) $row->catered, (int) $row->leads),
                'converted' => (int) $row->converted,
                'converted_rate' => $rate((int) $row->converted, (int) $row->leads),
                'top_feedback' => $productFeedback[$row->product_name] ?? null,
            ]),
        ];

        return [...$summary, 'insights' => $this->insights($summary)];
    }

    /**
     * Short findings worth acting on, most useful first. Each is only said when the numbers support it.
     *
     * @param  array<string, mixed>  $s
     * @return list<array{tone: string, text: string}>
     */
    private function insights(array $s): array
    {
        if ($s['leads'] === 0) {
            return [];
        }

        $pct = fn (?float $value) => round(($value ?? 0) * 100).'%';
        $feedbackLabel = fn (string $key) => config('segmentation.feedback')[$key][0] ?? $key;
        $insights = [];

        if ($s['pending'] > 0) {
            $insights[] = ['tone' => $s['catered_rate'] < 0.5 ? 'warning' : 'info',
                'text' => number_format($s['pending']).' of '.number_format($s['leads']).' leads ('.$pct($s['pending'] / $s['leads']).') are still pending: no status, contact date or catered mark yet.'];
        }

        $feedbackTotal = $s['feedback']->sum();
        if ($feedbackTotal > 0) {
            $top = $s['feedback']->sortDesc()->keys()->first();
            $share = $s['feedback'][$top] / $feedbackTotal;
            $advice = match ($top) {
                'no_verbal_conv' => ' Customers aren\'t picking up: try the hours with the most conversions below, or chat first.',
                'still_have_stocks' => ' Set callback dates so they\'re reached when they run out.',
                'no_budget' => ' Offer smaller packs or a payment date that suits them.',
                'ineffective', 'stopped_by_dr' => ' Worth passing to product support for follow-up.',
                default => '',
            };
            $insights[] = ['tone' => in_array($top, ['purchased', 'currently_using'], true) ? 'good' : 'warning',
                'text' => 'Most common feedback: '.$feedbackLabel($top).' ('.$pct($share).' of '.number_format($feedbackTotal).').'.$advice];
        }

        if ($s['stocks_no_callback'] > 0) {
            $insights[] = ['tone' => 'info', 'text' => number_format($s['stocks_no_callback']).' '.str('customer')->plural($s['stocks_no_callback']).' with STILL HAVE STOCKS have no callback date yet.'];
        }

        if ($s['catered'] > 0 && $s['feedback_rate'] !== null && $s['feedback_rate'] < 0.6) {
            $insights[] = ['tone' => 'warning', 'text' => 'Only '.$pct($s['feedback_rate']).' of catered leads have Customer\'s Feedback filled in, so the feedback chart shows part of the picture.'];
        }

        $hours = $s['hours']->filter(fn (array $h) => $h['contacts'] >= self::MIN_FOR_INSIGHT);
        if ($hours->isNotEmpty()) {
            $busiest = $hours->sortByDesc('contacts')->first();
            $best = $hours->filter(fn (array $h) => $h['converted'] > 0)->sortByDesc(fn (array $h) => $h['converted'] / $h['contacts'])->first();
            $insights[] = ['tone' => 'info', 'text' => 'Most contacts happen at '.$busiest['label'].' ('.number_format($busiest['contacts']).').'
                .($best ? ' Best converting hour: '.$best['label'].' ('.$pct($best['converted'] / $best['contacts']).' converted).' : '')];
        }

        $products = $s['products']->filter(fn (array $p) => $p['leads'] >= self::MIN_FOR_INSIGHT && $p['converted_rate'] !== null);
        if ($products->count() >= 2) {
            $best = $products->sortByDesc('converted_rate')->first();
            $worst = $products->sortBy('converted_rate')->first();
            if ($best['converted_rate'] - $worst['converted_rate'] >= self::MIN_GAP_FOR_INSIGHT) {
                $insights[] = ['tone' => 'good', 'text' => $best['name'].' converts best ('.$pct($best['converted_rate']).' of '.number_format($best['leads']).' leads); '
                    .$worst['name'].' the least ('.$pct($worst['converted_rate']).').'];
            }
        }

        $cras = $s['cras']->filter(fn (array $c) => $c['leads'] >= self::MIN_FOR_INSIGHT);
        if ($cras->count() >= 2) {
            $top = $cras->sortByDesc('catered_rate')->first();
            $low = $cras->sortBy('catered_rate')->first();
            if ($top['catered_rate'] - $low['catered_rate'] >= self::MIN_GAP_FOR_INSIGHT) {
                $insights[] = ['tone' => 'info', 'text' => $top['name'].' has catered the most of their leads ('.$pct($top['catered_rate']).'); '
                    .$low['name'].' the least ('.$pct($low['catered_rate']).').'];
            }
        }

        if ($s['converted'] > 0) {
            $insights[] = ['tone' => 'good', 'text' => number_format($s['converted']).' '.str('lead')->plural($s['converted']).' converted ('.$pct($s['converted_rate']).' of all leads).'];
        }

        return $insights;
    }
}
