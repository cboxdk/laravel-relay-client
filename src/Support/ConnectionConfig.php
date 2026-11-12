<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Support;

use SensitiveParameter;

/**
 * One way into Relay: where it is, which key to use, the project that key acts for and
 * the secrets its webhooks are signed with. The top level of `config/relay.php` is the
 * connection named `default`; `relay.connections` adds more, each by name.
 */
readonly class ConnectionConfig
{
    /**
     * @param  string  $url  the API, without a trailing slash; empty when unconfigured
     * @param  string  $key  the API key; empty when unconfigured. Never log it
     * @param  string|null  $project  the project every call acts for unless told otherwise
     * @param  bool  $projectKey  the key is a project key: calls need no `Relay-Project`
     * @param  list<string>  $webhookSecrets  the current secret and, during a rotation, the previous one
     */
    public function __construct(
        public string $name,
        public string $url,
        #[SensitiveParameter]
        public string $key,
        public ?string $project = null,
        public bool $projectKey = false,
        public float $timeout = 5.0,
        public float $connectTimeout = 2.0,
        #[SensitiveParameter]
        public array $webhookSecrets = [],
    ) {}

    public function configured(): bool
    {
        return $this->url !== '' && $this->key !== '';
    }

    /** `live` or `test`, read from the key's prefix (`rlk_live_…`); null when the key is neither. */
    public function environment(): ?string
    {
        return match (true) {
            str_starts_with($this->key, 'rlk_live_') => 'live',
            str_starts_with($this->key, 'rlk_test_') => 'test',
            default => null,
        };
    }
}
