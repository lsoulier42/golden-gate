<?php

namespace App\Controller\Gateway;

use App\ApiKey\ApiKeyGenerator;
use App\Dto\CreateApiKeyInput;
use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/gateway/api-keys — creates a key and returns its secret exactly once.
 *
 * A plain controller rather than an API Platform operation: the response is not the
 * resource itself. It carries the clear-text key, which is never stored, never
 * logged, and cannot be read again from any other endpoint.
 */
#[AsController]
final class CreateApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly ApiKeyGenerator $generator,
    ) {
    }

    #[Route('/api/gateway/api-keys', name: 'gateway_api_key_create', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] CreateApiKeyInput $input): JsonResponse
    {
        $generated = $this->generator->generate();

        $apiKey = (new ApiKey())
            ->setName($input->name)
            ->setKeyMaterial($generated->getPrefix(), $generated->getHash())
            ->setMonthlyRequestLimit($input->monthlyRequestLimit)
            ->setMonthlyTokenLimit($input->monthlyTokenLimit)
            ->setAllowedModels($input->allowedModels)
            ->setExpiresAt($input->expiresAt);

        if (null !== $input->monthlyBudgetUsd) {
            $apiKey->setMonthlyBudgetUsd($input->monthlyBudgetUsd);
        }

        $this->apiKeys->createOrUpdate($apiKey);

        return new JsonResponse($this->toPayload($apiKey, $generated->getSecret()), Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(ApiKey $apiKey, string $secret): array
    {
        return [
            'id' => $apiKey->getId(),
            'uuid' => $apiKey->getUuid()?->toRfc4122(),
            'name' => $apiKey->getName(),
            'keyPrefix' => $apiKey->getKeyPrefix(),
            'enabled' => $apiKey->isEnabled(),
            'expiresAt' => $apiKey->getExpiresAt()?->format(\DATE_ATOM),
            'monthlyRequestLimit' => $apiKey->getMonthlyRequestLimit(),
            'monthlyTokenLimit' => $apiKey->getMonthlyTokenLimit(),
            'monthlyBudgetUsd' => $apiKey->getMonthlyBudgetUsd(),
            'allowedModels' => $apiKey->getAllowedModels(),
            'lastUsedAt' => $apiKey->getLastUsedAt()?->format(\DATE_ATOM),
            'createdAt' => $apiKey->getCreatedAt()?->format(\DATE_ATOM),
            // Returned once, on creation only. Store it now: it cannot be recovered.
            'key' => $secret,
        ];
    }
}
