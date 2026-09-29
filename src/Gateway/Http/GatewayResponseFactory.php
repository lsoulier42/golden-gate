<?php

namespace App\Gateway\Http;

use App\AI\Dto\ChatCompletionResult;
use App\AI\Model\ModelDefinition;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds the OpenAI-style payloads of the public gateway API.
 *
 * Keeping them in one place makes the supported subset explicit: these responses are
 * documented as a subset of the OpenAI schema, not as full compatibility.
 */
final class GatewayResponseFactory
{
    public function chatCompletion(ChatCompletionResult $result): JsonResponse
    {
        return new JsonResponse([
            'id' => $result->getId(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $result->getModelAlias(),
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $result->getContent(),
                ],
                'finish_reason' => $result->getFinishReason() ?? 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $result->getUsage()->getPromptTokens(),
                'completion_tokens' => $result->getUsage()->getCompletionTokens(),
                'total_tokens' => $result->getUsage()->getTotalTokens(),
            ],
        ]);
    }

    /**
     * @param list<ModelDefinition> $models
     */
    public function modelList(array $models): JsonResponse
    {
        $data = [];

        foreach ($models as $model) {
            $data[] = [
                'id' => $model->getAlias(),
                'object' => 'model',
                'created' => 0,
                'owned_by' => $model->getProvider(),
                'description' => $model->getDescription(),
                'context_window' => $model->getContextWindow(),
            ];
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $data,
        ]);
    }
}
