{{-- One cell per status column: the order count as the status's Pancake badge, a dash for none. --}}
@foreach ($statuses as $status => $name)
    <td class="px-3 py-2 text-center">
        @if ($count = $counts[$status] ?? 0)
            <span class="inline-block min-w-8 rounded-full px-2 py-0.5 text-xs font-semibold {{ $badges[$status] ?? 'bg-canvas text-muted' }}">{{ number_format($count) }}</span>
        @else
            <span class="text-muted/60">–</span>
        @endif
    </td>
@endforeach
