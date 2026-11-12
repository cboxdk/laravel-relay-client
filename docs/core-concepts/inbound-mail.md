---
title: Inbound mail
description: Claim address patterns for a scope, read received mail with its content and attachment links, and erase it.
weight: 29
---

# Inbound mail

> This package talks to Relay's API. Whether mail actually arrives depends on Relay's
> receiving servers, which its API guide says are not running yet. See Relay's own
> status. Everything below works against the API and the [fake](../getting-started/testing.md).

## Routes

```php
use Cbox\Relay\Client\Data\InboundRouteType;

$routes = Relay::forScope($ref)->inboundRoutes();

$routes->register(InboundRouteType::Subdomain, 'acme.cbox.email');     // active at once
$route = $routes->register(InboundRouteType::Domain, 'acme.dk');        // pending
$route->verification;   // DnsRecord: type, name, value. Publish it, then:
$routes->verify($route->id)->active();

$routes->register(InboundRouteType::Prefix, 'cbox.email', prefix: 'r-');   // pending until Relay's operator approves

$routes->list();        // list<InboundRoute>
$routes->remove($id);   // mail already received is kept
```

A pattern has one owner; asking for one that is taken answers 422 (`RelayInvalid`).
`verify()` answers the route either way: `$route->status` says whether the record was
found. For your own domain, also point its MX records at Relay.

Needs `inbound:manage` to change routes, `inbound:read` to list them.

## Received mail

```php
$page = Relay::forScope($ref)->inbound()->list(limit: 20);   // Page<InboundMessage>, summaries
$mail = Relay::forScope($ref)->inbound()->find($id);         // ?InboundMessage with content

$mail->kind;             // 'mail', 'auto_reply', 'bounce', 'complaint', 'dmarc_report', or null
$mail->recipient;        // the address exactly as it was sent to
$mail->from;             // ?MailAddress
$mail->text; $mail->html; $mail->headers;   // only on find(); null and empty in a list
$mail->authentication;   // ['spf' => 'pass', 'dkim' => …]
$mail->parsed();         // false while status is 'received': no content yet

foreach ($mail->attachments as $attachment) {
    $attachment->name; $attachment->type; $attachment->size;
    $attachment->url;    // only on find(), and only good for a short while (15 minutes today)
}

Relay::forScope($ref)->inbound()->erase($id);   // the row, the raw mail, the content, every attachment
```

- `$mail->html` is whatever the sender wrote. Never put it in a page without
  sanitising it.
- An `auto_reply` is not an answer from a person.
- Download an attachment when you need it; do not store its link.
- `erase()` cannot be undone. It is what an erasure request needs.

`inbound()` works without a scope too: an organization key acting for none reads across
its scopes.

To be told when mail arrives instead of polling, subscribe an endpoint of your own to
`mail.received`: see [Webhooks from Relay](incoming-webhooks.md).

Needs `inbound:read` to read and `inbound:manage` to erase.
