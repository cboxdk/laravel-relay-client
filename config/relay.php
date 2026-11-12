<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where Cbox Relay is
    |--------------------------------------------------------------------------
    |
    | The Relay API (no trailing slash, e.g. https://api.cboxrelay.com) and an
    | API key (`rlk_live_…` or `rlk_test_…`). With either empty every call
    | throws RelayNotConfigured: nothing is sent anywhere.
    |
    */

    'url' => env('RELAY_URL'),
    'key' => env('RELAY_KEY'),

    /*
    |--------------------------------------------------------------------------
    | How long a call may take
    |--------------------------------------------------------------------------
    |
    | Seconds. `timeout` covers the whole request, `connect_timeout` only the
    | connection. A call that runs out throws RelayUnavailable; the client does
    | not retry on its own.
    |
    */

    'timeout' => (float) env('RELAY_TIMEOUT', 5),
    'connect_timeout' => (float) env('RELAY_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Default scope
    |--------------------------------------------------------------------------
    |
    | Optional. With an organization key, the scope every call acts for (its
    | external ref or its `scp_…` id), sent as the `Relay-Scope` header. Leave
    | it empty with a scope key: that key is already locked to its scope and
    | must not send the header. `Relay::forScope()` overrides it per call.
    |
    */

    'scope' => env('RELAY_SCOPE'),

];
