<?php

namespace App\AI\Gateway;

use App\AI\Dto\ChatCompletionRequest;
use App\AI\Dto\ChatCompletionResult;
use App\AI\Exception\ModelNotFoundException;
use App\AI\Exception\ProviderCallException;
use App\AI\Exception\ProviderNotFoundException;

/**
 * Boundary between the gateway's public contract and the LLM providers.
 *
 * Implementations resolve the public model alias, pick the provider adapter, run the
 * request and normalize the answer. No provider-specific type crosses this interface.
 */
interface ModelGatewayInterface
{
    /**
     * @throws ModelNotFoundException      the public alias is unknown or disabled
     * @throws ProviderNotFoundException   the model points at an unconfigured provider
     * @throws ProviderCallException       the provider rejected or failed the request
     */
    public function complete(ChatCompletionRequest $request): ChatCompletionResult;
}
