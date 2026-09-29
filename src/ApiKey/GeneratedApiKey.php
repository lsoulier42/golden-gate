<?php

namespace App\ApiKey;

/**
 * The one and only moment a Golden Gate key exists in clear text.
 *
 * `secret` is returned to the administrator by the creation endpoint and is never
 * persisted nor logged; only `prefix` (for display) and `hash` (for verification)
 * reach the database.
 */
final class GeneratedApiKey
{
    public function __construct(
        private readonly string $secret,
        private readonly string $prefix,
        private readonly string $hash,
    ) {
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getHash(): string
    {
        return $this->hash;
    }
}
