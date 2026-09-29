<?php

namespace App\AI\Gateway;

use App\AI\Dto\ChatCompletionRequest;
use App\AI\Dto\ChatCompletionResult;
use App\AI\Dto\ChatCompletionUsage;
use App\AI\Dto\ChatMessage;
use App\AI\Dto\ChatRole;
use App\AI\Exception\ProviderCallException;
use App\AI\Model\ModelDefinition;
use App\AI\Model\ModelRegistry;
use App\AI\Provider\PlatformFactory;
use App\AI\Provider\ProviderRegistry;
use Symfony\AI\Platform\Exception\ExceptionInterface as SymfonyAiExceptionInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Runs chat completions through Symfony AI.
 *
 * The class contains no provider-specific logic: it resolves the public alias, asks
 * PlatformFactory for the matching platform, converts the normalized request into
 * Symfony AI messages and converts the answer back into a provider-agnostic result.
 */
final class SymfonyAiModelGateway implements ModelGatewayInterface
{
    public function __construct(
        private readonly ModelRegistry $models,
        private readonly ProviderRegistry $providers,
        private readonly PlatformFactory $platformFactory,
    ) {
    }

    public function complete(ChatCompletionRequest $request): ChatCompletionResult
    {
        $model = $this->models->get($request->getModel());
        $provider = $this->providers->get($model->getProvider());

        $startedAt = hrtime(true);

        try {
            $deferredResult = $this->platformFactory
                ->createPlatform($provider)
                ->invoke(
                    $this->platformFactory->createModel($provider, $model->getUpstreamModel()),
                    $this->toMessageBag($request),
                    $this->toOptions($request),
                );

            $content = $deferredResult->asText();
        } catch (SymfonyAiExceptionInterface | HttpClientExceptionInterface $exception) {
            throw ProviderCallException::fromThrowable($exception);
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return $this->toChatCompletionResult($model, $deferredResult, $content, $latencyMs);
    }

    private function toMessageBag(ChatCompletionRequest $request): MessageBag
    {
        $messages = [];

        foreach ($request->getMessages() as $message) {
            $messages[] = $this->toSymfonyAiMessage($message);
        }

        return new MessageBag(...$messages);
    }

    private function toSymfonyAiMessage(ChatMessage $message): SystemMessage|UserMessage|AssistantMessage
    {
        return match ($message->getRole()) {
            ChatRole::System => new SystemMessage($message->getContent()),
            ChatRole::Assistant => new AssistantMessage(new Text($message->getContent())),
            ChatRole::User => new UserMessage(new Text($message->getContent())),
        };
    }

    /**
     * Only parameters the caller actually set are forwarded, so provider defaults
     * stay in charge for everything else.
     *
     * @return array<string, mixed>
     */
    private function toOptions(ChatCompletionRequest $request): array
    {
        $options = [];

        if (null !== $temperature = $request->getTemperature()) {
            $options['temperature'] = $temperature;
        }

        if (null !== $maxTokens = $request->getMaxTokens()) {
            $options['max_tokens'] = $maxTokens;
        }

        if (null !== $topP = $request->getTopP()) {
            $options['top_p'] = $topP;
        }

        if (null !== $stop = $request->getStop()) {
            $options['stop'] = $stop;
        }

        return $options;
    }

    private function toChatCompletionResult(
        ModelDefinition $model,
        DeferredResult $deferredResult,
        string $content,
        int $latencyMs,
    ): ChatCompletionResult {
        $rawData = $this->readRawData($deferredResult->getRawResult());

        return new ChatCompletionResult(
            id: 'chatcmpl-' . bin2hex(random_bytes(12)),
            model: $model,
            content: $content,
            finishReason: $this->readFinishReason($rawData),
            usage: $this->readUsage($deferredResult),
            latencyMs: $latencyMs,
            providerRequestId: isset($rawData['id']) && \is_string($rawData['id']) ? $rawData['id'] : null,
            providerModel: isset($rawData['model']) && \is_string($rawData['model']) ? $rawData['model'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readRawData(RawResultInterface $rawResult): array
    {
        try {
            return $rawResult->getData();
        } catch (\Throwable) {
            // The raw payload is a convenience: accounting must not fail because a
            // provider returned something unexpected alongside usable content.
            return [];
        }
    }

    /**
     * @param array<string, mixed> $rawData
     */
    private function readFinishReason(array $rawData): ?string
    {
        $choices = $rawData['choices'] ?? null;

        if (!\is_array($choices) || !\is_array($choices[0] ?? null)) {
            return null;
        }

        $finishReason = $choices[0]['finish_reason'] ?? null;

        return \is_string($finishReason) ? $finishReason : null;
    }

    private function readUsage(DeferredResult $deferredResult): ChatCompletionUsage
    {
        $tokenUsage = $deferredResult->getMetadata()->get('token_usage');

        if (!$tokenUsage instanceof TokenUsageInterface) {
            return new ChatCompletionUsage();
        }

        return new ChatCompletionUsage(
            promptTokens: $tokenUsage->getPromptTokens(),
            completionTokens: $tokenUsage->getCompletionTokens(),
            totalTokens: $tokenUsage->getTotalTokens(),
        );
    }
}
