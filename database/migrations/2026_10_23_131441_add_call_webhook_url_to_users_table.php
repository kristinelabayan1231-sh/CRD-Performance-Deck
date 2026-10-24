<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each CRA phone's MacroDroid webhook link: the tracker's Call button sends it the number to dial (stored encrypted).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('call_webhook_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('call_webhook_url');
        });
    }
};
