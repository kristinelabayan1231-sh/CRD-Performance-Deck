<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * exclusive_lead_tags: an exclusive CRA's leads whose POS order tag the website looks after (ExclusiveLeadTagging).
     * exclusive_removed_leads: leads a supervisor took off an exclusive CRA, kept off her lists from then on.
     */
    public function up(): void
    {
        Schema::create('exclusive_lead_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('tagged_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'tagged_at']);
        });

        Schema::create('exclusive_removed_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('via', 10);
            $table->timestamps();
            $table->unique(['user_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusive_removed_leads');
        Schema::dropIfExists('exclusive_lead_tags');
    }
};
