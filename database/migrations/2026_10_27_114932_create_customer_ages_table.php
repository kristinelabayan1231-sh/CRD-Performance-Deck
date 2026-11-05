<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product Target Market: each customer's age from an "Age: 17" POS note, by contact number (last 10 digits),
        // with the day of the order it was noted on.
        Schema::create('customer_ages', function (Blueprint $table) {
            $table->id();
            $table->string('phone_key', 20)->unique();
            $table->unsignedTinyInteger('age');
            $table->date('noted_on');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ages');
    }
};
