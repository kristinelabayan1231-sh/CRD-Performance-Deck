<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PC Report: a CRA's page row (or its Dormant / Active row) with its Tagging and Creation/Update Time.
     */
    public function up(): void
    {
        Schema::create('pc_report_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page');
            // '' for the page's one row, DORMANT or ACTIVE once the page is split.
            $table->string('category', 10)->default('');
            $table->date('from_month')->nullable();
            $table->date('to_month')->nullable();
            $table->string('tagging', 50)->nullable();
            // The page's Customer engagement over the row's days, and when Sync last finished.
            $table->unsignedInteger('engagements')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'page', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pc_report_rows');
    }
};
