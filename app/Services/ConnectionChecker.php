<?php

namespace App\Services;

use App\Models\PancakePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One small request to each outside connection the deck depends on. Used by
 * `php artisan connections:check` and Settings → Connections.
 */
class ConnectionChecker
{
    public function __construct(private PancakeClient $pancake) {}

    /**
     * @return list<array{name: string, ok: bool, details: string, seconds: float}>
     */
    public function run(): array
    {
        return [
            $this->check('Database', fn () => DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName().' · '.DB::table('users')->count().' users'),
            $this->check('Shecom retention API', function () {
                $response = Http::withToken((string) config('services.shecom.key'))->acceptJson()->timeout(60)
                    ->get(rtrim(config('services.shecom.url'), '/').'/management/retention-stockout');

                return $response->successful() ? number_format((int) $response->json('count')).' stock-outs' : $this->refused($response->status(), $response->json('error'));
            }),
            ...$this->pancakePos(),
            $this->pancakeChat(),
            // Some APIs only accept known addresses; this is the one the server calls out from.
            $this->check('Server outgoing IP', fn () => trim(Http::timeout(15)->get('https://api.ipify.org')->body()) ?: 'unknown'),
        ];
    }

    /**
     * Orders from the POS with each credential that is set: the access token is what syncs use when present.
     *
     * @return list<array{name: string, ok: bool, details: string, seconds: float}>
     */
    private function pancakePos(): array
    {
        $credentials = array_filter([
            'access token (used for syncs)' => ['access_token' => config('services.pancake.access_token')],
            'API key'.(config('services.pancake.access_token') ? ' (fallback)' : ' (used for syncs)') => ['api_key' => config('services.pancake.key')],
        ], fn (array $auth) => reset($auth));

        if (empty($credentials)) {
            return [['name' => 'Pancake POS orders', 'ok' => false, 'details' => 'Neither PANCAKE_ACCESS_TOKEN nor PANCAKE_API_KEY is set', 'seconds' => 0.0]];
        }

        return collect($credentials)->map(fn (array $auth, string $label) => $this->check("Pancake POS orders · {$label}", function () use ($auth) {
            $response = Http::timeout(60)->get(
                rtrim(config('services.pancake.pos_url'), '/').'/shops/'.config('services.pancake.shop_id').'/orders?'
                .http_build_query([...$auth, 'page_size' => 1]).'&fields[]=display_id'
            );

            return $response->json('success')
                ? 'shop '.config('services.pancake.shop_id').' · '.number_format((int) $response->json('total_entries')).' orders'
                : $this->refused($response->status(), $response->json('message'));
        }))->values()->all();
    }

    /**
     * Customer engagements for today on every active page in Settings → Pancake Pages.
     *
     * @return array{name: string, ok: bool, details: string, seconds: float}
     */
    private function pancakeChat(): array
    {
        return $this->check('Pancake chat engagements', function () {
            $pages = PancakePage::active()->orderBy('name')->get();
            $failed = $pages->reject(fn (PancakePage $page) => $this->pancake->checkPage($page)[0])->pluck('name');

            if ($pages->isEmpty() || $failed->isNotEmpty()) {
                $this->refused(0, $pages->isEmpty()
                    ? 'no active pages in Settings → Pancake Pages'
                    : $failed->count().' of '.$pages->count().' pages refused: '.$failed->join(', '));
            }

            return $pages->count().' of '.$pages->count().' pages OK';
        });
    }

    /**
     * @return array{name: string, ok: bool, details: string, seconds: float}
     */
    private function check(string $name, callable $probe): array
    {
        $started = microtime(true);

        try {
            $details = $probe();
            $ok = true;
        } catch (Throwable $e) {
            $details = $e->getMessage();
            $ok = false;
        }

        return ['name' => $name, 'ok' => $ok, 'details' => (string) $details, 'seconds' => round(microtime(true) - $started, 1)];
    }

    private function refused(int $status, ?string $message): never
    {
        throw new RuntimeException(trim(($status ? "HTTP {$status} · " : '').($message ?? 'unknown error')));
    }
}
