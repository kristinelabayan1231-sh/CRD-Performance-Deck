@props(['title' => 'Settings'])

{{-- Settings pages: sub tabs in a vertical nav on the left, the page on the right. --}}
{{-- The sub tabs and their permissions are in App\Support\SettingsTabs; add new Settings pages there. --}}
@php($groups = collect(\App\Support\SettingsTabs::for(auth()->user()))->map(fn (array $tabs) => collect($tabs)))

<x-layouts.app :title="$title">
    <h1 class="mb-4 text-xl font-semibold">Settings <span class="text-sm font-normal text-muted">· Reference data used across the deck.</span></h1>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-6">
        <nav aria-label="Settings sections" class="-mx-4 shrink-0 overflow-x-auto px-4 lg:sticky lg:top-6 lg:mx-0 lg:w-56 lg:overflow-visible lg:rounded-xl lg:bg-white lg:p-3 lg:shadow-sm">
            <ul class="flex gap-1 lg:flex-col lg:gap-0">
                @foreach ($groups as $group => $tabs)
                    <li @class(['contents lg:block', 'lg:mt-2 lg:border-t lg:border-line lg:pt-2' => ! $loop->first])>
                        {{-- Group label: a quiet caption, not a link --}}
                        <p class="hidden cursor-default px-3 pt-1 pb-0.5 text-[0.625rem] font-normal tracking-wider text-muted/70 uppercase select-none lg:block" aria-hidden="true">{{ $group }}</p>
                        <ul class="contents lg:block lg:space-y-0.5">
                            @foreach ($tabs as $route => [$label])
                                @php($active = request()->routeIs(str_replace('.index', '.*', $route)))
                                <li class="shrink-0">
                                    <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif
                                       @class([
                                           'block rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition',
                                           'bg-brand-50 text-brand-600 font-semibold' => $active,
                                           'bg-white text-ink shadow-sm hover:bg-brand-50 hover:text-brand-600 lg:bg-transparent lg:shadow-none' => ! $active,
                                       ])>{{ $label }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0 flex-1">
            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
