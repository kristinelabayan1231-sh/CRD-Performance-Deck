<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS Reminders text the CRAs' tracker lists: the tracker rows (leads) each text is for, so a lead is texted once and
     * its row shows it was texted.
     */
    public function up(): void
    {
        Schema::table('restock_reminders', function (Blueprint $table) {
            $table->json('lead_ids')->nullable()->after('covers');
        });
    }

    public function down(): void
    {
        Schema::table('restock_reminders', function (Blueprint $table) {
            $table->dropColumn('lead_ids');
        });
    }
};
