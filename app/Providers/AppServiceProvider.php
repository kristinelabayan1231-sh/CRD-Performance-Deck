<?php

namespace App\Providers;

use App\Models\User;
use App\Services\CraIssues;
use App\Services\PancakeSync;
use App\Support\SegmentationOptions;
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

        // Dropdown choices edited in Settings → Segmentation Tracker (none until the settings table exists).
        rescue(fn () => SegmentationOptions::apply(), report: false);

        // Header issues (untagged CRA orders and the like) on every app page. While any are shown,
        // they're looked up in Pancake again after the page is sent, so fixed ones clear on a refresh.
        View::composer('components.layouts.app', function (\Illuminate\View\View $view) {
            $user = auth()->user();
            $cras = $user ? CraIssues::crasFor($user) : collect();
            $issues = $cras->isEmpty() ? null : app(CraIssues::class)->for($cras);

            if ($issues?->contains(fn (array $issue) => $issue['order_id'] !== null)) {
                app(PancakeSync::class)->recheckIssuesLater();
            }

            $view->with('craIssues', $issues);
        });
    }
}
