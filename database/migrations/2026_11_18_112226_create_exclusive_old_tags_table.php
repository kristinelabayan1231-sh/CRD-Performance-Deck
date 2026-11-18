<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The orders that carried the exclusive POS tag before a supervisor's tag could give a lead to the exclusive CRA
     * (ExclusiveLeadTagging): CRAs had pasted her numbers into Pancake, so those tags never pull a lead. Filled with every
     * order tagged now.
     */
    public function up(): void
    {
        Schema::create('exclusive_old_tags', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->unique();
            $table->timestamps();
        });

        $now = now();
        DB::table('pancake_orders')->whereJsonContains('tags', (int) config('exclusive_leads.order_tag.id'))
            ->select(['id', 'pancake_order_id'])->orderBy('id')
            ->chunk(1000, fn ($orders) => DB::table('exclusive_old_tags')->insertOrIgnore($orders->map(fn (object $order) => [
                'pancake_order_id' => $order->pancake_order_id, 'created_at' => $now, 'updated_at' => $now,
            ])->all()));
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusive_old_tags');
    }
};
