<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * A page view that is too heavy to work out inside the request (CustomerDatabase::$inBackground) and isn't ready yet: it
 * is being prepared in the background (customers:prepare-view), so the page says so and reloads itself until it is.
 */
class ViewPreparing extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response()->view('preparing', ['retryAfter' => 10], 200)->header('Retry-After', '10');
    }
}
