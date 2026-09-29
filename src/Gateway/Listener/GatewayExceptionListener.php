<?php

namespace App\Gateway\Listener;

use App\Gateway\Error\GatewayErrorResponse;
use App\Gateway\Error\GatewayException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns any GatewayException raised while serving `/v1/*` into the stable gateway
 * error contract, so controllers and services can simply throw.
 *
 * The listener is restricted to the gateway paths: administration endpoints keep
 * their own (Problem+JSON) error format.
 */
final class GatewayExceptionListener implements EventSubscriberInterface
{
    private const GATEWAY_PATH_PREFIX = '/v1';

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 8]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if (!$throwable instanceof GatewayException) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), self::GATEWAY_PATH_PREFIX)) {
            return;
        }

        $event->setResponse(GatewayErrorResponse::forException($throwable));
    }
}
