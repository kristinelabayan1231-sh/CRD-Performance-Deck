<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "Repeat Purchase" status is gone (the Repeat Purchase? column says it): its leads become Active,
     * and it's dropped from a Status list saved in Settings → Segmentation Tracker.
     */
    public function up(): void
    {
        DB::table('leads')->where('status', 'repeat_purchase')->update(['status' => 'active']);

        $saved = DB::table('settings')->where('key', 'segmentation_options.statuses')->first();
        if ($saved) {
            $options = json_decode((string) $saved->value, true) ?: [];
            unset($options['repeat_purchase']);
            DB::table('settings')->where('id', $saved->id)->update(['value' => json_encode($options)]);
        }
    }

    /**
     * Leads moved to Active can't be told apart afterwards, so there is nothing to undo.
     */
    public function down(): void
    {
        //
    }
};
