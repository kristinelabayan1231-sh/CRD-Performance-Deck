<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'presentation_board.view';

    /** @var list<string> */
    private const ROLES = ['cra-supervisor', 'cra-team-lead'];

    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('slug', self::ROLES)->get() as $role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$current, self::PERMISSION]))),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->whereIn('slug', self::ROLES)->get() as $role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_diff($current, [self::PERMISSION]))),
            ]);
        }
    }
};
