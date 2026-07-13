<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A row means the CRA signed off their Conversion Breakdown numbers
     * for that date. Finalized days are skipped by pancake:sync-cra-stats
     * (the synced inquiries become a frozen snapshot) and their manual
     * fields are locked in the UI.
     */
    public function up(): void
    {
        Schema::create('cra_day_finalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cra_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cra_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cra_day_finalizations');
    }
};
