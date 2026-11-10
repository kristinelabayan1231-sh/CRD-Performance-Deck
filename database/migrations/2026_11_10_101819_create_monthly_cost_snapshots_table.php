<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRD - MC as it stood in each month, for the MC / salaries API: the month's summary and roster, saved through the
     * month and kept as they were once it ends. changed_at = when the figures last changed.
     */
    public function up(): void
    {
        Schema::create('monthly_cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique();
            $table->json('summary');
            $table->json('roster');
            $table->string('content_hash', 64);
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_cost_snapshots');
    }
};
