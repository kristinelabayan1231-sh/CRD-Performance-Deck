<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_conversation_tags', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            $table->string('tag_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_conversation_tags');
    }
};
