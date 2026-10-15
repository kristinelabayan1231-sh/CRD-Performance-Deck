<x-layouts.settings title="Pancake Pages">
    @php($input = 'h-11 w-full rounded-lg border border-line bg-white px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

    {{-- Add page --}}
    <section class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 class="text-base font-semibold">Add page</h2>
        <p class="text-sm text-muted">Facebook pages whose Pancake chat engagements count toward Segmentation Productivity. Get the page access token in Pancake → the page's Settings → Tools.</p>
        <form method="POST" action="{{ route('settings.pancake-pages.store') }}" class="mt-4 grid gap-4 md:grid-cols-[1fr_1fr_1.4fr_auto] md:items-end">
            @csrf
            <label>
                <span class="mb-1 block text-sm font-medium">Page name</span>
                <input type="text" name="name" value="{{ $errors->any() ? old('name') : '' }}" required maxlength="255" placeholder="e.g. Trusted Eye Care" class="{{ $input }}">
            </label>
            <label>
                <span class="mb-1 block text-sm font-medium">Page ID</span>
                <input type="text" name="page_id" value="{{ $errors->any() ? old('page_id') : '' }}" required inputmode="numeric" maxlength="30" placeholder="e.g. 1199…" class="{{ $input }}">
            </label>
            <label>
                <span class="mb-1 block text-sm font-medium">Page access token</span>
                <input type="password" name="access_token" required maxlength="2000" autocomplete="off" placeholder="Paste the token" class="{{ $input }}">
            </label>
            <button type="submit" class="h-11 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">
                Add page
            </button>
        </form>
        @if ($errors->any())
            <ul role="alert" class="mt-3 space-y-1 text-sm text-coral-700">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Pages --}}
    <section class="overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-line px-4 py-4">
            <h2 class="text-base font-semibold">Pages</h2>
            <span class="text-sm text-muted">{{ $pages->where('is_active', true)->count() }} active of {{ $pages->count() }}</span>
        </div>

        @if ($pages->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-muted">No pages yet. Add your first one above.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Page name</th>
                            <th class="px-4 py-3 font-semibold">Page ID</th>
                            <th class="px-4 py-3 font-semibold">Access token</th>
                            <th class="px-4 py-3 font-semibold">Active</th>
                            <th class="px-4 py-3 font-semibold">Last test</th>
                            <th class="px-4 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($pages as $page)
                            @php($bag = $errors->getBag("page{$page->id}"))
                            @php($form = "page-{$page->id}")
                            <tr @class(['align-middle', 'bg-canvas/40 text-muted' => ! $page->is_active])>
                                <td class="min-w-56 px-4 py-3">
                                    <input form="{{ $form }}" type="text" name="name" required maxlength="255"
                                           value="{{ $bag->any() ? old('name', $page->name) : $page->name }}"
                                           aria-label="Name of {{ $page->name }}" class="{{ $input }} h-10">
                                    @foreach ($bag->all() as $error)
                                        <p role="alert" class="mt-1 text-xs text-coral-700">{{ $error }}</p>
                                    @endforeach
                                </td>
                                <td class="min-w-44 px-4 py-3">
                                    <input form="{{ $form }}" type="text" name="page_id" required inputmode="numeric" maxlength="30"
                                           value="{{ $bag->any() ? old('page_id', $page->page_id) : $page->page_id }}"
                                           aria-label="Page ID of {{ $page->name }}" class="{{ $input }} h-10 tabular-nums">
                                </td>
                                <td class="min-w-52 px-4 py-3">
                                    <input form="{{ $form }}" type="password" name="access_token" maxlength="2000" autocomplete="off"
                                           placeholder="{{ $page->maskedToken() }} · replace"
                                           aria-label="New access token for {{ $page->name }}" class="{{ $input }} h-10">
                                </td>
                                <td class="px-4 py-3">
                                    <input form="{{ $form }}" type="hidden" name="is_active" value="0">
                                    <label class="inline-flex cursor-pointer items-center gap-2">
                                        <input form="{{ $form }}" type="checkbox" name="is_active" value="1" @checked($page->is_active)
                                               class="size-4 rounded border-line accent-brand-600" aria-label="{{ $page->name }} is active">
                                        <span class="text-xs">{{ $page->is_active ? 'On' : 'Off' }}</span>
                                    </label>
                                </td>
                                <td class="min-w-40 px-4 py-3 text-xs">
                                    @if ($page->checked_at === null)
                                        <span class="text-muted">Not tested</span>
                                    @elseif ($page->check_ok)
                                        <span class="rounded-full bg-teal/15 px-2 py-0.5 font-semibold text-teal-700">✓ Connected</span>
                                        <span class="mt-1 block text-muted">{{ $page->checked_at->diffForHumans() }}</span>
                                    @else
                                        <span class="rounded-full bg-coral/15 px-2 py-0.5 font-semibold text-coral-700" title="{{ $page->check_message }}">✕ Refused</span>
                                        <span class="mt-1 block text-muted">{{ $page->checked_at->diffForHumans() }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <form id="{{ $form }}" method="POST" action="{{ route('settings.pancake-pages.update', $page) }}">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="h-10 rounded-lg border border-line px-3 font-medium hover:border-brand-400 hover:text-brand-600">Save</button>
                                        </form>
                                        <form method="POST" action="{{ route('settings.pancake-pages.test', $page) }}">
                                            @csrf
                                            <button type="submit" class="h-10 rounded-lg border border-line px-3 font-medium hover:border-brand-400 hover:text-brand-600">Test</button>
                                        </form>
                                        <form method="POST" action="{{ route('settings.pancake-pages.destroy', $page) }}"
                                              data-confirm="Remove {{ $page->name }}? Its engagements stop counting." onsubmit="return confirm(this.dataset.confirm)">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="h-10 rounded-lg border border-coral/60 px-3 font-medium text-coral-700 hover:bg-coral/10">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    {{-- Product groups: the pages that sell the same product; each group is one set per CRA in PC Report. Changes show on
         the screen and are saved with Save groups. --}}
    @php($chip = 'inline-flex items-center gap-1 rounded-full border border-line bg-canvas/60 py-0.5 pr-1 pl-3 text-xs font-medium')
    <section class="mt-4 overflow-hidden rounded-xl bg-white shadow-sm" data-product-groups>
        <div class="flex flex-wrap items-start justify-between gap-2 border-b border-line px-4 py-4">
            <div>
                <h2 class="text-base font-semibold">Product groups</h2>
                <p class="text-sm text-muted">Group the pages that sell the same product. Each group is one set per CRA in PC Report, with a row for every page.</p>
            </div>
            @unless ($groupsSaved)
                <span class="rounded-full bg-[#fff6d6] px-2.5 py-0.5 text-xs font-semibold text-[#7a5c00]">Sample groups · save to keep yours</span>
            @endunless
        </div>

        @if ($errors->groups->any())
            <div role="alert" class="border-b border-line bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->groups->first() }}</div>
        @endif

        <form data-group-add class="flex flex-wrap gap-2 border-b border-line px-4 py-3">
            <label class="min-w-56 flex-1">
                <span class="sr-only">Product name</span>
                <input type="text" data-group-name required maxlength="60" placeholder="Product name, e.g. Sinuxyl" class="{{ $input }} h-10">
            </label>
            <button type="submit" class="h-10 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">Add product</button>
        </form>

        <div class="divide-y divide-line" data-group-list>
            @foreach ($productGroups as $group)
                @include('settings._product-group', ['name' => $group['name'], 'pages' => $group['pages']])
            @endforeach
        </div>
        <p data-group-empty @class(['px-4 py-8 text-center text-sm text-muted', 'hidden' => $productGroups !== []])>No product groups yet. Add your first one above.</p>

        <div class="border-t border-line bg-canvas/40 px-4 py-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Pages not in a group <span class="font-normal normal-case">· not shown in PC Report</span></p>
            <div class="flex flex-wrap gap-1.5" data-group-free>
                @foreach ($ungroupedPages as $page)
                    <span class="{{ $chip }} pr-3" data-page="{{ $page }}">{{ $page }}</span>
                @endforeach
            </div>
            <p data-group-free-empty @class(['text-sm text-muted', 'hidden' => $ungroupedPages->isNotEmpty()])>Every page is in a group.</p>
        </div>

        {{-- Sends the groups on screen; the panel fills in the list when it's submitted --}}
        <form method="POST" action="{{ route('settings.pancake-pages.groups') }}" data-group-save class="flex flex-wrap items-center justify-end gap-3 border-t border-line px-4 py-3">
            @csrf
            @method('PUT')
            <input type="hidden" name="groups" data-group-json>
            <span class="rounded-full bg-[#fff6d6] px-2.5 py-0.5 text-xs font-semibold text-[#7a5c00]" data-group-dirty hidden>Unsaved changes</span>
            <button type="submit" class="h-10 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">Save groups</button>
        </form>

        <template data-group-template>
            @include('settings._product-group', ['name' => '', 'pages' => []])
        </template>
    </section>
</x-layouts.settings>
