<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

/** The calls {@see FakeRelay} records, one per method of the transport contract. */
enum Operation: string
{
    case Whoami = 'whoami';
    case PutScope = 'put_scope';
    case FindScope = 'find_scope';
    case ListScopes = 'list_scopes';
    case ListDeliveries = 'list_deliveries';

    case ListEventTypes = 'list_event_types';
    case PutEventTypes = 'put_event_types';
    case PublishEvent = 'publish_event';
    case PublishEvents = 'publish_events';
    case ListEvents = 'list_events';
    case FindEvent = 'find_event';
    case RetryWebhookDelivery = 'retry_webhook_delivery';
    case ListEndpoints = 'list_endpoints';
    case CreateEndpoint = 'create_endpoint';
    case FindEndpoint = 'find_endpoint';
    case UpdateEndpoint = 'update_endpoint';
    case DeleteEndpoint = 'delete_endpoint';
    case PauseEndpoint = 'pause_endpoint';
    case ResumeEndpoint = 'resume_endpoint';
    case RotateEndpointSecret = 'rotate_endpoint_secret';
    case PingEndpoint = 'ping_endpoint';
    case ReplayEndpoint = 'replay_endpoint';

    case Judge = 'judge';
    case FindJudgment = 'find_judgment';

    case ListInboundRoutes = 'list_inbound_routes';
    case CreateInboundRoute = 'create_inbound_route';
    case VerifyInboundRoute = 'verify_inbound_route';
    case DeleteInboundRoute = 'delete_inbound_route';
    case ListInbound = 'list_inbound';
    case FindInbound = 'find_inbound';
    case DeleteInbound = 'delete_inbound';

    case PushKey = 'push_key';
    case ListPushSubscriptions = 'list_push_subscriptions';
    case CreatePushSubscription = 'create_push_subscription';
    case DeletePushSubscription = 'delete_push_subscription';
    case SendPush = 'send_push';
    case FindPush = 'find_push';

    case ListDomains = 'list_domains';
    case CreateDomain = 'create_domain';
    case FindDomain = 'find_domain';
    case VerifyDomain = 'verify_domain';
    case DeleteDomain = 'delete_domain';
    case ListSuppressions = 'list_suppressions';
    case CreateSuppression = 'create_suppression';
    case DeleteSuppression = 'delete_suppression';
    case SendMail = 'send_mail';
    case FindMail = 'find_mail';
}
