<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A restock text is Sent only when the CRA's phone confirms it went out (POST /api/sms-sent from the "Send reminder"
     * macro): handed_at keeps when it was handed to the phone, and sms_confirmed_at when a CRA's phone first confirmed a
     * text (no texts go to a phone before that). Until now a text counted as sent once MacroDroid's server took it, and on
     * 2026-11-23 none had actually gone out (no phone had the macro set up), so the reminders marked sent with no reply
     * from the customer are back to "to send"; they carry over until their product runs out.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('sms_confirmed_at')->nullable()->after('sms_auto_send');
        });

        Schema::table('restock_reminders', function (Blueprint $table) {
            $table->timestamp('handed_at')->nullable()->after('queued_at');
        });

        DB::table('restock_reminders')->where('status', 'sent')
            ->whereNotIn('id', DB::table('sms_replies')->whereNotNull('restock_reminder_id')->select('restock_reminder_id'))
            ->update(['status' => 'pending', 'sent_at' => null, 'queued_at' => null, 'message' => null, 'attempts' => 0, 'error' => null]);
    }

    public function down(): void
    {
        Schema::table('restock_reminders', function (Blueprint $table) {
            $table->dropColumn('handed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_confirmed_at');
        });
    }
};
