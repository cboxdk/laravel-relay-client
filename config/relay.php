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
    | connection. A call that runs out throws RelayUnavailable; only the queued
    | publish retries on its own.
    |
    */

    'timeout' => (float) env('RELAY_TIMEOUT', 5),
    'connect_timeout' => (float) env('RELAY_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Default project
    |--------------------------------------------------------------------------
    |
    | Optional. With an organization key, the project every call acts for (its
    | external ref or its `prj_…` id), sent as the `Relay-Project` header. Leave
    | it empty with a project key: that key is already locked to its project (see
    | `project_key` below). `Relay::forProject()` overrides it per call.
    |
    */

    'project' => env('RELAY_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Project key
    |--------------------------------------------------------------------------
    |
    | Most calls act for one project. With an organization key and no project named
    | they throw RelayProjectRequired before anything is sent. Set this to true
    | when RELAY_KEY is a project key: Relay already knows its project, so those
    | calls go out without the header.
    |
    */

    'project_key' => (bool) env('RELAY_PROJECT_KEY', false),

    /*
    |--------------------------------------------------------------------------
    | Publishing events
    |--------------------------------------------------------------------------
    |
    | Relay::publish() does not call Relay in the caller's request: it puts a
    | job on this connection and queue (null is the default of each) after the
    | database transaction commits. The job is attempted `tries` times in all,
    | waiting `backoff` seconds before the second attempt, the third and so on
    | (the last value repeats). With `sync` true the call is made at once, in
    | the caller's process, and its failure is thrown there.
    |
    */

    'publish' => [
        'sync' => (bool) env('RELAY_PUBLISH_SYNC', false),
        'connection' => env('RELAY_PUBLISH_CONNECTION'),
        'queue' => env('RELAY_PUBLISH_QUEUE'),
        'tries' => (int) env('RELAY_PUBLISH_TRIES', 5),
        'backoff' => [10, 30, 120, 600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks from Relay
    |--------------------------------------------------------------------------
    |
    | For the `relay.webhook` route middleware. `secret` is the signing secret
    | Relay gave when the endpoint was registered; `previous_secret` is the old
    | one while a rotation is under way, so no delivery is lost. `tolerance` is
    | how many seconds a delivery's timestamp may be off, either way. With no
    | secret every delivery is refused.
    |
    */

    'webhook' => [
        'secret' => env('RELAY_WEBHOOK_SECRET'),
        'previous_secret' => env('RELAY_WEBHOOK_PREVIOUS_SECRET'),
        'tolerance' => (int) env('RELAY_WEBHOOK_TOLERANCE', 300),
    ],

];
