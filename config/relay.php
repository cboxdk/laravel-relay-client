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

        // The package's own endpoint: a path such as `relay/webhook` registers
        // POST {path} and POST {path}/{connection}, which verify a delivery, handle it
        // once and dispatch a Laravel event for it. Null registers nothing; a routes
        // file can call Relay::webhookRoutes() instead.
        'route' => env('RELAY_WEBHOOK_ROUTE'),

        // The cache store handled deliveries are remembered in (null: the default
        // store), and for how many seconds. Relay retries a delivery for about a day.
        'cache' => env('RELAY_WEBHOOK_CACHE'),
        'dedupe' => (int) env('RELAY_WEBHOOK_DEDUPE', 172800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local delivery status (optional)
    |--------------------------------------------------------------------------
    |
    | Off by default: nothing is kept here and no database is needed. When on,
    | every mail sent through Relay gets a row in `relay_messages`, and every
    | webhook event about a mail (through the package's webhook route) a row in
    | `relay_message_events` that brings the mail's row up to date. Publish the
    | migration with `php artisan relay:install --store`.
    |
    | `subject` says how a mail's subject is kept: `hash` (its SHA-256, enough
    | to find a mail whose subject you know), `plain`, or `none`.
    |
    */

    'store' => [
        'enabled' => (bool) env('RELAY_STORE', false),
        'connection' => env('RELAY_STORE_CONNECTION'),
        'subject' => env('RELAY_STORE_SUBJECT', 'hash'),
    ],

    /*
    |--------------------------------------------------------------------------
    | More connections (optional)
    |--------------------------------------------------------------------------
    |
    | Everything above is the connection named `default`. An app that talks to
    | Relay with more than one key (another organization, live and test side by
    | side) names the others here and reaches them with
    | Relay::connection('billing'), or per mailer with
    | 'billing' => ['transport' => 'relay', 'connection' => 'billing'].
    |
    | A connection has its own key, project and webhook secret. `url`,
    | `timeout` and `connect_timeout` fall back to the ones above.
    |
    | 'billing' => [
    |     'key' => env('RELAY_BILLING_KEY'),
    |     'project' => env('RELAY_BILLING_PROJECT'),
    |     'project_key' => false,
    |     'webhook' => ['secret' => env('RELAY_BILLING_WEBHOOK_SECRET')],
    | ],
    |
    */

    'connections' => [],

];
