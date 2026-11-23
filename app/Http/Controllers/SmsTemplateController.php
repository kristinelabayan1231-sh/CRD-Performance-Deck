<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\LeadGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SMS Reminders → Templates: each CRA's own restock texts per product and customer type, and the name she goes by in
 * them ({cra_name}). A CRA sees and edits only hers; someone who can see every CRA's texts picks the CRA.
 */
class SmsTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $owner = $this->owner($request);
        $templates = SmsTemplate::with('product')->where('user_id', $owner?->id)->orderByDesc('is_active')->orderBy('name')->get();

        return view('sms-reminders.templates', [
            'owner' => $owner,
            'isOwn' => $owner?->is($request->user()) ?? false,
            'cras' => $request->user()->can('sms_reminders.view_all') ? LeadGenerator::cras() : collect(),
            'templates' => $templates,
            'selected' => $request->query('new') ? null : ($templates->firstWhere('id', (int) $request->query('template')) ?? $templates->first()),
            'products' => Product::where('is_tsd', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        abort_unless($owner, 404);
        $template = SmsTemplate::create([...$this->validated($request), 'user_id' => $owner->id, 'updated_by' => $request->user()->id]);

        return redirect()->route('sms-reminders.templates.index', $this->query($request, $owner, ['template' => $template->id]))->with('status', "Template \"{$template->name}\" added.");
    }

    public function update(Request $request, SmsTemplate $smsTemplate): RedirectResponse
    {
        $this->authorizeOwner($request->user(), $smsTemplate);
        $smsTemplate->update([...$this->validated($request), 'updated_by' => $request->user()->id]);

        return redirect()->route('sms-reminders.templates.index', $this->query($request, $smsTemplate->owner, ['template' => $smsTemplate->id]))->with('status', "Template \"{$smsTemplate->name}\" saved.");
    }

    public function destroy(Request $request, SmsTemplate $smsTemplate): RedirectResponse
    {
        $this->authorizeOwner($request->user(), $smsTemplate);
        $smsTemplate->delete();

        return redirect()->route('sms-reminders.templates.index', $this->query($request, $smsTemplate->owner))->with('status', "Template \"{$smsTemplate->name}\" deleted.");
    }

    /**
     * The name a CRA goes by in her texts ({cra_name}), e.g. her code name; blank goes back to her display name.
     */
    public function name(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        abort_unless($owner, 404);
        $data = $request->validate(['sms_name' => ['nullable', 'string', 'max:60']], [], ['sms_name' => 'name in texts']);
        $owner->update(['sms_name' => trim((string) ($data['sms_name'] ?? '')) ?: null]);

        return redirect()->route('sms-reminders.templates.index', $this->query($request, $owner))->with('status', "Texts now say \"{$owner->smsName()}\".");
    }

    /**
     * Whose templates: your own; someone who can see every CRA's texts picks the CRA (?cra=), else the first CRA when
     * they aren't one themselves.
     */
    private function owner(Request $request): ?User
    {
        $user = $request->user();

        if (! $user->can('sms_reminders.view_all')) {
            return $user;
        }

        $cras = LeadGenerator::cras();
        $picked = (int) $request->input('cra');

        return $cras->firstWhere('id', $picked) ?? ($cras->contains('id', $user->id) ? $user : $cras->first());
    }

    private function authorizeOwner(User $user, SmsTemplate $template): void
    {
        abort_unless((int) $template->user_id === $user->id || $user->can('sms_reminders.view_all'), 403);
    }

    /**
     * The page's query: the CRA picked, when it isn't you, plus $extra.
     *
     * @param  array<string, int>  $extra
     * @return array<string, int>
     */
    private function query(Request $request, ?User $owner, array $extra = []): array
    {
        return ($owner && ! $owner->is($request->user()) ? ['cra' => $owner->id] : []) + $extra;
    }

    /**
     * @return array{name: string, purpose: string, product_id: ?int, customer_type: ?string, body: string, is_active: bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'purpose' => ['required', Rule::in(array_keys(SmsTemplate::PURPOSES))],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('is_tsd', false)],
            'customer_type' => ['nullable', Rule::in(array_keys(config('sms_reminders.customer_types')))],
            'body' => ['required', 'string', 'max:480'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['body' => 'message']);

        return [
            'name' => $data['name'],
            'purpose' => $data['purpose'],
            'body' => $data['body'],
            'product_id' => $data['product_id'] ?? null,
            'customer_type' => $data['customer_type'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
