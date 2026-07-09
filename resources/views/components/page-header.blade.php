@props(['title', 'subtitle' => null])

<div class="sticky top-0 z-20 -mx-6 mb-8 border-b border-slate-200 bg-slate-50 px-6 pb-5 pt-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-3xl font-bold tracking-tight text-slate-900">{{ $title }}</h1>
                @isset($titleMeta)
                    {{ $titleMeta }}
                @endisset
            </div>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-500">{{ $subtitle }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-end justify-end gap-4">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
