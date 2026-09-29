<?php

namespace App\Tests\ApiKey;

use App\ApiKey\ApiKeyGenerator;
use App\Entity\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * The generator is the only place a clear-text key exists, so these tests pin both
 * its shape and the property that matters most: the stored material is a hash, and
 * verification is exact.
 */
final class ApiKeyGeneratorTest extends TestCase
{
    private ApiKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ApiKeyGenerator();
    }

    public function testAGeneratedSecretHasTheGoldenGateShape(): void
    {
        $generated = $this->generator->generate();
        $secret = $generated->getSecret();

        $this->assertStringStartsWith(ApiKey::KEY_PREFIX, $secret);
        // 24 random bytes rendered as hex.
        $this->assertSame(48, strlen(substr($secret, strlen(ApiKey::KEY_PREFIX))));
        $this->assertMatchesRegularExpression('/^gg_[0-9a-f]{48}$/', $secret);
        $this->assertTrue($this->generator->looksLikeApiKey($secret));
    }

    public function testTwoSecretsNeverCollide(): void
    {
        $secrets = [];

        for ($i = 0; $i < 50; ++$i) {
            $secrets[] = $this->generator->generate()->getSecret();
        }

        $this->assertCount(50, array_unique($secrets));
    }

    public function testThePrefixIsASafePrefixOfTheSecret(): void
    {
        $generated = $this->generator->generate();

        $this->assertSame($this->generator->extractPrefix($generated->getSecret()), $generated->getPrefix());
        $this->assertStringStartsWith($generated->getPrefix(), $generated->getSecret());
        // The prefix must be far too short to be usable as a credential.
        $this->assertLessThan(strlen($generated->getSecret()) / 2, strlen($generated->getPrefix()));
    }

    public function testTheStoredHashIsASha256DigestOfTheSecret(): void
    {
        $generated = $this->generator->generate();

        $this->assertSame(64, strlen($generated->getHash()));
        $this->assertSame(hash('sha256', $generated->getSecret()), $generated->getHash());
        $this->assertNotSame($generated->getSecret(), $generated->getHash());
    }

    public function testVerificationAcceptsTheSecretAndRejectsEverythingElse(): void
    {
        $generated = $this->generator->generate();

        $this->assertTrue($this->generator->verify($generated->getSecret(), $generated->getHash()));
        $this->assertFalse($this->generator->verify($generated->getSecret() . 'a', $generated->getHash()));
        $this->assertFalse($this->generator->verify($generated->getPrefix(), $generated->getHash()));
        $this->assertFalse($this->generator->verify($generated->getHash(), $generated->getHash()));
    }

    public function testMalformedCandidatesAreRejectedWithoutTouchingTheDatabase(): void
    {
        $this->assertFalse($this->generator->looksLikeApiKey(''));
        $this->assertFalse($this->generator->looksLikeApiKey('gg_'));
        $this->assertFalse($this->generator->looksLikeApiKey('gg_short'));
        $this->assertFalse($this->generator->looksLikeApiKey('sk_' . str_repeat('a', 48)));
        $this->assertFalse($this->generator->looksLikeApiKey('gg_' . str_repeat('z', 48)));
        // A JWT must not be mistaken for a gateway key.
        $this->assertFalse($this->generator->looksLikeApiKey('eyJ0eXAiOiJKV1QifQ.eyJyb2xlcyI6W119.sig'));
    }
}
