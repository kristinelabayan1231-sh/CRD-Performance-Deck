<?php

namespace App\Http\Controllers;

use App\Models\PancakePage;
use App\Services\PancakeClient;
use App\Support\ProductGroups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PancakePageController extends Controller
{
    public function index(): View
    {
        $pages = PancakePage::orderBy('name')->get();
        $groups = ProductGroups::all();

        return view('settings.pancake-pages', [
            'pages' => $pages,
            'productGroups' => $groups,
            'groupsSaved' => ProductGroups::isSaved(),
            'ungroupedPages' => $pages->pluck('name')->diff(collect($groups)->pluck('pages')->flatten())->values(),
        ]);
    }

    public function store(Request $request, PancakeClient $client): RedirectResponse
    {
        $data = $this->validated($request);

        $page = PancakePage::create($data);
        $this->check($page, $client);

        return back()->with('status', "Page \"{$page->name}\" added. ".$page->check_message);
    }

    public function update(Request $request, PancakePage $page, PancakeClient $client): RedirectResponse
    {
        $data = $this->validated($request, $page);

        // A blank token keeps the saved one.
        if (blank($data['access_token'] ?? null)) {
            unset($data['access_token']);
        }

        $page->update($data);

        if ($page->wasChanged(['page_id', 'access_token'])) {
            $this->check($page, $client);
        }

        return back()->with('status', "Page \"{$page->name}\" updated.");
    }

    /**
     * Save the product groups (sent as JSON by the Product groups panel): each a name and its pages, a page in one group only.
     */
    public function saveGroups(Request $request): RedirectResponse
    {
        $request->merge(['groups' => json_decode((string) $request->input('groups'), true)]);

        $data = $request->validateWithBag('groups', [
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'groups.*.pages' => ['present', 'array'],
            'groups.*.pages.*' => ['string', Rule::in(PancakePage::pluck('name')->all()), 'distinct'],
        ], [
            'groups.required' => 'Add at least one product group.',
            'groups.min' => 'Add at least one product group.',
            'groups.*.name.distinct' => 'Two product groups have the same name.',
            'groups.*.pages.*.in' => 'A page in the groups is no longer in the list above; reload and try again.',
            'groups.*.pages.*.distinct' => 'A page can be in one product group only.',
        ]);

        $groups = collect($data['groups'])->map(fn (array $group) => ['name' => trim($group['name']), 'pages' => array_values($group['pages'])]);

        // Each group is picked by its name in PC Report's link, so names must stay apart there too ("Clear Sight" vs "ClearSight" is fine).
        if ($groups->map(fn (array $group) => Str::slug($group['name']))->duplicates()->isNotEmpty()) {
            return back()->withErrors(['groups' => 'Two product groups have names that are too alike; rename one.'], 'groups');
        }

        ProductGroups::save($groups->all(), $request->user());

        return back()->with('status', 'Product groups saved. PC Report shows them now.');
    }

    public function destroy(PancakePage $page): RedirectResponse
    {
        $page->delete();

        return back()->with('status', "Page \"{$page->name}\" removed.");
    }

    /**
     * Ask Pancake for today's engagements with the saved token.
     */
    public function test(PancakePage $page, PancakeClient $client): RedirectResponse
    {
        $this->check($page, $client);

        return back()->with('status', "{$page->name}: {$page->check_message}");
    }

    private function check(PancakePage $page, PancakeClient $client): void
    {
        [$ok, $message] = $client->checkPage($page);

        $page->update(['checked_at' => now(), 'check_ok' => $ok, 'check_message' => $message]);
    }

    /**
     * @return array{name: string, page_id: string, access_token?: ?string, is_active: bool}
     */
    private function validated(Request $request, ?PancakePage $page = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'page_id' => trim((string) $request->input('page_id')),
            'access_token' => trim((string) $request->input('access_token')),
            'is_active' => $request->boolean('is_active', $page?->is_active ?? true),
        ]);

        // Edits are validated in their own error bag so they don't show on the add form.
        return $request->validateWithBag($page ? "page{$page->id}" : 'default', [
            'name' => ['required', 'string', 'max:255'],
            'page_id' => ['required', 'regex:/^\d+$/', 'max:30', Rule::unique('pancake_pages', 'page_id')->ignore($page)],
            'access_token' => [$page ? 'nullable' : 'required', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ], [
            'page_id.regex' => 'The page ID is the number from Pancake (digits only).',
            'page_id.unique' => 'This page is already added.',
        ]);
    }
}
