<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contact numbers confirmed as the same customer in the Customer Database: each row
     * files one number under the customer's main number. Orders themselves are unchanged.
     */
    public function up(): void
    {
        Schema::create('customer_links', function (Blueprint $table) {
            $table->id();
            $table->string('phone_key')->unique();
            $table->string('primary_phone_key')->index();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_links');
    }
};
