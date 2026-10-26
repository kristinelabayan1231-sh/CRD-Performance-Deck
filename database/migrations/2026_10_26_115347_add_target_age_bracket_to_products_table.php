<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Settings → Age Bracket: the customer ages a product is meant for (either end may be blank).
            $table->unsignedTinyInteger('age_min')->nullable()->after('consumption_days');
            $table->unsignedTinyInteger('age_max')->nullable()->after('age_min');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['age_min', 'age_max']);
        });
    }
};
