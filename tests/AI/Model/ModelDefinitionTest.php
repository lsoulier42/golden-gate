<?php

namespace App\Tests\AI\Model;

use App\AI\Exception\InvalidModelConfigurationException;
use App\AI\Model\ModelDefinition;
use PHPUnit\Framework\TestCase;

final class ModelDefinitionTest extends TestCase
{
    public function testCostIsTheWeightedSumOfInputAndOutputTokens(): void
    {
        $model = new ModelDefinition(
            alias: 'mistral-small',
            provider: 'ovhcloud',
            upstreamModel: 'Mistral-Small-3.2-24B-Instruct-2506',
            inputPricePerMillionUsd: 0.10,
            outputPricePerMillionUsd: 0.30,
        );

        // 1,000,000 input tokens at 0.10 USD + 1,000,000 output tokens at 0.30 USD.
        $this->assertSame(400_000, $model->estimateCostMicroUsd(1_000_000, 1_000_000));
    }

    public function testCostOfASmallRequestStaysExact(): void
    {
        $model = new ModelDefinition(
            alias: 'mistral-small',
            provider: 'ovhcloud',
            upstreamModel: 'Mistral-Small-3.2-24B-Instruct-2506',
            inputPricePerMillionUsd: 0.10,
            outputPricePerMillionUsd: 0.30,
        );

        // 1200 input tokens = 120 micro-USD, 800 output tokens = 240 micro-USD.
        $this->assertSame(360, $model->estimateCostMicroUsd(1_200, 800));
    }

    public function testCostRoundsToTheNearestMicroUsd(): void
    {
        $model = new ModelDefinition(
            alias: 'qwen-32b',
            provider: 'ovhcloud',
            upstreamModel: 'Qwen3-32B',
            inputPricePerMillionUsd: 0.09,
            outputPricePerMillionUsd: 0.36,
        );

        // 1 input token at 0.09 USD/M = 0.09 micro-USD, which rounds up to 0.
        $this->assertSame(0, $model->estimateCostMicroUsd(1, 0));
        // 7 tokens give 0.63 micro-USD, which rounds up to 1.
        $this->assertSame(1, $model->estimateCostMicroUsd(7, 0));
    }

    public function testFreeModelsCostNothing(): void
    {
        $model = new ModelDefinition(
            alias: 'local',
            provider: 'ollama',
            upstreamModel: 'llama3.2',
        );

        $this->assertSame(0, $model->estimateCostMicroUsd(10_000, 10_000));
    }

    public function testPricesAreExposedBackInUsdPerMillionTokens(): void
    {
        $model = new ModelDefinition(
            alias: 'mistral-small',
            provider: 'ovhcloud',
            upstreamModel: 'Mistral-Small-3.2-24B-Instruct-2506',
            inputPricePerMillionUsd: 0.10,
            outputPricePerMillionUsd: 0.30,
        );

        $this->assertSame(0.10, $model->getInputPricePerMillionUsd());
        $this->assertSame(0.30, $model->getOutputPricePerMillionUsd());
    }

    public function testAnEmptyAliasIsRejected(): void
    {
        $this->expectException(InvalidModelConfigurationException::class);

        new ModelDefinition(alias: '  ', provider: 'ovhcloud', upstreamModel: 'qwen');
    }

    public function testAMissingUpstreamModelIsRejected(): void
    {
        $this->expectException(InvalidModelConfigurationException::class);

        new ModelDefinition(alias: 'qwen-32b', provider: 'ovhcloud', upstreamModel: '');
    }

    public function testANegativePriceIsRejected(): void
    {
        $this->expectException(InvalidModelConfigurationException::class);

        new ModelDefinition(
            alias: 'qwen-32b',
            provider: 'ovhcloud',
            upstreamModel: 'Qwen3-32B',
            inputPricePerMillionUsd: -1.0,
        );
    }
}
