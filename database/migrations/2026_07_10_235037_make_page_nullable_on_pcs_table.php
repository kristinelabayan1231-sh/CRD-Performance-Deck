<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PCs are fixed workstations; the Facebook page + Pancake account are
     * assigned onto a PC after creation (and can be changed later), so
     * those columns must allow being empty.
     */
    public function up(): void
    {
        Schema::table('pcs', function (Blueprint $table) {
            $table->unsignedBigInteger('facebook_page_id')->nullable()->change();
            $table->string('pancake_user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pcs', function (Blueprint $table) {
            $table->unsignedBigInteger('facebook_page_id')->nullable(false)->change();
            $table->string('pancake_user_id')->nullable(false)->change();
        });
    }
};
