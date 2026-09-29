<?php

namespace App\ApiKey;

use App\Entity\ApiKey;

/**
 * Creates and verifies Golden Gate API keys.
 *
 * Secret layout: `gg_` + 48 hexadecimal characters (24 random bytes, 192 bits of
 * entropy). Such a secret cannot be brute-forced, so a single SHA-256 digest is
 * enough to store it - no slow password hash - which keeps gateway authentication
 * cheap on the hot path.
 *
 * The clear-text secret is never persisted, logged or included in an exception.
 */
final class ApiKeyGenerator
{
    /**
     * Number of hexadecimal characters of the secret kept in clear as the key prefix.
     * 12 characters (48 bits) make accidental collisions between keys negligible while
     * staying short enough to display in the administration UI.
     */
    public const PREFIX_HEX_LENGTH = 12;

    private const SECRET_BYTES = 24;

    public function generate(): GeneratedApiKey
    {
        $secret = ApiKey::KEY_PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));

        return new GeneratedApiKey(
            secret: $secret,
            prefix: $this->extractPrefix($secret),
            hash: $this->hash($secret),
        );
    }

    /**
     * The prefix stored alongside a secret, also used to locate candidate keys
     * during authentication.
     */
    public function extractPrefix(string $secret): string
    {
        return substr($secret, 0, \strlen(ApiKey::KEY_PREFIX) + self::PREFIX_HEX_LENGTH);
    }

    public function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Constant-time comparison, so a wrong key cannot be narrowed down by timing.
     */
    public function verify(string $secret, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($secret));
    }

    /**
     * A syntactically valid key: the `gg_` marker followed by enough random material.
     * Checked before touching the database, so malformed keys cost nothing.
     */
    public function looksLikeApiKey(string $candidate): bool
    {
        return 1 === preg_match(
            '/^' . preg_quote(ApiKey::KEY_PREFIX, '/') . '[0-9a-f]{' . self::SECRET_BYTES * 2 . '}$/',
            $candidate,
        );
    }
}
