<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Facebook pages connected to Pancake, read for chat engagements. Managed
     * in Settings → Pancake Pages instead of PANCAKE_PAGE_* lines in .env.
     */
    public function up(): void
    {
        Schema::create('pancake_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('page_id')->unique();
            // Encrypted with APP_KEY (see the model's cast).
            $table->text('access_token');
            $table->boolean('is_active')->default(true);
            $table->timestamp('checked_at')->nullable();
            $table->boolean('check_ok')->nullable();
            $table->string('check_message')->nullable();
            $table->timestamps();
        });

        if (app()->runningUnitTests()) {
            return;
        }

        // One-time copy of the pages already in .env (PANCAKE_PAGE_<NAME>_ID / _TOKEN).
        $now = now();
        collect($_ENV + $_SERVER + getenv())
            ->filter(fn ($value, $key) => is_string($key) && preg_match('/^PANCAKE_PAGE_(.+)_ID$/', $key) && $value)
            ->each(function ($pageId, $key) use ($now) {
                $token = env(preg_replace('/_ID$/', '_TOKEN', $key));

                if ($token) {
                    DB::table('pancake_pages')->insertOrIgnore([
                        'name' => Str::of($key)->after('PANCAKE_PAGE_')->beforeLast('_ID')->replace('_', ' ')->lower()->title()->toString(),
                        'page_id' => (string) $pageId,
                        'access_token' => Crypt::encryptString($token),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pancake_pages');
    }
};
