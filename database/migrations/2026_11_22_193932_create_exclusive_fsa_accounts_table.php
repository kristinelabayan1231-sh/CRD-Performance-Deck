<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The FSA Pancake accounts whose customers belong to an exclusive CRA: a customer no CRA has sold to, whose latest
     * order one of these accounts sold, is hers (ExclusiveFsaCustomers). Edited in the Exclusive Lead Tracker's Settings.
     */
    public function up(): void
    {
        Schema::create('exclusive_fsa_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('seller_name');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'seller_name']);
            $table->index('seller_name');
        });

        // The list given on 2026-11-23 for Joanna (Anna), the exclusive CRA then: Francis Gonzales, Ramileen (both her
        // accounts), Jenny, Shei and Sheila (one account, SHEI), Ejay and Kim Gan.
        $accounts = ['FRANCIS GONZALES', 'RAMILEEN PUEBLAS', 'RAMILEEN GRACE PUEBLAS', 'JENNY AGCAOILE', 'SHEI', 'E JAY FACUN', 'KIM GAN'];
        $now = now();

        foreach (DB::table('users')->whereNotNull('exclusive_from')->pluck('id') as $userId) {
            DB::table('exclusive_fsa_accounts')->insertOrIgnore(array_map(fn (string $account) => [
                'user_id' => $userId, 'seller_name' => $account, 'created_at' => $now, 'updated_at' => $now,
            ], $accounts));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusive_fsa_accounts');
    }
};
