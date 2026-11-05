<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CVR Tracker: the CRA's manual-sheet rows for the day that match no Pancake order (backfilled days only),
     * as [{customer, amount}], so the page can say they're missing.
     */
    public function up(): void
    {
        Schema::table('cvr_day_entries', function (Blueprint $table) {
            $table->json('sheet_missing')->nullable()->after('unconfirmed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cvr_day_entries', function (Blueprint $table) {
            $table->dropColumn('sheet_missing');
        });
    }
};
