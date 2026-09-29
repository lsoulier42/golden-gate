<?php

namespace App\Gateway\Listener;

use App\Security\GatewayPrincipal;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Refreshes `lastUsedAt` of the API key behind a gateway request.
 *
 * Runs after the response has been sent, and with a direct UPDATE: the call itself
 * is what matters for operators, and it must not interfere with the unit of work
 * that may still hold the ApiKey entity.
 */
final class ApiKeyLastUsedListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof GatewayPrincipal) {
            return;
        }

        $apiKeyId = $user->getApiKey()->getId();

        if (null === $apiKeyId) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE api_key SET last_used_at = :now WHERE id = :id',
            [
                'now' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'id' => $apiKeyId,
            ],
        );
    }
}
