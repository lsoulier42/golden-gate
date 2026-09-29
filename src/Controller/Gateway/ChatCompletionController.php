<?php

namespace App\Controller\Gateway;

use App\Dto\ChatCompletionInput;
use App\Gateway\ChatCompletionHandler;
use App\Gateway\Error\GatewayException;
use App\Gateway\Http\GatewayResponseFactory;
use App\Security\GatewayPrincipal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * POST /v1/chat/completions — the public, provider-agnostic entry point.
 *
 * The body is deserialized and validated explicitly rather than through
 * MapRequestPayload, so that every failure answers with the gateway error contract
 * instead of the default violation list of the administration API.
 */
#[AsController]
final class ChatCompletionController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly ChatCompletionHandler $handler,
        private readonly GatewayResponseFactory $responseFactory,
    ) {
    }

    #[Route('/v1/chat/completions', name: 'gateway_chat_completions', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] GatewayPrincipal $principal): JsonResponse
    {
        $input = $this->deserialize($request);

        $violations = $this->validator->validate($input);

        if (\count($violations) > 0) {
            throw GatewayException::invalidRequest((string) $violations->get(0)->getMessage());
        }

        $result = $this->handler->handle(
            $principal->getApiKey(),
            $input->toChatCompletionRequest(),
        );

        return $this->responseFactory->chatCompletion($result);
    }

    private function deserialize(Request $request): ChatCompletionInput
    {
        $content = $request->getContent();

        if ('' === trim($content)) {
            throw GatewayException::invalidRequest('The request body must be a JSON object.');
        }

        try {
            return $this->serializer->deserialize($content, ChatCompletionInput::class, 'json');
        } catch (SerializerExceptionInterface | \ValueError | \TypeError) {
            throw GatewayException::invalidRequest('The request body is not a valid chat completion payload.');
        }
    }
}
