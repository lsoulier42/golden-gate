<?php

namespace App\Controller\Gateway;

use App\AI\Model\ModelDefinition;
use App\AI\Model\ModelRegistry;
use App\Gateway\Http\GatewayResponseFactory;
use App\Security\GatewayPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * GET /v1/models — the models the requesting API key may actually use.
 *
 * Disabled models are absent, and a key restricted to an allow-list only sees that
 * list: the endpoint never advertises a model the key would be refused on.
 */
#[AsController]
final class ListModelsController extends AbstractController
{
    public function __construct(
        private readonly ModelRegistry $models,
        private readonly GatewayResponseFactory $responseFactory,
    ) {
    }

    #[Route('/v1/models', name: 'gateway_models', methods: ['GET'])]
    public function __invoke(#[CurrentUser] GatewayPrincipal $principal): JsonResponse
    {
        $apiKey = $principal->getApiKey();

        $accessible = array_values(array_filter(
            $this->models->enabled(),
            static fn (ModelDefinition $model): bool => $apiKey->canAccessModel($model->getAlias()),
        ));

        return $this->responseFactory->modelList($accessible);
    }
}
