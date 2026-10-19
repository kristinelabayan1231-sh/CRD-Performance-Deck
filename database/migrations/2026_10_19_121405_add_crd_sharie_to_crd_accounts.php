<?php

use App\Models\PancakeEngagement;
use App\Models\Role;
use App\Models\User;
use App\Support\PancakeAccounts;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds CRD Sharie to Settings → Pancake Accounts the way Save accounts does, so her past POS
 * deliveries are relabelled CRD and recent customers' history is checked again.
 */
return new class extends Migration
{
    private const ACCOUNT = 'CRD Sharie';

    public function up(): void
    {
        $accounts = PancakeAccounts::crd();
        $by = User::whereHas('role', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->orderBy('id')->first();

        if ($by === null) {
            return;
        }

        if (! in_array(PancakeEngagement::staffKey(self::ACCOUNT), array_map(PancakeEngagement::staffKey(...), $accounts), true)) {
            $accounts[] = self::ACCOUNT;
        }

        PancakeAccounts::saveCrd($accounts, $by);
    }

    public function down(): void
    {
        // Edit the list in Settings → Pancake Accounts instead.
    }
};
