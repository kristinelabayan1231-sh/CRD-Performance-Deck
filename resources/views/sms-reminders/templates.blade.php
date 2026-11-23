@php
    $control = 'h-10 w-full rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $types = config('sms_reminders.customer_types');
    $template = $selected;
    // The CRA picked, kept on every link when it isn't you.
    $whose = $owner && ! $isOwn ? ['cra' => $owner->id] : [];
@endphp

<x-layouts.app title="SMS Templates">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">SMS Reminders <span class="text-sm font-normal text-muted">· Templates</span></h1>
                <p class="text-sm text-muted">{{ $isOwn ? 'Your own texts: no one else sees or changes them.' : ($owner ? $owner->displayName()."'s own texts." : 'No CRA yet.') }} Reminders: each customer gets the closest fit (product + customer type, then product, then customer type, then "Any"); with no template, the built-in default is sent. Follow-ups are only for the Text button.</p>
            </div>
            @include('sms-reminders._tabs')
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif
        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif

        @if ($owner)
            <section aria-label="Name in texts" class="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-white p-4 shadow-sm">
                @if ($cras->isNotEmpty())
                    <form method="GET" action="{{ route('sms-reminders.templates.index') }}" class="flex flex-col gap-1.5 text-sm font-semibold">
                        <label for="template-cra">CRA</label>
                        <select id="template-cra" name="cra" onchange="this.form.submit()" class="{{ $control }} w-48 font-normal">
                            @foreach ($cras as $cra)
                                <option value="{{ $cra->id }}" @selected($owner->is($cra))>{{ $cra->displayName() }}</option>
                            @endforeach
                        </select>
                        <noscript><button type="submit" class="h-10 rounded-lg border border-line px-3 text-sm">Show</button></noscript>
                    </form>
                @endif
                <form method="POST" action="{{ route('sms-reminders.templates.name', $whose) }}" class="flex flex-wrap items-end gap-2">
                    @csrf @method('PUT')
                    <label class="flex flex-col gap-1.5 text-sm font-semibold">
                        {{ $isOwn ? 'Your name in texts' : 'Name in texts' }}
                        <input type="text" name="sms_name" maxlength="60" value="{{ old('sms_name', $owner->sms_name) }}" placeholder="{{ $owner->displayName() }}" class="{{ $control }} w-56 font-normal">
                    </label>
                    <button type="submit" class="h-10 rounded-lg border border-line px-4 text-sm font-semibold hover:border-brand-400">Save name</button>
                </form>
                <p class="text-xs text-muted sm:max-w-sm">{cra_name} in a text becomes <strong class="text-ink">{{ $owner->smsName() }}</strong>. Use your code name; leave it blank to use {{ $owner->displayName() }}.</p>
            </section>
        @endif

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <section aria-label="Templates" class="overflow-hidden rounded-xl border border-line bg-white shadow-sm lg:w-80 lg:shrink-0">
                <div class="border-b border-line p-3">
                    <a href="{{ route('sms-reminders.templates.index', $whose + ['new' => 1]) }}" class="flex h-10 items-center justify-center gap-2 rounded-lg bg-brand-600 text-sm font-semibold text-white hover:bg-brand-700">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>New template
                    </a>
                </div>
                @forelse ($templates as $item)
                    <a href="{{ route('sms-reminders.templates.index', $whose + ['template' => $item->id]) }}" @if ($template?->is($item)) aria-current="true" @endif
                       @class(['block border-b border-line px-4 py-3 last:border-b-0', 'bg-brand-50' => $template?->is($item), 'hover:bg-canvas' => ! $template?->is($item)])>
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-semibold">{{ $item->name }}</span>
                            @unless ($item->is_active)
                                <span class="rounded-full bg-canvas px-2 py-0.5 text-[0.6875rem] font-semibold text-muted">Off</span>
                            @endunless
                        </span>
                        <span class="text-xs text-muted">{{ $item->isFollowUp() ? 'Follow-up' : 'Reminder' }} · {{ $item->product?->name ?? 'Any product' }} · {{ $item->customerTypeLabel() }}</span>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-muted">No templates yet: {{ $isOwn ? 'your' : 'these' }} customers get the built-in default until {{ $isOwn ? 'you add' : 'one is added' }}.</p>
                @endforelse
            </section>

            <form method="POST" action="{{ $template ? route('sms-reminders.templates.update', $template) : route('sms-reminders.templates.store', $whose) }}"
                  class="min-w-0 flex-1 space-y-4 rounded-xl border border-line bg-white p-5 shadow-sm" id="template-form">
                @csrf
                @if ($template) @method('PUT') @endif
                <h2 class="text-base font-semibold">{{ $template ? 'Edit template' : 'New template' }}</h2>

                <label class="flex flex-col gap-1.5 text-sm font-semibold">
                    Template name
                    <input type="text" name="name" required maxlength="100" value="{{ old('name', $template?->name) }}" placeholder="e.g. Clear Sight · Repeat" class="{{ $control }} font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold">
                    Used for
                    <select name="purpose" class="{{ $control }} font-normal">
                        @foreach (\App\Models\SmsTemplate::PURPOSES as $value => $purposeLabel)
                            <option value="{{ $value }}" @selected(old('purpose', $template?->purpose ?? \App\Models\SmsTemplate::REMINDER) === $value)>{{ $purposeLabel }}</option>
                        @endforeach
                    </select>
                    <span class="text-xs font-normal text-muted">Every active template can be picked from the Text button's dropdown. Only reminder templates are sent automatically.</span>
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1.5 text-sm font-semibold">
                        Product
                        <select name="product_id" class="{{ $control }} font-normal" data-preview-product>
                            <option value="">Any product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((int) old('product_id', $template?->product_id) === $product->id)>{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-semibold">
                        Customer type
                        <select name="customer_type" class="{{ $control }} font-normal">
                            <option value="">Any customer</option>
                            @foreach ($types as $key => $label)
                                <option value="{{ $key }}" @selected(old('customer_type', $template?->customer_type) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="space-y-2">
                    <label for="body" class="text-sm font-semibold">Message</label>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-muted">Insert:</span>
                        @foreach (config('sms_reminders.placeholders') as $placeholder => $meaning)
                            <button type="button" data-insert="{{ $placeholder }}" title="{{ $meaning }}"
                                    class="rounded-full border border-brand-200 bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 hover:border-brand-400">{{ $placeholder }}</button>
                        @endforeach
                    </div>
                    <textarea id="body" name="body" rows="5" required maxlength="480"
                              class="w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm leading-relaxed focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">{{ old('body', $template?->body ?? config('sms_reminders.default_body')) }}</textarea>
                    <p class="text-xs text-muted">"{{ config('sms_reminders.opt_out_line') }}" is added to the end when the message doesn't mention STOP.</p>
                    <p id="char-count" class="text-sm font-semibold" aria-live="polite"></p>
                </div>

                <label class="flex items-center gap-2.5 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template?->is_active ?? true)) class="size-4.5 rounded accent-brand-600">
                    Use this template
                </label>

                <div class="flex flex-wrap justify-end gap-2">
                    @if ($template)
                        <button type="submit" form="delete-template" class="h-10 rounded-lg border border-line px-4 text-sm font-semibold text-coral-700 hover:border-coral">Delete</button>
                    @endif
                    <button type="submit" class="h-10 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">Save template</button>
                </div>
            </form>
            @if ($template)
                <form method="POST" action="{{ route('sms-reminders.templates.destroy', $template) }}" id="delete-template" onsubmit="return confirm('Delete this template?')">
                    @csrf @method('DELETE')
                </form>
            @endif

            <aside aria-label="Preview" class="space-y-3 rounded-xl border border-line bg-white p-5 shadow-sm lg:w-80 lg:shrink-0">
                <h2 class="text-xs font-semibold tracking-wide text-muted uppercase">Preview</h2>
                <div class="rounded-2xl bg-canvas p-4">
                    <p id="preview" class="ml-auto max-w-[95%] rounded-2xl rounded-br-md bg-brand-600 px-3.5 py-2.5 text-sm leading-relaxed text-white"></p>
                </div>
                <p class="text-xs text-muted">Sample: Maria, run-out date 27 Nov, {{ $owner?->smsName() ?? 'Anna' }}.</p>
            </aside>
        </div>
    </div>

    <script>
        (() => {
            const body = document.getElementById('body');
            const product = document.querySelector('[data-preview-product]');
            const preview = document.getElementById('preview');
            const count = document.getElementById('char-count');
            const optOut = @js(config('sms_reminders.opt_out_line'));
            const longest = @js($products->pluck('name')->sortByDesc(fn ($name) => mb_strlen($name))->first() ?? 'Product');
            const gsm = /^[\n\r\x20-\x7E£¥èéùìòÇØøÅåÆæßÉÄÖÑÜäöñüà§¡¿€]*$/;

            const fill = (text, name) => {
                const filled = text.replaceAll('{first_name}', 'Maria').replaceAll('{product}', name)
                    .replaceAll('{run_out_date}', '27 Nov').replaceAll('{cra_name}', @js($owner?->smsName() ?? 'Anna')).trim();
                return /stop/i.test(filled) ? filled : `${filled} ${optOut}`;
            };

            const refresh = () => {
                const name = product.selectedIndex > 0 ? product.options[product.selectedIndex].text : longest;
                preview.textContent = fill(body.value, name);
                const longestText = fill(body.value, product.selectedIndex > 0 ? name : longest);
                const limit = gsm.test(longestText) ? 160 : 70;
                const parts = Math.ceil(longestText.length / limit) || 1;
                count.textContent = `${longestText.length} / ${limit} characters · ${parts} SMS` + (limit === 70 ? ' — an emoji or special character cuts one SMS to 70 characters' : '') + (parts > 1 ? ' — shorten it to keep it to one text' : '');
                count.className = 'text-sm font-semibold ' + (parts > 1 ? 'text-coral-700' : 'text-brand-700');
            };

            document.querySelectorAll('[data-insert]').forEach((button) => button.addEventListener('click', () => {
                const at = body.selectionStart ?? body.value.length;
                body.value = body.value.slice(0, at) + button.dataset.insert + body.value.slice(body.selectionEnd ?? at);
                body.focus();
                body.selectionStart = body.selectionEnd = at + button.dataset.insert.length;
                refresh();
            }));
            body.addEventListener('input', refresh);
            product.addEventListener('change', refresh);
            refresh();
        })();
    </script>
</x-layouts.app>
