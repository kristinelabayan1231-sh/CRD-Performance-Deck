<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PC Report: a CRA's page row's Total Inquiries and Total Engagement for one day, typed in.
     */
    public function up(): void
    {
        Schema::create('pc_report_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page');
            // '' for the page's one row, DORMANT or ACTIVE once the page is split.
            $table->string('category', 10)->default('');
            $table->date('date');
            $table->unsignedInteger('inquiries')->nullable();
            $table->unsignedInteger('engagements')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'page', 'category', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pc_report_entries');
    }
};
