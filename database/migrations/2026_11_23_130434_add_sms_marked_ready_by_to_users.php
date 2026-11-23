<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A supervisor can mark a CRA's phone ready for texts in Settings → SMS Reminders (sms_marked_ready_by), for phones
     * already sending without the "Send reminder" macro's confirm step: their texts count as sent once the phone takes
     * them. A phone that confirms a text itself clears it and waits for each confirmation from then on.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sms_marked_ready_by')->nullable()->after('sms_confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sms_marked_ready_by');
        });
    }
};
