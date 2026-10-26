<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Niche;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NicheController extends Controller
{
    public function index(): View
    {
        $niches = Niche::with(['products:id', 'cras:id,niche_id', 'editor'])->orderBy('name')->get();
        $cras = LeadGenerator::cras();
        $inNiche = $niches->flatMap(fn (Niche $niche) => $niche->products->pluck('id'))->unique();

        return view('settings.niches', [
            'niches' => $niches,
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'cras' => $cras,
            // With every CRA in a niche, a product in none has nobody to take its leads.
            'unclaimed' => $niches->isNotEmpty() && $cras->every(fn (User $cra) => $cra->niche_id !== null)
                ? Product::whereNotIn('id', $inNiche)->orderBy('name')->pluck('name')
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $niche = Niche::create(['name' => $data['name'], 'updated_by' => $request->user()->id]);

        return $this->saved($niche, $data, "Niche \"{$niche->name}\" added.");
    }

    public function update(Request $request, Niche $niche): RedirectResponse
    {
        $data = $this->validated($request, $niche);
        $niche->update(['name' => $data['name'], 'updated_by' => $request->user()->id]);

        return $this->saved($niche, $data, "Niche \"{$niche->name}\" saved.");
    }

    public function destroy(Niche $niche): RedirectResponse
    {
        $niche->delete();
        // Its CRAs take every product again: today's leads still waiting can go to them.
        app(LeadGenerator::class)->assign(Lead::today());

        return back()->with('status', "Niche \"{$niche->name}\" deleted. Its CRAs get every product's leads again.");
    }

    /**
     * Save the niche's products and CRAs (a CRA picked here leaves any other niche), then move the untouched leads
     * now outside their CRA's niche (LeadGenerator::releaseOutsideNiches()).
     *
     * @param  array{name: string, products: list<int>, cras?: list<int>}  $data
     */
    private function saved(Niche $niche, array $data, string $status): RedirectResponse
    {
        DB::transaction(function () use ($niche, $data) {
            $niche->products()->sync($data['products']);
            User::where('niche_id', $niche->id)->whereNotIn('id', $data['cras'] ?? [])->update(['niche_id' => null]);
            User::whereIn('id', $data['cras'] ?? [])->update(['niche_id' => $niche->id]);
        });

        $released = app(LeadGenerator::class)->releaseOutsideNiches(Lead::today());

        return back()->with('status', $status.($released ? " {$released} untouched ".str('lead')->plural($released).' outside their CRA\'s niche moved to a CRA whose niche has the product.' : ''));
    }

    /**
     * @return array{name: string, products: list<int>, cras?: list<int>}
     */
    private function validated(Request $request, ?Niche $niche = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        return $request->validateWithBag($niche ? "niche{$niche->id}" : 'default', [
            'name' => ['required', 'string', 'max:100', Rule::unique('niches', 'name')->ignore($niche)],
            'products' => ['required', 'array', 'min:1'],
            'products.*' => ['integer', Rule::exists('products', 'id')],
            'cras' => ['nullable', 'array'],
            'cras.*' => ['integer', Rule::exists('users', 'id')->where('role_id', Role::where('slug', Role::CRA)->value('id'))],
        ], [
            'name.unique' => 'A niche with this name already exists.',
            'products.required' => 'Pick at least one product for the niche.',
        ]);
    }
}
