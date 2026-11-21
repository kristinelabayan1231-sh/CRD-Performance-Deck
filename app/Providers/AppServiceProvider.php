<?php

namespace App\Providers;

use App\Models\SmsReply;
use App\Models\User;
use App\Services\CraIssues;
use App\Services\PancakeSync;
use App\Support\CustomerPrivacy;
use App\Support\SegmentationOptions;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request, so the VIP / High AOV numbering is worked out once per page.
        $this->app->scoped(CustomerPrivacy::class);
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
        Gate::define('product_qty.manage', fn (User $user) => $user->isSuperAdmin());

        // Dropdown choices edited in Settings → Segmentation Tracker (none until the settings table exists).
        rescue(fn () => SegmentationOptions::apply(), report: false);

        // Each heavy Artisan command's peak memory in the logs (config logging.command_memory_mb).
        Event::listen(function (CommandFinished $event) {
            $peakMb = (int) round(memory_get_peak_usage() / 1048576);

            if ($event->command !== null && $peakMb >= config('logging.command_memory_mb')) {
                Log::info('artisan '.str_replace("'", '', (string) $event->input).": peak memory {$peakMb} MB");
            }
        });

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

        // SMS Reminders: the CRA's customers who replied YES and still need a call (top bar button and pop-up).
        View::composer('components.layouts.app', function (\Illuminate\View\View $view) {
            $user = auth()->user();

            if ($user?->can('sms_reminders.view')) {
                $view->with('reorderAlerts', SmsReply::wantsToReorder()->where('user_id', $user->id)->count());
            }
        });
    }
}
