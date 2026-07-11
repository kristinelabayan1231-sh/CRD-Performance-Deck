<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cra_pc_day_stats', function (Blueprint $table) {
            // Manual override: Pancake's UI shows a per-user engagement
            // metric (unique customers) that its public API doesn't expose —
            // the API only gives message-event counts. CRAs correct the
            // prefilled number from their statistics screen when needed.
            $table->unsignedInteger('engagement')->nullable()->after('inquiries');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cra_pc_day_stats', function (Blueprint $table) {
            $table->dropColumn('engagement');
        });
    }
};
