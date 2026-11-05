<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pancake chat conversations with a phone number, for PC Report's Total Inquiries.
     */
    public function up(): void
    {
        Schema::create('pancake_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pancake_page_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id');
            // When the conversation was created in Pancake (UTC).
            $table->timestamp('started_at');
            $table->json('phone_keys');
            $table->json('tags');
            $table->timestamps();

            $table->unique(['pancake_page_id', 'conversation_id']);
            $table->index(['pancake_page_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pancake_conversations');
    }
};
