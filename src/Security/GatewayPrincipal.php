<?php

namespace App\Security;

use App\Entity\ApiKey;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The security principal of a `/v1/*` request.
 *
 * The API key entity is deliberately not a UserInterface: gateway credentials and
 * the administration accounts of the starter stay two distinct authentication
 * contexts, and the entity keeps no security plumbing.
 */
final class GatewayPrincipal implements UserInterface
{
    public const ROLE = 'ROLE_GATEWAY';

    public function __construct(private readonly ApiKey $apiKey)
    {
    }

    public function getApiKey(): ApiKey
    {
        return $this->apiKey;
    }

    public function getUserIdentifier(): string
    {
        // The key prefix identifies the credential without revealing it.
        return $this->apiKey->getKeyPrefix();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: the clear-text secret never reaches this object.
    }
}
