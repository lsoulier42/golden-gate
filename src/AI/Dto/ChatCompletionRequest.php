<?php

namespace App\AI\Dto;

use App\AI\Exception\InvalidChatRequestException;

/**
 * Provider-agnostic chat completion request, the input of ModelGatewayInterface.
 *
 * The model is a Golden Gate public alias, never an upstream identifier: resolving
 * it is the gateway's job.
 */
final class ChatCompletionRequest
{
    public const MAX_MESSAGES = 500;

    /**
     * @param list<ChatMessage> $messages
     * @param list<string>|null $stop
     */
    public function __construct(
        private readonly string $model,
        private readonly array $messages,
        private readonly ?float $temperature = null,
        private readonly ?int $maxTokens = null,
        private readonly ?float $topP = null,
        private readonly ?array $stop = null,
    ) {
        if ('' === trim($model)) {
            throw new InvalidChatRequestException('The "model" field is required.');
        }

        if ([] === $messages) {
            throw new InvalidChatRequestException('At least one message is required.');
        }

        if (\count($messages) > self::MAX_MESSAGES) {
            throw new InvalidChatRequestException(\sprintf(
                'A request cannot contain more than %d messages.',
                self::MAX_MESSAGES,
            ));
        }

        if (null !== $temperature && ($temperature < 0.0 || $temperature > 2.0)) {
            throw new InvalidChatRequestException('"temperature" must be between 0 and 2.');
        }

        if (null !== $topP && ($topP < 0.0 || $topP > 1.0)) {
            throw new InvalidChatRequestException('"top_p" must be between 0 and 1.');
        }

        if (null !== $maxTokens && $maxTokens < 1) {
            throw new InvalidChatRequestException('"max_tokens" must be a positive integer.');
        }

        if (null !== $stop && [] === $stop) {
            throw new InvalidChatRequestException('"stop" cannot be an empty list.');
        }
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @return list<ChatMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function getTopP(): ?float
    {
        return $this->topP;
    }

    /**
     * @return list<string>|null
     */
    public function getStop(): ?array
    {
        return $this->stop;
    }
}
