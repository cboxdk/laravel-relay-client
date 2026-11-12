---
title: Architecture
description: One transport contract with an HTTP and an in-memory implementation, a thin manager and facade on top, typed value objects throughout.
weight: 21
---

# Architecture

```
Facades\Relay ─► Relay (manager) ─► Contracts\RelayTransport ─┬─ Http\HttpRelayTransport ─► Cbox Relay
                    └─ Scopes                                  └─ Testing\FakeRelay (in memory)
```

## The contract

`Cbox\Relay\Client\Contracts\RelayTransport` has one method per endpoint:

```php
public function whoami(?string $scope = null): Identity;
public function putScope(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null, ?string $scope = null): Scope;
public function findScope(string $ref, ?string $scope = null): ?Scope;
public function listScopes(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;
public function listDeliveries(DeliveryFilter $filter = new DeliveryFilter, ?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;
```

`$scope` is the scope a call acts for; it becomes the `Relay-Scope` header.

## The manager

`Cbox\Relay\Client\Relay` holds a transport and an optional scope and forwards to the
contract. `scopes()` returns a `Scopes` object with `put`, `find` and `list`.
`forScope()` and `withoutScope()` return a new manager; the one you called them on is
unchanged. The container builds a new manager on every resolution, on whatever
transport is bound at that moment.

## Value objects

Everything the client returns is a typed, readonly object in `Cbox\Relay\Client\Data`:
`Identity`, `Scope`, `Delivery`, `Page`, and the smaller `Organization`, `ApiKey`,
`ScopeReference`, `Environment` and `Problem`. Arrays exist at two places only: where a
JSON body is read (`fromArray`) and where one is written (`toArray`). `Scope::$settings`
is the one free-form bag, because it is a JSON object whose shape belongs to your
product.

A success answer the client cannot read (a missing id, a date that is not one) throws
`RelayMalformedResponse` rather than producing a half-filled object.

## What one call is

One method call is one HTTP request with the configured `timeout` and
`connect_timeout`. Nothing is retried, cached or queued by this package.
