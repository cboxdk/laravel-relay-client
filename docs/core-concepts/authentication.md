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

The client does not know which kind `RELAY_KEY` is, and does not need to.

## Acting for a project

```php
$relay = Relay::forProject($workspace->id);   // a new client; every call sends Relay-Project

$relay->whoami()->project?->externalRef;      // the workspace id
$relay->deliveries();                       // that project's log
```

`forProject()` does not change the facade or the manager it was called on.

To act for one project everywhere, set `RELAY_PROJECT` (`relay.project`). `forProject()`
overrides it for one client, `Relay::withoutProject()` removes it for one client.

## When no project is named

Most of the API acts for one project: publishing, endpoints, sources, the judge, push,
sending domains, suppressions and mail. You do not have to name it. With neither
`forProject()` nor `relay.project`, a call goes out without the `Relay-Project` header and
Relay decides:

- a **project key** acts for the project it is locked to;
- an **organization key** acts for the environment's project `default`, which Relay makes
  the first time it is needed.

```php
Relay::publish('order.paid', $payload);                               // the key's own project, or `default`
Relay::forProject($workspace->id)->publish('order.paid', $payload);  // that workspace's project
```

An app with one project never has to think about projects. An app that keeps a project
per customer must name it on every call: one that forgets lands in `default`, not in an
error. `whoami()`, `projects()`, `deliveries()` and `inbound()` read across the
environment when no project is named.

Before 0.2.0 the client threw `RelayProjectRequired` for such a call, and a project key had
to be announced with `relay.project_key`. Both are gone, as is Relay's `project_required`
answer.

Never take the project from user input without checking that the user belongs to that
workspace: with an organization key, the value picks whose data is read. See the
[threat model](../security/threat-model.md).
