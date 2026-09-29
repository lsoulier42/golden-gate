<?php

namespace App\Gateway\Listener;

use App\Gateway\Error\GatewayErrorResponse;
use App\Gateway\Error\GatewayException;
use App\Gateway\Http\GatewayRateLimiter;
use App\Security\GatewayPrincipal;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces the per-key request rate on every `/v1/*` call.
 *
 * Registered after the firewall (priority 0 < 8), so it only sees authenticated
 * requests and never counts anonymous attempts.
 */
final class GatewayRateLimitListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly GatewayRateLimiter $rateLimiter,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/v1')) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof GatewayPrincipal) {
            return;
        }

        try {
            $this->rateLimiter->assertNotRateLimited($user->getApiKey()->getKeyPrefix());
        } catch (GatewayException $exception) {
            $event->setResponse(GatewayErrorResponse::forException($exception));
        }
    }
}
