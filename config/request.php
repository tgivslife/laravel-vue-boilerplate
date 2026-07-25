<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request ID
    |--------------------------------------------------------------------------
    |
    | Controls how incoming request IDs are validated and trusted.
    |
    | trust_proxy_only - when true, client-supplied IDs are accepted only when
    |   the request originates from a trusted proxy (see TrustProxies / the
    |   TRUSTED_PROXIES env variable). All other requests receive a fresh ID.
    |
    | min_length / max_length - character bounds for a valid client-supplied ID.
    |
    | pattern - PCRE pattern (without length anchors) used to whitelist the
    |   characters allowed in a client-supplied ID.
    |
    */

    'id' => [
        'trust_proxy_only' => (bool) env('REQUEST_ID_TRUST_PROXY_ONLY', false),
        'min_length' => (int) env('REQUEST_ID_MIN_LENGTH', 8),
        'max_length' => (int) env('REQUEST_ID_MAX_LENGTH', 128),
        'pattern' => env('REQUEST_ID_PATTERN', '/^[A-Za-z0-9._-]+$/'),
    ],

];
