<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportUsers extends Command
{
    protected $signature = 'users:export {path=storage/app/private/users-export.json : Where to write the file}';

    protected $description = 'Write every role and user (no passwords) to a JSON file for users:import on another server';

    public function handle(): int
    {
        $data = [
            'roles' => Role::orderBy('id')->get()->map(fn (Role $role) => $role->only(['slug', 'name', 'description', 'permissions', 'is_system']))->all(),
            'users' => User::with(['role', 'grantedBy'])->orderBy('id')->get()->map(fn (User $user) => [
                ...$user->only(['email', 'name', 'display_name', 'pancake_name', 'google_id', 'avatar', 'is_active']),
                'role' => $user->role?->slug,
                'granted_by' => $user->grantedBy?->email,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ])->all(),
        ];

        $path = base_path($this->argument('path'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('Exported '.count($data['roles']).' roles and '.count($data['users'])." users to {$this->argument('path')}.");

        return self::SUCCESS;
    }
}
