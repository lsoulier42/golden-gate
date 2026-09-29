<?php

namespace App\Tests\Entity;

use App\Entity\ApiKey;
use App\Money\UsdAmount;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests of the access and limit decisions carried by an API key.
 *
 * These are the rules the gateway enforces before calling a provider, so they are
 * pinned here independently from the HTTP layer.
 */
final class ApiKeyTest extends TestCase
{
    private function key(): ApiKey
    {
        return (new ApiKey())
            ->setName('Test key')
            ->setKeyMaterial('gg_0123456789ab', str_repeat('a', 64));
    }

    public function testAKeyWithoutAllowListMayUseEveryModel(): void
    {
        $key = $this->key();

        $this->assertNull($key->getAllowedModels());
        $this->assertTrue($key->canAccessModel('mistral-small'));
        $this->assertTrue($key->canAccessModel('any-future-model'));
    }

    public function testAnAllowListRestrictsAccessToTheListedModels(): void
    {
        $key = $this->key()->setAllowedModels(['mistral-small', 'qwen-32b']);

        $this->assertTrue($key->canAccessModel('mistral-small'));
        $this->assertTrue($key->canAccessModel('qwen-32b'));
        $this->assertFalse($key->canAccessModel('mistral-medium'));
    }

    public function testAnAllowListIsStoredSortedAndDeduplicated(): void
    {
        $key = $this->key()->setAllowedModels(['qwen-32b', 'mistral-small', 'qwen-32b']);

        $this->assertSame(['mistral-small', 'qwen-32b'], $key->getAllowedModels());
    }

    public function testAnEmptyAllowListMeansUnrestrictedAccess(): void
    {
        // Clearing the list in the admin UI must not silently lock the key out.
        $key = $this->key()->setAllowedModels([]);

        $this->assertNull($key->getAllowedModels());
        $this->assertTrue($key->canAccessModel('mistral-small'));
    }

    public function testAnEnabledKeyWithoutExpiryIsUsable(): void
    {
        $key = $this->key();

        $this->assertTrue($key->isUsableAt(new \DateTimeImmutable()));
        $this->assertFalse($key->isExpiredAt(new \DateTimeImmutable()));
        $this->assertTrue($key->isEnabled());
    }

    public function testADisabledKeyIsNotUsable(): void
    {
        $key = $this->key()->setEnabled(false);

        $this->assertFalse($key->isUsableAt(new \DateTimeImmutable()));
    }

    public function testAnExpiredKeyIsNotUsable(): void
    {
        $key = $this->key()->setExpiresAt(new \DateTimeImmutable('-1 second'));

        $this->assertFalse($key->isUsableAt(new \DateTimeImmutable()));
        $this->assertTrue($key->isExpiredAt(new \DateTimeImmutable()));
    }

    public function testAKeyExpiringInTheFutureIsUsable(): void
    {
        $key = $this->key()->setExpiresAt(new \DateTimeImmutable('+1 day'));

        $this->assertTrue($key->isUsableAt(new \DateTimeImmutable()));
        $this->assertFalse($key->isExpiredAt(new \DateTimeImmutable()));
    }

    public function testNoLimitMeansNoRestriction(): void
    {
        $key = $this->key();

        $this->assertNull($key->getMonthlyRequestLimit());
        $this->assertNull($key->getMonthlyTokenLimit());
        $this->assertNull($key->getMonthlyBudget());
    }

    public function testABudgetIsStoredExactlyAndExposedInUsd(): void
    {
        $key = $this->key()->setMonthlyBudgetUsd('12.25');

        $budget = $key->getMonthlyBudget();
        $this->assertInstanceOf(UsdAmount::class, $budget);
        $this->assertSame(12_250_000, $budget->toMicroUsd());
        $this->assertSame(12.25, $key->getMonthlyBudgetUsd());
    }

    public function testABudgetAcceptsTheShapesAnAdminFormSends(): void
    {
        $this->assertSame(5_000_000, $this->key()->setMonthlyBudgetUsd(5)->getMonthlyBudget()?->toMicroUsd());
        $this->assertSame(5_500_000, $this->key()->setMonthlyBudgetUsd(5.5)->getMonthlyBudget()?->toMicroUsd());
        $this->assertSame(5_500_000, $this->key()->setMonthlyBudgetUsd('5.50')->getMonthlyBudget()?->toMicroUsd());
    }

    public function testABudgetCanBeCleared(): void
    {
        $key = $this->key()->setMonthlyBudgetUsd(5);
        $key->setMonthlyBudgetUsd(null);

        $this->assertNull($key->getMonthlyBudget());
        $this->assertNull($key->getMonthlyBudgetUsd());

        $key->setMonthlyBudgetUsd(5);
        $key->setMonthlyBudgetUsd('');

        $this->assertNull($key->getMonthlyBudget());
    }

    public function testTheSecretIsOnlyEverWritten(): void
    {
        $key = $this->key();

        // There is no getter for the secret nor for the clear-text material: only the
        // prefix and the digest are readable.
        $this->assertSame('gg_0123456789ab', $key->getKeyPrefix());
        $this->assertSame(str_repeat('a', 64), $key->getKeyHash());
        $this->assertFalse(method_exists($key, 'getKey'));
        $this->assertFalse(method_exists($key, 'getSecret'));
    }

    public function testMarkingAKeyAsUsedOnlyMovesTheTimestampForward(): void
    {
        $key = $this->key();
        $this->assertNull($key->getLastUsedAt());

        $usedAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $key->markUsed($usedAt);

        $this->assertSame($usedAt, $key->getLastUsedAt());
    }
}
