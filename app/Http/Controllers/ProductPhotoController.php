<?php

namespace App\Http\Controllers;

use App\Services\ProductPhotos;
use Illuminate\Http\Response;

/**
 * A product's photo from the Drive "Product Photos" folder, served by the app so the CRD Board (and its screenshot)
 * loads it from the same site.
 */
class ProductPhotoController extends Controller
{
    public function __invoke(string $key, ProductPhotos $photos): Response
    {
        [$bytes, $type] = $photos->photo($key) ?? abort(404);

        return response($bytes, 200, ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600']);
    }
}
