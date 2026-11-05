<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Joanna Paclibare (display name "Joanna Rose", Pancake account "CRD ANNA PACLIBARE 2") moves to the Exclusive Lead
     * Tracker from Nov 1, 2026. Only when exactly one active CRA matches; otherwise nobody is changed and a supervisor
     * transfers her in Settings.
     */
    public function up(): void
    {
        $craRole = DB::table('roles')->where('slug', 'cra')->value('id');

        $matches = DB::table('users')->where('role_id', $craRole)->where('is_active', true)->get()
            ->filter(function (object $user) {
                $name = strtolower(trim($user->display_name ?: (string) $user->name));
                $accounts = strtolower(implode(' ', [$user->display_name, $user->name, $user->pancake_name, $user->email]));

                return str_starts_with($name, 'joanna') || str_contains($accounts, 'paclibare');
            });

        if ($matches->count() !== 1) {
            Log::warning('Exclusive Lead Tracker: Joanna Paclibare not transferred automatically', ['matches' => $matches->count()]);

            return;
        }

        DB::table('users')->where('id', $matches->first()->id)->update(['exclusive_from' => '2026-11-01']);
    }

    /**
     * Left in place: the column goes with the migration before this one.
     */
    public function down(): void {}
};
