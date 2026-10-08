<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/test/scheme', fn (): string => request()->getScheme().' '.request()->ip());
});

it('reads the scheme and client address the proxy forwards when TRUSTED_PROXIES trusts it', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->get('/test/scheme', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertSee('https 203.0.113.7');
});

it('ignores forwarded headers by default, because only the Docker stack sits behind a proxy', function () {
    $this->get('/test/scheme', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertSee('http 127.0.0.1');
});
