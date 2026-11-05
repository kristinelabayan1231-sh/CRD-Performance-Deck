<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\PancakeOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The Pancake chat a lead's order came from, opened from the Segmentation Tracker beside the Spiels panel. A lead's
 * Order # is its Pancake order number, so the chat is that order's conversation. Orders saved before conversations
 * were kept are fetched from Pancake once (and saved); with no conversation on the order, the customer's latest chat
 * on another order with the same contact number is used.
 */
class LeadChat
{
    /** How long an order Pancake had no conversation for is not asked about again. */
    public const RETRY_HOURS = 24;

    public function __construct(private PancakeSync $pancake) {}

    /**
     * The chat's page and conversation, or null when no order of this customer has one.
     *
     * @return array{page_id: string, conversation_id: string, page_name: ?string}|null
     */
    public function for(Lead $lead): ?array
    {
        $number = Str::after((string) $lead->order_id, Lead::POS_PREFIX);
        $order = PancakeOrder::firstWhere('pancake_order_id', $number);

        if (! $order?->conversation_id && Cache::add('lead-chat.fetched.'.$number, true, now()->addHours(self::RETRY_HOURS))) {
            rescue(fn () => $this->pancake->syncOrdersByNumber([$number]));
            $order = PancakeOrder::firstWhere('pancake_order_id', $number);
        }

        if (! $order?->conversation_id) {
            $order = PancakeOrder::where('phone_key', LeadGenerator::normalizePhone((string) $lead->phone_number))
                ->whereNotNull('conversation_id')->whereNotNull('page_id')
                ->latest('ordered_at')->first();
        }

        return $order?->conversation_id && $order->page_id
            ? ['page_id' => $order->page_id, 'conversation_id' => $order->conversation_id, 'page_name' => $order->page_name]
            : null;
    }

    /**
     * The chat's address in Pancake's inbox.
     *
     * @param  array{page_id: string, conversation_id: string}  $chat
     */
    public static function url(array $chat): string
    {
        return rtrim(config('services.pancake.inbox_url'), '/').'/'.rawurlencode($chat['page_id']).'?c_id='.rawurlencode($chat['conversation_id']);
    }
}
