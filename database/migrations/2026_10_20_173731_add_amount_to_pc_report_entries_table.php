<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PC Report: a row's Amount for a day, typed in like Total Inquiries and Total Engagement.
     */
    public function up(): void
    {
        Schema::table('pc_report_entries', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('engagements');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pc_report_entries', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
