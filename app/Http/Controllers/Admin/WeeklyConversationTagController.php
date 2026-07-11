<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WeeklyConversationTag;
use App\Support\WeekBlocks;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WeeklyConversationTagController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'week_start' => 'required|date',
            'tag_name' => 'required|string|max:255',
        ]);

        $weekStart = WeekBlocks::startOf(Carbon::parse($validated['week_start']))->toDateString();

        WeeklyConversationTag::updateOrCreate(
            ['week_start' => $weekStart],
            ['tag_name' => trim($validated['tag_name'])],
        );

        return back()->with('status', 'Weekly conversation tag saved.');
    }

    public function destroy(WeeklyConversationTag $weeklyConversationTag)
    {
        $weeklyConversationTag->delete();

        return back()->with('status', 'Weekly conversation tag removed.');
    }
}
