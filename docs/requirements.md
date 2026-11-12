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
  the container and config contracts, the HTTP client, and the service provider and
  facade base classes.

The package has no other runtime dependencies. No PHP extension beyond what Laravel
itself requires. The HTTP client needs Guzzle, which `laravel/framework` already
requires.

## Services

- A Cbox Relay organization and an API key (`rlk_live_…` or `rlk_test_…`). Managing
  scopes needs the `scopes:manage` ability, reading the delivery log `deliveries:read`.
