<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Product photos for the CRD Board, from the Drive "Product Photos" folder (services.google_drive.product_photos_folder).
 * The folder is shared with anyone with the link, so it's read from Drive's public folder view rather than the app's
 * Drive connection (which only sees files the app uploaded). A file goes with the product its name starts with, as the
 * Daily I.S. cards match ("Audicure.jpg" => AudiCure). The list is kept an hour, each photo a day.
 */
class ProductPhotos
{
    private const FOLDER_URL = 'https://drive.google.com/embeddedfolderview';

    private const FILE_URL = 'https://drive.google.com/uc';

    /**
     * The photo URL for a product (group or card name), or null when the folder has none for it.
     */
    public function urlFor(string $product): ?string
    {
        $key = DailyIncomeStatement::productKey($product);

        return $key !== null && isset($this->files()[$key]) ? route('product-photos.show', $key) : null;
    }

    /**
     * A product's photo: [bytes, mime type], or null when it has none or Drive doesn't answer.
     *
     * @return array{0: string, 1: string}|null
     */
    public function photo(string $key): ?array
    {
        $fileId = $this->files()[$key] ?? null;
        if ($fileId === null) {
            return null;
        }

        $cached = Cache::remember("product-photos.file.{$fileId}", now()->addDay(), function () use ($fileId) {
            $response = Http::timeout(20)->get(self::FILE_URL, ['export' => 'download', 'id' => $fileId]);
            $type = strtok((string) $response->header('Content-Type'), ';');

            return $response->successful() && str_starts_with($type, 'image/') ? [base64_encode($response->body()), $type] : null;
        });

        return $cached === null ? null : [base64_decode($cached[0]), $cached[1]];
    }

    /**
     * The folder's image files by product key (the first file wins when two match one product).
     *
     * @return array<string, string> key => Drive file id
     */
    private function files(): array
    {
        return Cache::remember('product-photos.files', now()->addHour(), function () {
            $folder = (string) config('services.google_drive.product_photos_folder');
            $response = $folder === '' ? null : Http::timeout(20)->get(self::FOLDER_URL, ['id' => $folder]);
            if ($response === null || ! $response->successful()) {
                return [];
            }

            preg_match_all('/id="entry-([\w-]+)".*?class="flip-entry-title">([^<]+)</s', $response->body(), $entries, PREG_SET_ORDER);
            $files = [];
            foreach ($entries as [, $id, $name]) {
                $name = html_entity_decode($name, ENT_QUOTES);
                $key = preg_match('/\.(jpe?g|png|webp)$/i', $name) ? DailyIncomeStatement::productKey(pathinfo($name, PATHINFO_FILENAME)) : null;
                if ($key !== null && ! isset($files[$key])) {
                    $files[$key] = $id;
                }
            }

            return $files;
        });
    }
}
