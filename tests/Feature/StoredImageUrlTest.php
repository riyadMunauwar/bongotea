<?php

use Illuminate\Support\Facades\Storage;

/**
 * The admin's upload preview fetches a stored image with `fetch()`, which the
 * browser blocks when the address is http:// on an https:// page or names
 * another host — an `<img>` tolerates both, so the storefront never showed
 * it. A disk address built from APP_URL left the preview spinning forever on
 * any installation whose APP_URL differs from the address the admin is on.
 */
it('addresses a stored image from the site root rather than from APP_URL', function () {
    config(['app.url' => 'http://elsewhere.test']);

    expect(Storage::disk('local')->url('brands/logo.png'))->toBe('/storage/brands/logo.png');
});
