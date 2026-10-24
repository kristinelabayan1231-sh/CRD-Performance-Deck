<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadChat;
use Illuminate\Http\JsonResponse;

/**
 * The lead's Pancake chat link for the Spiels panel (the tracker opens it in its own window); null when none is found.
 */
class LeadChatController extends Controller
{
    public function __invoke(Lead $lead, LeadChat $chats): JsonResponse
    {
        $chat = $chats->for($lead);

        return response()->json([
            'url' => $chat ? LeadChat::url($chat) : null,
            'page' => $chat['page_name'] ?? null,
        ]);
    }
}
