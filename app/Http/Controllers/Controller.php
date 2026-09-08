<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

abstract class Controller
{
    /**
     * A page addressed by an order number or an invoice reference: never
     * stored by a cache or a proxy, never indexed, because the URL is a bearer
     * handle to an Order.
     *
     * @param  array<string, mixed>  $data
     */
    protected function uncachedPage(string $view, array $data): Response
    {
        return response()
            ->view($view, $data)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
