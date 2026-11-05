<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer's Feedback holds several choices: every one picked, the main one first (also kept in `feedback`,
        // which the deck's rules read). Blank on rows saved before, which read as just their `feedback`.
        foreach (['leads', 'pancake_reservations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('feedbacks')->nullable()->after('feedback');
            });
        }
    }

    public function down(): void
    {
        foreach (['leads', 'pancake_reservations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('feedbacks');
            });
        }
    }
};
