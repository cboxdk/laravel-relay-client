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

- An **organization key** acts for the whole organization. It may act for one of its
  scopes by sending the `Relay-Scope` header with the scope's external ref or its
  `scp_…` id.
- A **scope key** is locked to one scope. It must not send `Relay-Scope`.

The client does not know which kind `RELAY_KEY` is and does not check; Relay refuses a
scope key that sends the header.

## Acting for a scope

```php
$relay = Relay::forScope($workspace->id);   // a new client; every call sends Relay-Scope

$relay->whoami()->scope?->externalRef;      // the workspace id
$relay->deliveries();                       // that scope's log
```

`forScope()` does not change the facade or the manager it was called on.

To act for one scope everywhere, set `RELAY_SCOPE` (`relay.scope`). `forScope()`
overrides it for one client, `Relay::withoutScope()` removes it for one client. Leave
`RELAY_SCOPE` empty when `RELAY_KEY` is a scope key.

Never take the scope from user input without checking that the user belongs to that
workspace: with an organization key, the value picks whose data is read. See the
[threat model](../security/threat-model.md).
