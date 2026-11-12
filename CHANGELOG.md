# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `Cbox\Relay\Client\Contracts\RelayTransport` with `Http\HttpRelayTransport` over
  Laravel's HTTP client: `GET /v1/whoami`, `PUT /v1/scopes/{external_ref}`,
  `GET /v1/scopes`, `GET /v1/scopes/{ref}`, `GET /v1/deliveries`.
- The `Relay` manager and the `Relay` facade: `whoami()`, `scopes()->put()/find()/list()`,
  `deliveries()`, `forScope()`, `withoutScope()`.
- Typed value objects (`Identity`, `Scope`, `Delivery`, `Page`, `DeliveryFilter`,
  `Problem`) and typed exceptions for Relay's RFC 9457 problem answers.
- `Cbox\Relay\Client\Testing\FakeRelay`, `Relay::fake()` and `InteractsWithRelay`.

[Unreleased]: https://github.com/cboxdk/laravel-relay-client/commits/main
