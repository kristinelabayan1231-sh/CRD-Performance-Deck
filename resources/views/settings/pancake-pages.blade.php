<x-layouts.app title="Pancake Pages">
    @include('settings._header')

    @php($input = 'h-11 w-full rounded-lg border border-line bg-white px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

    {{-- Add page --}}
    <section class="mb-8 rounded-xl bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold">Add page</h2>
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
        <div class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 class="text-lg font-semibold">Pages</h2>
            <span class="text-sm text-muted">{{ $pages->where('is_active', true)->count() }} active of {{ $pages->count() }}</span>
        </div>

        @if ($pages->isEmpty())
            <p class="px-6 py-10 text-center text-sm text-muted">No pages yet. Add your first one above.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="px-6 py-3 font-semibold">Page name</th>
                            <th class="px-4 py-3 font-semibold">Page ID</th>
                            <th class="px-4 py-3 font-semibold">Access token</th>
                            <th class="px-4 py-3 font-semibold">Active</th>
                            <th class="px-4 py-3 font-semibold">Last test</th>
                            <th class="px-6 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($pages as $page)
                            @php($bag = $errors->getBag("page{$page->id}"))
                            @php($form = "page-{$page->id}")
                            <tr @class(['align-middle', 'bg-canvas/40 text-muted' => ! $page->is_active])>
                                <td class="min-w-56 px-6 py-3">
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
                                <td class="px-6 py-3">
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
</x-layouts.app>
