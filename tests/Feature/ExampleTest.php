<?php

test('the site root serves the welcome view', function () {
    $response = $this->get('/');

    /**
     * The root is a plain web route with no Slug and no cache entry of its
     * own; nginx falls through to PHP for it.
     */
    $response->assertOk()->assertViewIs('welcome');
});
