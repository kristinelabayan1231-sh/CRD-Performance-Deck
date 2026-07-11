<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('period'); // week | month | year
            $table->string('period_key'); // e.g. 2026-07-08, 2026-07, 2026
            $table->timestamp('computed_at');
            $table->longText('payload');
            $table->timestamps();
            $table->unique(['period', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_dashboard_snapshots');
    }
};
