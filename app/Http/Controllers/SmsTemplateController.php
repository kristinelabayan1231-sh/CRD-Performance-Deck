<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SmsTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SMS Reminders → Templates: restock texts per product and customer type. The list beside the picked template's
 * editor (or a new one).
 */
class SmsTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $templates = SmsTemplate::with('product')->orderByDesc('is_active')->orderBy('name')->get();

        return view('sms-reminders.templates', [
            'templates' => $templates,
            'selected' => $request->query('new') ? null : ($templates->firstWhere('id', (int) $request->query('template')) ?? $templates->first()),
            'products' => Product::where('is_tsd', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = SmsTemplate::create([...$this->validated($request), 'updated_by' => $request->user()->id]);

        return redirect()->route('sms-reminders.templates.index', ['template' => $template->id])->with('status', "Template \"{$template->name}\" added.");
    }

    public function update(Request $request, SmsTemplate $smsTemplate): RedirectResponse
    {
        $smsTemplate->update([...$this->validated($request), 'updated_by' => $request->user()->id]);

        return redirect()->route('sms-reminders.templates.index', ['template' => $smsTemplate->id])->with('status', "Template \"{$smsTemplate->name}\" saved.");
    }

    public function destroy(SmsTemplate $smsTemplate): RedirectResponse
    {
        $smsTemplate->delete();

        return redirect()->route('sms-reminders.templates.index')->with('status', "Template \"{$smsTemplate->name}\" deleted.");
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

        return [...$data, 'product_id' => $data['product_id'] ?? null, 'customer_type' => $data['customer_type'] ?? null, 'is_active' => $request->boolean('is_active')];
    }
}
