<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks;

use Cbox\Relay\Client\Http\Controllers\WebhookController;
use Illuminate\Routing\Router;

/**
 * Registers the package's webhook endpoint: `POST {path}` for the default connection and
 * `POST {path}/{connection}` for a named one (its secrets verify the delivery). Only the
 * `relay.webhook` middleware runs: no session, no CSRF check, nothing that reads or
 * rewrites the body before the signature is checked.
 */
class WebhookRoutes
{
    public const string NAME = 'relay.webhook';

    public const string DEFAULT_PATH = 'relay/webhook';

    public static function register(Router $router, string $path = self::DEFAULT_PATH): void
    {
        $path = trim($path, '/');

        $router->post($path, WebhookController::class)->middleware('relay.webhook')->name(self::NAME);
        $router->post($path.'/{connection}', WebhookController::class)
            ->where('connection', '[A-Za-z0-9_-]+')
            ->middleware('relay.webhook')
            ->name(self::NAME.'.connection');
    }
}
