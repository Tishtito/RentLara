<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Who may call the API from another origin:
    |
    |  - the Android/iOS apps (Capacitor web views): https://localhost and capacitor://localhost.
    |    They use bearer tokens, never cookies.
    |  - the React portals when they are served from a different (sub)domain than the API in
    |    production. In dev they are same-origin through the Vite proxy and need nothing here.
    |
    | Never add bare `localhost` to SANCTUM_STATEFUL_DOMAINS: the Android app's origin is
    | https://localhost, which would turn its bearer requests into CSRF-checked cookie requests (419).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'capacitor://localhost,https://localhost,http://localhost',
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Cookies for the web portals when they run on another subdomain of the API.
    'supports_credentials' => true,

];
