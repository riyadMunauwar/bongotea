<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Who may tell the application, through X-Forwarded-* headers, which
    | scheme a visitor used and which address they came from. The Docker
    | stack sits behind Dokploy's Traefik, which terminates TLS, so it sets
    | `*`: without that every generated URL is http:// on an https:// site.
    |
    | Unset everywhere else, deliberately. On a host with nothing in front of
    | PHP, trusting the headers lets any visitor choose the IP address that
    | tracking and rate limiting see. Either `*`, or comma-separated
    | addresses or CIDR ranges.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
