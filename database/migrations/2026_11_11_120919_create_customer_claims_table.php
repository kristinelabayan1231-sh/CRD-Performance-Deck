<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The CRA who claimed a customer up for reassignment, by the customer's main number: theirs until someone else claims them.
     */
    public function up(): void
    {
        Schema::create('customer_claims', function (Blueprint $table) {
            $table->id();
            $table->string('phone_key')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_cra_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_claims');
    }
};
