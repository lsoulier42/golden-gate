<?php

namespace App\Tests\AI\Model;

use App\AI\Exception\ModelNotFoundException;
use App\AI\Model\ModelDefinition;
use App\AI\Model\ModelRegistry;
use PHPUnit\Framework\TestCase;

final class ModelRegistryTest extends TestCase
{
    private function registry(): ModelRegistry
    {
        return new ModelRegistry([
            'mistral-small' => [
                'provider' => 'ovhcloud',
                'upstream_model' => 'Mistral-Small-3.2-24B-Instruct-2506',
                'enabled' => true,
                'input_price_per_million_usd' => 0.10,
                'output_price_per_million_usd' => 0.30,
                'description' => 'Mistral Small 3.2',
                'context_window' => 131072,
            ],
            'qwen-32b' => [
                'provider' => 'ovhcloud',
                'upstream_model' => 'Qwen3-32B',
            ],
            'retired-model' => [
                'provider' => 'mistral',
                'upstream_model' => 'mistral-medium-latest',
                'enabled' => false,
            ],
        ]);
    }

    public function testGetResolvesAPublicAliasToItsProviderAndUpstreamModel(): void
    {
        $model = $this->registry()->get('mistral-small');

        $this->assertSame('mistral-small', $model->getAlias());
        $this->assertSame('ovhcloud', $model->getProvider());
        $this->assertSame('Mistral-Small-3.2-24B-Instruct-2506', $model->getUpstreamModel());
        $this->assertTrue($model->isEnabled());
        $this->assertSame(131072, $model->getContextWindow());
    }

    public function testDefaultsAreAppliedWhenOnlyTheRequiredKeysAreGiven(): void
    {
        $model = $this->registry()->get('qwen-32b');

        $this->assertTrue($model->isEnabled());
        $this->assertSame(0.0, $model->getInputPricePerMillionUsd());
        $this->assertSame(0.0, $model->getOutputPricePerMillionUsd());
        $this->assertNull($model->getContextWindow());
    }

    public function testGetRejectsAnUnknownAlias(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->registry()->get('gpt-4o');
    }

    public function testGetRejectsADisabledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->registry()->get('retired-model');
    }

    public function testFindStillResolvesDisabledModelsForAdministration(): void
    {
        $model = $this->registry()->find('retired-model');

        $this->assertInstanceOf(ModelDefinition::class, $model);
        $this->assertFalse($model->isEnabled());
    }

    public function testEnabledOnlyReturnsServableModels(): void
    {
        $aliases = array_map(
            static fn (ModelDefinition $model): string => $model->getAlias(),
            $this->registry()->enabled(),
        );

        $this->assertSame(['mistral-small', 'qwen-32b'], $aliases);
    }
}
