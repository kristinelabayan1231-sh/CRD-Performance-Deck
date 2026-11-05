<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * POS order tags for Conversion Breakdown: the raw tag ids, and which CRD
     * conversion the order counts as (broadcast or segmentation).
     */
    public function up(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('page_name');
            $table->string('conversion_type', 20)->nullable()->index()->after('tags');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->dropIndex(['conversion_type']);
            $table->dropColumn(['tags', 'conversion_type']);
        });
    }
};
