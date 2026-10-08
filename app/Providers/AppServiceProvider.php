<?php

namespace App\Providers;

use App\Models\User;
use App\Services\CraIssues;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys(config('access.permissions')) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        Gate::define('roles.manage', fn (User $user) => $user->isSuperAdmin());

        // Header issues (untagged CRA orders and the like) on every app page.
        View::composer('components.layouts.app', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            $cras = $user ? CraIssues::crasFor($user) : collect();

            $view->with('craIssues', $cras->isEmpty() ? null : app(CraIssues::class)->for($cras));
        });
    }
}
