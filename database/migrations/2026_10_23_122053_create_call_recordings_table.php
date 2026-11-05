<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calls uploaded from each CRA's phone (MacroDroid). The audio lives in Google Drive; only its file id is kept here.
     */
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The lead whose contact number matched; null until one does.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_number');
            // The last 10 digits (09xx…, +639xx… and 9xx… are the same number).
            $table->string('phone_key')->index();
            $table->string('direction')->nullable(); // in | out
            $table->timestamp('called_at')->index();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('drive_file_id');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('original_filename')->nullable();
            $table->timestamps();

            // A file sent again by the phone's catch-up upload is not saved twice.
            $table->unique(['user_id', 'original_filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
