<?php

namespace App\Gateway\Listener;

use App\Gateway\Error\GatewayErrorCode;
use App\Gateway\Error\GatewayErrorResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rejects oversized gateway request bodies before they are deserialized.
 *
 * Mirrors the `request_body max_size` limit enforced by FrankenPHP, so the gateway
 * behaves the same way whatever fronts it.
 */
final class GatewayRequestBodyListener implements EventSubscriberInterface
{
    public const MAX_BODY_BYTES = 1_000_000;

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 32]];
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

        $declaredLength = $request->headers->get('Content-Length');

        if (null !== $declaredLength && is_numeric($declaredLength) && (int) $declaredLength > self::MAX_BODY_BYTES) {
            $this->reject($event);
        }
    }

    private function reject(RequestEvent $event): void
    {
        $event->setResponse(new GatewayErrorResponse(
            GatewayErrorCode::RequestTooLarge,
            \sprintf('The request body must not exceed %d bytes.', self::MAX_BODY_BYTES),
        ));
    }
}
