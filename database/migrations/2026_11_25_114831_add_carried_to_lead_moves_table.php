<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carried = the lead left its day because it was catered on a later one (Lead::countOnDayCatered()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_moves', function (Blueprint $table) {
            $table->boolean('carried')->default(false);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('lead_moves', function (Blueprint $table) {
            $table->dropIndex(['lead_id']);
            $table->dropColumn('carried');
        });
    }
};
