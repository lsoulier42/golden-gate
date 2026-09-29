<?php

namespace App\Controller\Gateway;

use App\AI\Model\ModelDefinition;
use App\AI\Model\ModelRegistry;
use App\Usage\UsageDashboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration surface of the gateway.
 *
 * Reserved to ROLE_ADMIN: the routes live under /api/gateway, which the starter's
 * JWT firewall already protects, and the access_control rules add the role check.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly UsageDashboard $dashboard,
        private readonly ModelRegistry $models,
    ) {
    }

    #[Route('/api/gateway/dashboard', name: 'gateway_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        return new JsonResponse($this->dashboard->overview());
    }

    /**
     * The configured models, read-only: provider infrastructure stays server-side,
     * so this endpoint reports what the gateway exposes without any credential.
     */
    #[Route('/api/gateway/models', name: 'gateway_models_admin', methods: ['GET'])]
    public function models(): JsonResponse
    {
        return new JsonResponse([
            'models' => array_map(
                static fn (ModelDefinition $model): array => [
                    'alias' => $model->getAlias(),
                    'provider' => $model->getProvider(),
                    'upstreamModel' => $model->getUpstreamModel(),
                    'enabled' => $model->isEnabled(),
                    'inputPricePerMillionUsd' => $model->getInputPricePerMillionUsd(),
                    'outputPricePerMillionUsd' => $model->getOutputPricePerMillionUsd(),
                    'description' => $model->getDescription(),
                    'contextWindow' => $model->getContextWindow(),
                ],
                $this->models->all(),
            ),
        ]);
    }
}
