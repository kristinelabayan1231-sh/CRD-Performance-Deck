<?php

namespace App\Http\Controllers;

use App\Models\Spiel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → My Spiels: each user's own spiels (title and content), shown in the Segmentation Tracker's Spiels panel
 * in their order. Nobody sees or changes another user's spiels.
 */
class SpielController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.spiels', ['spiels' => $request->user()->spiels]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $request->user()->spiels()->create([...$data, 'position' => (int) $request->user()->spiels()->max('position') + 1]);

        return back()->with('status', "Spiel \"{$data['title']}\" saved.");
    }

    public function update(Request $request, Spiel $spiel): RedirectResponse
    {
        $this->authorizeOwner($request, $spiel);
        $spiel->update($this->validated($request));

        return back()->with('status', "Spiel \"{$spiel->title}\" saved.");
    }

    public function destroy(Request $request, Spiel $spiel): RedirectResponse
    {
        $this->authorizeOwner($request, $spiel);
        $spiel->delete();

        return back()->with('status', "Spiel \"{$spiel->title}\" deleted.");
    }

    /**
     * Save the order the spiels were dragged into (their ids, first to last).
     */
    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $own = $request->user()->spiels()->pluck('id')->all();

        foreach (array_values(array_intersect($ids, $own)) as $position => $id) {
            Spiel::whereKey($id)->update(['position' => $position + 1]);
        }

        return response()->json(['message' => 'Order saved.']);
    }

    /**
     * The title and the content's rich text (the editor's HTML, cleaned to Spiel::ALLOWED_TAGS).
     *
     * @return array{title: string, body: string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:20000', function (string $attribute, string $value, \Closure $fail) {
                if (! Spiel::hasText($value)) {
                    $fail('Write the spiel\'s content.');
                }
            }],
        ], [
            'title.required' => 'Give the spiel a title.',
            'body.required' => 'Write the spiel\'s content.',
        ]);

        return ['title' => $data['title'], 'body' => Spiel::sanitize($data['body'])];
    }

    private function authorizeOwner(Request $request, Spiel $spiel): void
    {
        abort_unless($spiel->user_id === $request->user()->id, 403);
    }
}
