---
title: Authentication
description: The bearer key, whoami, organization keys and scope keys, and acting for a scope with Relay-Scope.
weight: 22
---

# Authentication

Every request carries `Authorization: Bearer <RELAY_KEY>`. Keys look like `rlk_live_…`
or `rlk_test_…`.

## Who am I

```php
$me = Relay::whoami();

$me->organization;   // Organization: id ('org_…'), name
$me->environment;    // Environment::Live or Environment::Test
$me->key;            // ApiKey: id ('key_…'), name, prefix, abilities
$me->scope;          // ?ScopeReference: id, externalRef, name
$me->can('deliveries:read');
$me->scoped();       // whether the call acted for one scope
```

`whoami` never returns the key itself, only its id, name and visible prefix.

## Two kinds of key

- An **organization key** acts for the whole organization. It acts for one of its
  scopes by sending the `Relay-Scope` header with the scope's external ref or its
  `scp_…` id.
- A **scope key** is locked to one scope and needs no header. Naming another scope
  answers 403 (`RelayForbidden`, type `scope_locked`).

The client does not know which kind `RELAY_KEY` is. Tell it with `relay.scope_key`
(below) when it is a scope key.

## Acting for a scope

```php
$relay = Relay::forScope($workspace->id);   // a new client; every call sends Relay-Scope

$relay->whoami()->scope?->externalRef;      // the workspace id
$relay->deliveries();                       // that scope's log
```

`forScope()` does not change the facade or the manager it was called on.

To act for one scope everywhere, set `RELAY_SCOPE` (`relay.scope`). `forScope()`
overrides it for one client, `Relay::withoutScope()` removes it for one client.

## Calls that need a scope

Most of the API only makes sense for one scope: publishing, endpoints, the judge, push,
sending domains, suppressions and mail. With an organization key and no `Relay-Scope`,
Relay answers those 422. The client does not let it get that far: a call that needs a
scope throws `Cbox\Relay\Client\Exceptions\RelayScopeRequired` before anything is sent
when neither `forScope()` nor `relay.scope` names one.

```php
Relay::publish('order.paid', $payload);                       // RelayScopeRequired
Relay::forScope($workspace->id)->publish('order.paid', $payload);
```

`whoami()`, `scopes()`, `deliveries()` and `inbound()` work without a scope.

With a scope key, set `RELAY_SCOPE_KEY=true` (`relay.scope_key`). Relay already knows
the key's scope, so scoped calls go out without the header and nothing is thrown.

The check lives in the `Relay` manager. Code that calls the `RelayTransport` contract
directly passes its own `$scope` and gets Relay's 422 when it is missing.

Never take the scope from user input without checking that the user belongs to that
workspace: with an organization key, the value picks whose data is read. See the
[threat model](../security/threat-model.md).
