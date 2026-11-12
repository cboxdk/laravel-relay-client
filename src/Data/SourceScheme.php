<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** How a provider signs the webhooks it sends to a source: the scheme Relay verifies them with. */
enum SourceScheme: string
{
    case Stripe = 'stripe';
    case Github = 'github';
    case Shopify = 'shopify';
    case Slack = 'slack';
    case Mailgun = 'mailgun';
    case StandardWebhooks = 'standard-webhooks';
    case Cbox = 'cbox';
}
