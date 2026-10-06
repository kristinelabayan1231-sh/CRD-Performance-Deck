<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportUsers extends Command
{
    protected $signature = 'users:import {path=storage/app/private/users-export.json : File written by users:export}';

    protected $description = 'Add or update roles (by slug) and users (by email) from a users:export file; nothing is deleted';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));

        if (! File::exists($path)) {
            $this->error("No file at {$this->argument('path')}. Run users:export first.");

            return self::FAILURE;
        }

        $data = json_decode(File::get($path), true);

        $this->line('Importing into database: '.DB::connection()->getDatabaseName().' on '.config('database.connections.'.config('database.default').'.host', '(url)'));

        DB::transaction(function () use ($data) {
            foreach ($data['roles'] as $row) {
                Role::updateOrCreate(['slug' => $row['slug']], $row);
            }

            $roles = Role::pluck('id', 'slug');

            foreach ($data['users'] as $row) {
                User::updateOrCreate(['email' => strtolower($row['email'])], [
                    ...array_intersect_key($row, array_flip(['name', 'display_name', 'pancake_name', 'google_id', 'avatar', 'is_active'])),
                    'role_id' => $row['role'] ? $roles[$row['role']] ?? null : null,
                    'last_login_at' => $row['last_login_at'] ? CarbonImmutable::parse($row['last_login_at']) : null,
                ]);
            }

            // Second pass, once everyone exists: who granted each account.
            $ids = User::pluck('id', 'email');
            foreach ($data['users'] as $row) {
                if ($row['granted_by'] && isset($ids[$row['granted_by']])) {
                    User::where('email', strtolower($row['email']))->update(['granted_by' => $ids[$row['granted_by']]]);
                }
            }
        });

        $this->info('Imported '.count($data['roles']).' roles and '.count($data['users']).' users.');

        return self::SUCCESS;
    }
}
