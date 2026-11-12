---
title: Requirements
description: Runtime requirements enforced by the package's composer constraints.
weight: 3
---

# Requirements

The package installs only when your project satisfies the constraints declared in its
`composer.json`. These are the versions the dependency resolver enforces, nothing more.

## PHP

- **PHP `^8.4`**: PHP 8.4 or newer.

## Laravel

- **Laravel 12 or 13**: the package requires the `illuminate/*` components below at
  `^12.0 || ^13.0`.

## Direct dependencies

- **`illuminate/contracts`, `illuminate/http`, `illuminate/support`** `^12.0 || ^13.0`:
  the container and config contracts, the HTTP client and request, and the service
  provider and facade base classes.
- **`illuminate/bus`, `illuminate/queue`** `^12.0 || ^13.0`: the queued publish job.
- **`illuminate/mail`** `^12.0 || ^13.0`: the mail manager the `relay` transport is
  registered with.
- **`illuminate/notifications`** `^12.0 || ^13.0`: the notification the Web Push channel
  receives.
- **`illuminate/routing`** `^12.0 || ^13.0`: the router the `relay.webhook` middleware
  alias is registered with.
- **`symfony/mailer`, `symfony/mime`** `^7.2 || ^8.0`: the transport base class and the
  `Email` the mail transport reads. `illuminate/mail` requires both already.

An app on `laravel/framework` has all of these. The package adds nothing outside
Illuminate and the two Symfony components Laravel's own mailer is built on. No PHP
extension beyond what Laravel itself requires. The HTTP client needs Guzzle, which
`laravel/framework` already requires.

## Services

- A Cbox Relay organization and an API key (`rlk_live_…` or `rlk_test_…`) with the
  abilities for what you use: `scopes:manage`, `deliveries:read`, `events:publish`,
  `events:read`, `endpoints:manage`, `mail:send`, `domains:manage`,
  `suppressions:manage`, `push:send`, `push:manage`, `inbound:read`, `inbound:manage`,
  `judge:run`, `sources:manage`.
- A queue worker, for `Relay::publish()`.
