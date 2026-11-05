<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product Consumption: words that make an item not this product even though it has its name (e.g. "Nasal Inhaler"
     * for Sinuxyl), comma-separated.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('exclude_keywords', 1000)->nullable()->after('keywords');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('exclude_keywords');
        });
    }
};
