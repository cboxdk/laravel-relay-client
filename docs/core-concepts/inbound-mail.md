---
title: Inbound mail
description: Claim addresses for a project (one address, a domain, or one Relay gives you), read received mail with its content and attachment links, and erase it.
weight: 29
---

# Inbound mail

> This package talks to Relay's API; Relay's own receiving servers take the mail. The
> package's tests run against the API description and the [fake](../getting-started/testing.md),
> not against a running Relay.

## Routes

```php
use Cbox\Relay\Client\Data\InboundRouteType;

$routes = Relay::forProject($ref)->inboundRoutes();

$routes->register(InboundRouteType::Subdomain, 'acme.cbox.email');     // active at once
$route = $routes->register(InboundRouteType::Domain, 'acme.dk');        // pending
$route->verification;   // DnsRecord: type, name, value. Publish it, then:
$routes->verify($route->id)->active();

$routes->register(InboundRouteType::Prefix, 'cbox.email', prefix: 'r-');   // pending until Relay's operator approves

$routes->list();        // list<InboundRoute>
$routes->remove($id);   // mail already received is kept
```

### One address

```php
$route = $routes->registerAddress('support@acme.dk');

$route->address;        // 'support@acme.dk'
$route->active();       // true at once when the project already sends from acme.dk
$route->verification;   // otherwise the TXT record that proves the domain; publish it, then verify()
```

A domain the project already sends from needs no second proof: its DKIM record shows it
is yours. Mail arrives once the domain's MX records point at Relay, which moves **every**
mailbox at the domain. To leave your mail where it is and receive one address at Relay,
forward it instead:

```php
$route = $routes->registerForwarding('billing@acme.dk');

$route->address;      // an address at Relay's own domain: forward billing@acme.dk to it in your mail system
$route->forwardFor;   // 'billing@acme.dk'
$route->active();     // true at once: there is nothing to prove and nothing to change in DNS
```

Relay does not check that the forwarding is set up, and it does not send received mail
on to another mailbox: the mail is stored and reaches you as `mail.received`.

A pattern has one owner; asking for one that is taken answers 422 (`RelayInvalid`).
`verify()` answers the route either way: `$route->status` says whether the record was
found. For your own domain, also point its MX records at Relay.

Needs `inbound:manage` to change routes, `inbound:read` to list them.

## Received mail

```php
$page = Relay::forProject($ref)->inbound()->list(limit: 20);   // Page<InboundMessage>, summaries
$mail = Relay::forProject($ref)->inbound()->find($id);         // ?InboundMessage with content

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

Relay::forProject($ref)->inbound()->erase($id);   // the row, the raw mail, the content, every attachment
```

- `$mail->html` is whatever the sender wrote. Never put it in a page without
  sanitising it.
- An `auto_reply` is not an answer from a person.
- Download an attachment when you need it; do not store its link.
- `erase()` cannot be undone. It is what an erasure request needs.

`inbound()` works without a project too: an organization key acting for none reads across
its projects.

To be told when mail arrives instead of polling, subscribe an endpoint of your own to
`mail.received`: see [Webhooks from Relay](incoming-webhooks.md).

Needs `inbound:read` to read and `inbound:manage` to erase.
