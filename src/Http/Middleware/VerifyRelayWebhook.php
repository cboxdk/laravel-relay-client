<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Http\Middleware;

use Cbox\Relay\Client\Contracts\WebhookSecrets;
use Cbox\Relay\Client\Exceptions\RelayWebhookRejected;
use Cbox\Relay\Client\RelayConfig;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use Cbox\Relay\Client\Webhooks\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `relay.webhook` route middleware: lets a request through only when it carries a
 * valid `cbox` signature over its raw body, and hands the controller the parsed
 * {@see IncomingWebhook} (`IncomingWebhook::from($request)`).
 *
 * A bad or missing signature answers 401. A body that is signed but is not an envelope
 * Relay sends answers 400. No secret configured answers 500, not 401: that is this
 * app's fault, and a 5xx makes Relay keep retrying while it is fixed.
 *
 * Keep it away from middleware that rewrites the body before it runs: the signature is
 * over the bytes as they arrived.
 */
class VerifyRelayWebhook
{
    public function __construct(
        private readonly WebhookSecrets $secrets,
        private readonly RelayConfig $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();

        try {
            WebhookSignature::verify($body, $request->headers->get(WebhookSignature::HEADER), $this->secrets->for($request), $this->config->webhookTolerance(), Carbon::now()->getTimestamp());

            $request->attributes->set(IncomingWebhook::REQUEST_ATTRIBUTE, IncomingWebhook::parse($body));
        } catch (RelayWebhookRejected $e) {
            return match (true) {
                $e->misconfigured() => new Response('Webhook verification is misconfigured.', Response::HTTP_INTERNAL_SERVER_ERROR),
                $e->reason === RelayWebhookRejected::REASON_MALFORMED_BODY => new Response('Unreadable webhook body.', Response::HTTP_BAD_REQUEST),
                default => new Response('Invalid webhook signature.', Response::HTTP_UNAUTHORIZED),
            };
        }

        return $next($request);
    }
}
