<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CVR Tracker, following the CRAs' sheets: the CRA an order counts for when it was made under another seller
     * account (e.g. AJ DELA CRUZ), its place in the CRA's list, and the sheet's Assigned / Pancake.
     */
    public function up(): void
    {
        Schema::table('cvr_order_entries', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('pancake_order_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->nullable()->after('user_id');
            $table->string('lead_source', 10)->nullable()->after('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cvr_order_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['position', 'lead_source']);
        });
    }
};
