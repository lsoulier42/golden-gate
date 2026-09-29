<?php

namespace App\Gateway;

use App\AI\Dto\ChatCompletionRequest;
use App\AI\Dto\ChatCompletionResult;
use App\AI\Exception\ModelNotFoundException;
use App\AI\Exception\ProviderCallException;
use App\AI\Gateway\ModelGatewayInterface;
use App\AI\Model\ModelRegistry;
use App\Entity\ApiKey;
use App\Gateway\Error\GatewayException;
use App\Usage\UsageLimitChecker;
use App\Usage\UsageRecorder;

/**
 * Orchestrates one gateway request: resolve the model, enforce access and quotas,
 * call the provider, then record what happened.
 *
 * The order matters. Access and quota checks run before the provider is called, so
 * a refused request costs nothing upstream; usage is recorded after the answer, from
 * what the provider actually reported.
 */
final class ChatCompletionHandler
{
    public function __construct(
        private readonly ModelRegistry $models,
        private readonly ModelGatewayInterface $modelGateway,
        private readonly UsageLimitChecker $limitChecker,
        private readonly UsageRecorder $usageRecorder,
    ) {
    }

    public function handle(ApiKey $apiKey, ChatCompletionRequest $request): ChatCompletionResult
    {
        $model = $this->resolveModel($request->getModel());

        if (!$apiKey->canAccessModel($model->getAlias())) {
            $this->usageRecorder->logRejection($apiKey, $model->getAlias(), 'model_not_allowed');

            throw GatewayException::modelNotAllowed($model->getAlias());
        }

        try {
            $this->limitChecker->assertWithinLimits($apiKey);
        } catch (GatewayException $exception) {
            $this->usageRecorder->logRejection($apiKey, $model->getAlias(), $exception->getErrorCode()->value);

            throw $exception;
        }

        $requestId = 'chatcmpl-' . bin2hex(random_bytes(12));

        try {
            $result = $this->modelGateway->complete($request);
        } catch (ProviderCallException $exception) {
            $this->usageRecorder->recordFailure($apiKey, $model, $requestId, $exception);

            throw GatewayException::providerFailed($exception->getMessage(), $exception->isTimeout());
        }

        $this->usageRecorder->recordSuccess($apiKey, $result);

        return $result;
    }

    /**
     * @throws GatewayException
     */
    private function resolveModel(string $alias): \App\AI\Model\ModelDefinition
    {
        try {
            return $this->models->get($alias);
        } catch (ModelNotFoundException) {
            throw GatewayException::modelNotFound($alias);
        }
    }
}
