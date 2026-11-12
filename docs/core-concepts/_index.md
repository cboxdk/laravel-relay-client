---
title: Core concepts
description: The transport contract, authentication and projects, publishing events, mail, push, the judge, webhooks from and through Relay, and the typed errors.
weight: 20
---

# Core concepts

- [Architecture](architecture.md): one contract, two implementations, a thin manager
- [Authentication](authentication.md): the key, `whoami`, and acting for a project
- [Projects](projects.md): registering your workspaces with Relay
- [Deliveries](deliveries.md): reading the delivery log
- [Errors](errors.md): the exception for each answer
- [Publishing events](publishing-events.md): `Relay::publish()`, the queued job, idempotency
- [Webhook endpoints](webhook-endpoints.md): event types, endpoints, events and their deliveries
- [The judge](judge.md): score a mail or a template without sending it
- [Inbound mail](inbound-mail.md): claim addresses, read and erase what was received
- [Web Push](web-push.md): the key, subscriptions, messages and the notification channel
- [Outbound mail](outbound-mail.md): domains, suppressions, sending and the mail transport
- [Webhooks from Relay](incoming-webhooks.md): verify a delivery and read Relay's own events
- [Inbound webhooks](inbound-webhooks.md): sources that take a provider's webhooks for a project
- [Local delivery status](delivery-status.md): the optional store of sent mail and its events
- [Connections](connections.md): more than one key from one app
- [Sending limits and holds](sending-limits.md): how much a project may send, asking for more, and mail Relay's guard holds
