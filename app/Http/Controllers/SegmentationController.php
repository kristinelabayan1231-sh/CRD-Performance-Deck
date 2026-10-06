<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SegmentationController extends Controller
{
    public function index(Request $request, LeadGenerator $generator): View
    {
        $user = $request->user();
        $canViewAll = $user->can('segmentation.view_all');
        $today = Lead::today();

        // Keep today's leads fresh without anyone pressing a button.
        $syncError = null;
        try {
            $generator->syncIfStale($today);
        } catch (Throwable $e) {
            Log::warning('Automatic lead sync failed', ['message' => $e->getMessage()]);
            $syncError = $e->getMessage();
        }

        [$filters, $from, $to] = $this->resolveFilters($request, $today);
        $scope = $this->scope($filters, $from, $to);
        $cras = LeadGenerator::cras();

        $leads = (clone $scope)
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $status === 'none' ? $q->whereNull('status') : $q->where('status', $status))
            ->with(['assignee', 'notesAuthor'])
            ->orderBy('est_out_of_stock_date')
            ->orderByRaw('lead_type = ? desc', [Lead::TYPE_CRD])
            ->orderBy('customer_name')
            ->paginate(50)
            ->withQueryString();

        // Carried-over (unprocessed) leads from earlier days, for single-day views.
        $backlog = $from->equalTo($to)
            ? Lead::backlogAsOf($from)
                ->when(ctype_digit($filters['cra']), fn (Builder $q) => $q->where('assigned_to', (int) $filters['cra']))
                ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('lead_type', $type))
                ->with(['assignee', 'notesAuthor', 'transfers.fromUser'])
                ->orderBy('est_out_of_stock_date')
                ->orderBy('customer_name')
                ->get()
            : collect();

        $canTransfer = $user->can('segmentation.transfer');

        return view('segmentation.index', [
            'leads' => $leads,
            'backlog' => $backlog,
            'canTransfer' => $canTransfer,
            'workload' => $canTransfer ? LeadGenerator::workload($today) : [],
            'tiles' => $this->tiles($scope, $filters, $from, $to, $cras, $canViewAll),
            'singleDay' => $from->equalTo($to),
            'filters' => $filters,
            'cras' => $cras,
            'canViewAll' => $canViewAll,
            'canManage' => $user->can('segmentation.manage'),
            'statuses' => config('segmentation.statuses'),
            'optionalColumns' => config('segmentation.optional_columns'),
            'today' => $today,
            'lastSync' => LeadGenerator::lastSync(CarbonImmutable::parse($filters['date'] ?? $today)),
            'lastSyncResult' => LeadGenerator::lastSyncResult(CarbonImmutable::parse($filters['date'] ?? $today)),
            'syncError' => $syncError,
        ]);
    }

    /**
     * Live tile values for the current filters, polled by the page and
     * refreshed right after a status change.
     */
    public function summary(Request $request): JsonResponse
    {
        [$filters, $from, $to] = $this->resolveFilters($request, Lead::today());

        return response()->json($this->tiles(
            $this->scope($filters, $from, $to), $filters, $from, $to, LeadGenerator::cras(), $request->user()->can('segmentation.view_all'),
        ));
    }

    /**
     * @return array{0: array<string, ?string>, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function resolveFilters(Request $request, CarbonImmutable $today): array
    {
        $user = $request->user();

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(array_keys(Lead::TYPES))],
            'status' => ['nullable', 'string'],
        ]);

        // A specific date wins; otherwise a whole month; default is today.
        if (! empty($filters['date'])) {
            $from = $to = CarbonImmutable::parse($filters['date']);
            $filters['month'] = $from->format('Y-m');
        } elseif (! empty($filters['month'])) {
            $from = CarbonImmutable::parse($filters['month'].'-01');
            $to = $from->endOfMonth();
        } else {
            $from = $to = $today;
            $filters['date'] = $today->toDateString();
            $filters['month'] = $today->format('Y-m');
        }

        $filters['cra'] = $user->can('segmentation.view_all') ? ($filters['cra'] ?? 'all') : (string) $user->id;

        return [$filters, $from, $to];
    }

    private function scope(array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Lead::query()
            ->whereDate('est_out_of_stock_date', '>=', $from)->whereDate('est_out_of_stock_date', '<=', $to)
            ->when($filters['cra'] === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when(ctype_digit($filters['cra']), fn (Builder $q) => $q->where('assigned_to', (int) $filters['cra']))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('lead_type', $type));
    }

    /**
     * Summary tile values: key => [value, note].
     *
     * @return array<string, array{value: string, note: ?string}>
     */
    private function tiles(Builder $scope, array $filters, CarbonImmutable $from, CarbonImmutable $to, $cras, bool $canViewAll): array
    {
        $summary = (clone $scope)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as crd', [Lead::TYPE_CRD])
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as new', [Lead::TYPE_NEW])
            ->selectRaw('sum(case when status is not null then 1 else 0 end) as updated')
            ->first();

        $converted = (clone $scope)->converted()->count();

        $total = (int) $summary->total;
        $updated = (int) $summary->updated;

        $tiles = [
            'total' => ['value' => number_format($total), 'note' => null],
            'crd' => ['value' => number_format((int) $summary->crd), 'note' => null],
            'new' => ['value' => number_format((int) $summary->new), 'note' => null],
        ];

        if ($canViewAll) {
            $breakdown = $this->perCraBreakdown($filters, $from, $to, $cras);
            $counts = collect($breakdown)->pluck('total');
            $base = $from->equalTo($to) ? ' · base '.config('segmentation.leads_per_cra') : '';

            if (ctype_digit($filters['cra'])) {
                // One CRA selected: their own count for the date/month and type filters.
                $cra = $cras->firstWhere('id', (int) $filters['cra']);
                $value = number_format($total);
                $note = ($cra?->displayName() ?? 'Selected CRA').$base;
            } else {
                // All CRAs: leads each one holds in this range, or a min–max range if the split isn't exact.
                $value = match (true) {
                    $counts->isEmpty() => '—',
                    $counts->min() === $counts->max() => number_format($counts->min()),
                    default => number_format($counts->min()).'–'.number_format($counts->max()),
                };
                $note = $cras->count().($cras->count() === 1 ? ' CRA' : ' CRAs').($base ? $base.' each' : '');
            }

            $tiles['per_cra'] = [
                'value' => $value,
                'note' => $note,
                // Shown in the Per CRA pop-up; always covers every CRA for the date/month and type filters.
                'breakdown' => $breakdown,
                'period' => ($from->equalTo($to) ? $from->format('M j, Y') : $from->format('F Y'))
                    .(! empty($filters['type']) ? ' · '.Lead::TYPES[$filters['type']].'s' : ''),
            ];
        }

        $tiles['updated'] = [
            'value' => number_format($updated),
            'note' => $total ? 'of '.number_format($total).' · '.number_format($total - $updated).' pending · '.round($updated / $total * 100).'%' : 'No leads yet',
        ];

        $tiles['converted'] = [
            'value' => $total ? round($converted / $total * 100, 1).'%' : '0%',
            'note' => number_format($converted).' of '.number_format($total).' converted',
        ];

        return $tiles;
    }

    /**
     * Assigned leads per active CRA for the range, flagging anyone whose
     * count differs from the most common one.
     *
     * @return list<array{id: int, name: string, total: int, crd: int, new: int, odd: bool}>
     */
    private function perCraBreakdown(array $filters, CarbonImmutable $from, CarbonImmutable $to, $cras): array
    {
        $rows = Lead::whereDate('est_out_of_stock_date', '>=', $from)->whereDate('est_out_of_stock_date', '<=', $to)
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('lead_type', $type))
            ->whereIn('assigned_to', $cras->pluck('id'))
            ->selectRaw('assigned_to, lead_type, count(*) as total')
            ->groupBy('assigned_to', 'lead_type')
            ->get()
            ->groupBy('assigned_to');

        $breakdown = $cras->map(function ($cra) use ($rows) {
            $mine = $rows[$cra->id] ?? collect();

            return [
                'id' => $cra->id,
                'name' => $cra->displayName(),
                'total' => (int) $mine->sum('total'),
                'crd' => (int) $mine->where('lead_type', Lead::TYPE_CRD)->sum('total'),
                'new' => (int) $mine->where('lead_type', Lead::TYPE_NEW)->sum('total'),
            ];
        });

        // Most common count; on a tie the lower one, so the extra leads are what get flagged.
        $usual = $breakdown->countBy('total')
            ->map(fn (int $times, int $count) => ['times' => $times, 'count' => $count])
            ->sort(fn ($a, $b) => [$b['times'], $a['count']] <=> [$a['times'], $b['count']])
            ->first()['count'] ?? null;

        return $breakdown
            ->map(fn (array $row) => [...$row, 'odd' => $breakdown->count() > 1 && $row['total'] !== $usual])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function update(Request $request, Lead $lead): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $canManage = $user->can('segmentation.manage');

        abort_unless($canManage || $lead->assigned_to === $user->id, 403, 'You can only update leads assigned to you.');

        $in = fn (string $list) => ['sometimes', 'nullable', Rule::in(array_map('strval', array_keys(config("segmentation.{$list}"))))];

        $data = $request->validate([
            'status' => $in('statuses'),
            'assigned_to' => ['sometimes', 'nullable', Rule::in(LeadGenerator::cras()->pluck('id'))],
            'repeat_purchase' => $in('repeat_purchase'),
            'customer_tag' => $in('customer_tags'),
            'contact_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'contact_time' => $in('contact_times'),
            'feedback' => $in('feedback'),
            'callback_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [
            'assigned_to.in' => 'Leads can only be assigned to active CRAs.',
            'feedback.in' => "That Customer's Feedback option isn't available.",
        ]);

        $lead->fill(array_intersect_key($data, array_flip([
            'repeat_purchase', 'customer_tag', 'contact_date', 'contact_time', 'feedback', 'callback_date',
        ])));

        if (array_key_exists('notes', $data)) {
            $notes = trim((string) $data['notes']);
            $lead->forceFill([
                'notes' => $notes === '' ? null : $notes,
                'notes_updated_by' => $notes === '' ? null : $user->id,
                'notes_updated_at' => $notes === '' ? null : now(),
            ]);
        }

        if (array_key_exists('assigned_to', $data)) {
            abort_unless($canManage, 403, 'Only managers can reassign leads.');

            $lead->forceFill([
                'assigned_to' => $data['assigned_to'],
                'assigned_at' => $data['assigned_to'] ? now() : null,
            ]);
        }

        if (array_key_exists('status', $data)) {
            $lead->forceFill([
                'status' => $data['status'],
                'status_updated_by' => $user->id,
                'status_updated_at' => now(),
            ]);
        }

        $lead->save();

        if ($request->expectsJson()) {
            $lead->load('notesAuthor');

            return response()->json([
                'saved' => true,
                'notes' => $lead->notes,
                'notes_meta' => $this->notesMeta($lead),
            ]);
        }

        return back()->with('status', "Updated {$lead->customer_name}.");
    }

    public static function notesMeta(Lead $lead): ?string
    {
        if (! $lead->notes) {
            return null;
        }

        return trim(($lead->notesAuthor?->displayName() ?? '').' · '.$lead->notes_updated_at?->timezone(config('segmentation.timezone'))->format('M j, g:i A'), ' ·');
    }

    public function sync(Request $request, LeadGenerator $generator): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $date = CarbonImmutable::parse($data['date']);

        try {
            $result = $generator->generate($date);
        } catch (Throwable $e) {
            Log::error('Lead sync failed', ['date' => $data['date'], 'message' => $e->getMessage()]);

            return back()->withErrors(['sync' => "Couldn't load leads from the API: {$e->getMessage()}"]);
        }

        $message = "Synced {$date->format('M j, Y')}: {$result['found']} leads ({$result['crd']} CRD, {$result['new']} new), {$result['assigned']} newly assigned";
        $message .= $result['unassigned'] ? ", {$result['unassigned']} unassigned." : '.';

        if (LeadGenerator::cras()->isEmpty()) {
            $message .= ' No active CRAs yet — give users the CRA role in User Access.';
        }

        return redirect()->route('segmentation.index', ['date' => $date->toDateString()])->with('status', $message);
    }
}
