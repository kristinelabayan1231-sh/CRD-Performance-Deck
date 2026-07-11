<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cras', function (Blueprint $table) {
            // Lets a CRA log in (via the existing Google OAuth + allowed_emails
            // gate) and be recognized as themselves once authenticated.
            $table->string('email')->nullable()->unique()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cras', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
