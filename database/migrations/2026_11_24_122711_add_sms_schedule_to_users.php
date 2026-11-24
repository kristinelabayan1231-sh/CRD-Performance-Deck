<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A CRA's own schedule for automatic restock texts (SMS Reminders → Schedule): send time, sending windows and days
     * of the week. Null follows the default in Settings → SMS Reminders.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('sms_schedule')->nullable()->after('sms_auto_send');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_schedule');
        });
    }
};
