<?php

test('the site root serves the welcome view', function () {
    $response = $this->get('/');

    /**
     * The root is a plain web route with no Slug and no cache entry of its
     * own, so there is no file for any reader to find and the request reaches
     * PHP whichever serving path is in front of it.
     */
    $response->assertOk()->assertViewIs('welcome');
});
