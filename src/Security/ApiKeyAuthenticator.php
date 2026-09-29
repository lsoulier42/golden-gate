<?php

namespace App\Security;

use App\ApiKey\ApiKeyGenerator;
use App\Entity\ApiKey;
use App\Gateway\Error\GatewayErrorResponse;
use App\Repository\ApiKeyRepository;
use App\Security\Exception\GatewayAuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Stateless authentication of `/v1/*` requests with a Golden Gate API key.
 *
 * Flow: read the Bearer token, check its `gg_` shape, locate the candidates sharing
 * its prefix, verify the hash in constant time, then reject disabled and expired
 * keys. The authenticated key is exposed as a GatewayPrincipal; refreshing its
 * `lastUsedAt` belongs to the request listener, so authentication stays read-only.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly ApiKeyGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $secret = $this->extractBearerToken($request);

        if (null === $secret) {
            throw GatewayAuthenticationException::invalidApiKey(
                'Missing API key: send it as "Authorization: Bearer gg_…".',
            );
        }

        if (!$this->generator->looksLikeApiKey($secret)) {
            throw GatewayAuthenticationException::invalidApiKey();
        }

        $apiKey = $this->findMatchingKey($secret);

        if (null === $apiKey) {
            $this->logger->warning('Gateway request rejected: unknown API key.', [
                'key_prefix' => $this->generator->extractPrefix($secret),
            ]);

            throw GatewayAuthenticationException::invalidApiKey();
        }

        // A single gate decides whether the key may be used; the specific reason is
        // only resolved to report the right error code.
        if (!$apiKey->isUsableAt(new \DateTimeImmutable())) {
            throw $apiKey->isEnabled()
                ? GatewayAuthenticationException::apiKeyExpired()
                : GatewayAuthenticationException::apiKeyDisabled();
        }

        return new SelfValidatingPassport(new UserBadge(
            $apiKey->getKeyPrefix(),
            static fn (): GatewayPrincipal => new GatewayPrincipal($apiKey),
        ));
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();

        return new PostAuthenticationToken($user, $firewallName, $user->getRoles());
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Let the request continue to the gateway controller.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $failure = $exception instanceof GatewayAuthenticationException
            ? $exception
            : GatewayAuthenticationException::invalidApiKey();

        return new GatewayErrorResponse($failure->getErrorCode(), $failure->getMessage());
    }

    /**
     * Reached when a request carries no Authorization header at all: `supports()`
     * returns false, so no failure handler runs and the entry point answers instead.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $exception = GatewayAuthenticationException::invalidApiKey(
            'Missing API key: send it as "Authorization: Bearer gg_…".',
        );

        return new GatewayErrorResponse($exception->getErrorCode(), $exception->getMessage());
    }

    /**
     * Candidates are located by prefix and then verified by hash: a prefix is only a
     * search hint, never a proof of identity.
     */
    private function findMatchingKey(string $secret): ?ApiKey
    {
        foreach ($this->apiKeys->findByPrefix($this->generator->extractPrefix($secret)) as $candidate) {
            if ($this->generator->verify($secret, $candidate->getKeyHash())) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (null === $header || 1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
