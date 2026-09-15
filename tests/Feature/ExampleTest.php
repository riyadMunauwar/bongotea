<?php

test('the site root is answered by the application and not by the framework skeleton', function () {
    /**
     * The root used to be a plain `web` route returning an empty view, with no
     * cache entry of its own and a comment in every webserver configuration
     * saying it had no Slug to be keyed on. It now answers from the cache like
     * any other address (ADR-0023).
     *
     * What is left here is the shallowest possible check, in the file that came
     * with the framework: that `/` resolves at all. Everything about *what* it
     * serves, and when, is `SiteRootTest`'s — this deliberately asserts nothing
     * that file already covers.
     */
    $this->get('/')->assertOk();
});
