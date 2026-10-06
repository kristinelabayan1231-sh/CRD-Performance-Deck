<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CheckConnections extends Command
{
    protected $signature = 'connections:check';

    protected $description = 'Test the database, Shecom and Pancake connections with one small request each';

    public function handle(): int
    {
        $rows = [
            $this->check('Database', fn () => DB::connection()->getDatabaseName().' · '.DB::table('users')->count().' users'),
            $this->check('Shecom retention API', function () {
                $response = Http::withToken((string) config('services.shecom.key'))->acceptJson()->timeout(60)
                    ->get(rtrim(config('services.shecom.url'), '/').'/management/retention-stockout');

                return $response->successful() ? number_format((int) $response->json('count')).' stock-outs' : $this->refused($response->status(), $response->json('error'));
            }),
            ...$this->pancakePos(),
            $this->pancakeChat(),
        ];

        $this->table(['Connection', 'Result', 'Details', 'Time'], $rows);

        return collect($rows)->contains(fn (array $row) => $row[1] === 'FAIL') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Orders from the POS with each credential that is set: the access token is what syncs use when present.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function pancakePos(): array
    {
        $credentials = array_filter([
            'access token (used for syncs)' => ['access_token' => config('services.pancake.access_token')],
            'API key'.(config('services.pancake.access_token') ? ' (fallback)' : ' (used for syncs)') => ['api_key' => config('services.pancake.key')],
        ], fn (array $auth) => reset($auth));

        if (empty($credentials)) {
            return [['Pancake POS orders', 'FAIL', 'Neither PANCAKE_ACCESS_TOKEN nor PANCAKE_API_KEY is set', '—']];
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
     * Customer engagements for today on every configured page.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function pancakeChat(): array
    {
        return $this->check('Pancake chat engagements', function () {
            $pages = config('services.pancake.pages');
            $day = now(config('segmentation.timezone'))->format('d/m/Y');
            $failed = [];

            foreach ($pages as $page) {
                $response = Http::acceptJson()->timeout(30)->get(
                    rtrim(config('services.pancake.chat_url'), '/')."/pages/{$page['id']}/statistics/customer_engagements",
                    ['page_access_token' => $page['token'], 'date_range' => "{$day} 00:00:00 - {$day} 23:59:59"],
                );

                if (! $response->json('success')) {
                    $failed[] = $page['id'];
                }
            }

            if (empty($pages) || $failed) {
                $this->refused(0, empty($pages) ? 'no PANCAKE_PAGE_* pairs in .env' : count($failed).' of '.count($pages).' pages refused: '.implode(', ', $failed));
            }

            return count($pages).' of '.count($pages).' pages OK';
        });
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function check(string $name, callable $probe): array
    {
        $started = microtime(true);

        try {
            $details = $probe();
            $result = 'OK';
        } catch (Throwable $e) {
            $details = $e->getMessage();
            $result = 'FAIL';
        }

        return [$name, $result, $details, round(microtime(true) - $started, 1).'s'];
    }

    private function refused(int $status, ?string $message): never
    {
        throw new RuntimeException(trim(($status ? "HTTP {$status} · " : '').($message ?? 'unknown error')));
    }
}
