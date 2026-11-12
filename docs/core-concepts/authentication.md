---
title: Authentication
description: The bearer key, whoami, organization keys and project keys, and acting for a project with Relay-Project.
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
$me->project;          // ?ProjectReference: id, externalRef, name
$me->can('deliveries:read');
$me->scoped();       // whether the call acted for one project
```

`whoami` never returns the key itself, only its id, name and visible prefix.

## Two kinds of key

- An **organization key** acts for the whole organization. It acts for one of its
  projects by sending the `Relay-Project` header with the project's external ref or its
  `prj_…` id.
- A **project key** is locked to one project and needs no header. It may name its own
  project; naming another answers 403 (`RelayForbidden`, type `project_locked`).

A project Relay does not know answers 404 (`RelayNotFound`, type `project_not_found`).

The client does not know which kind `RELAY_KEY` is. Tell it with `relay.project_key`
(below) when it is a project key.

## Acting for a project

```php
$relay = Relay::forProject($workspace->id);   // a new client; every call sends Relay-Project

$relay->whoami()->project?->externalRef;      // the workspace id
$relay->deliveries();                       // that project's log
```

`forProject()` does not change the facade or the manager it was called on.

To act for one project everywhere, set `RELAY_PROJECT` (`relay.project`). `forProject()`
overrides it for one client, `Relay::withoutProject()` removes it for one client.

## Calls that need a project

Most of the API only makes sense for one project: publishing, endpoints, sources, the
judge, push, sending domains, suppressions and mail. With an organization key and no
`Relay-Project`, Relay answers those 422 with the type `project_required`. The client does not let it get that far: a call that needs a
project throws `Cbox\Relay\Client\Exceptions\RelayProjectRequired` before anything is sent
when neither `forProject()` nor `relay.project` names one.

```php
Relay::publish('order.paid', $payload);                       // RelayProjectRequired
Relay::forProject($workspace->id)->publish('order.paid', $payload);
```

`whoami()`, `projects()`, `deliveries()` and `inbound()` work without a project.

With a project key, set `RELAY_PROJECT_KEY=true` (`relay.project_key`). Relay already knows
the key's project, so scoped calls go out without the header and nothing is thrown.

The check lives in the `Relay` manager. Code that calls the `RelayTransport` contract
directly passes its own `$project`; when it is missing, Relay's `project_required` answer
becomes the same `RelayProjectRequired`, then with `$e->problem` set. So one catch covers
both.

Never take the project from user input without checking that the user belongs to that
workspace: with an organization key, the value picks whose data is read. See the
[threat model](../security/threat-model.md).
