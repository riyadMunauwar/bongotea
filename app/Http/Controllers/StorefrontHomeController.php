<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The site root: a stub inside the storefront shell until Home is a System
 * Page built from Blocks.
 */
class StorefrontHomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('home');
    }
}
