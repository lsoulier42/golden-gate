<?php

namespace App\Dto;

use App\AI\Dto\ChatCompletionRequest;
use App\AI\Dto\ChatMessage;
use App\AI\Dto\ChatRole;
use App\AI\Exception\InvalidChatRequestException;
use App\Gateway\Error\GatewayException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated body of POST /v1/chat/completions.
 *
 * Deliberately a separate object from the internal ChatCompletionRequest: the HTTP
 * shape is a public contract that must be validated strictly, while the internal DTO
 * is what the gateway services manipulate.
 *
 * Only the OpenAI-compatible subset the MVP supports is accepted; unknown fields
 * (tools, response_format, n, ...) are ignored rather than half-honoured.
 */
final class ChatCompletionInput
{
    public const MAX_MESSAGES = 500;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $model = '';

    /**
     * Decoded as-is: each entry is validated by toChatCompletionRequest(), because the
     * payload comes from an untrusted client.
     *
     * @var array<int, mixed>
     */
    #[Assert\NotBlank]
    #[Assert\Count(min: 1, max: self::MAX_MESSAGES)]
    public array $messages = [];

    #[Assert\Range(min: 0, max: 2)]
    public ?float $temperature = null;

    #[Assert\Positive]
    public ?int $max_tokens = null;

    #[Assert\Range(min: 0, max: 1)]
    public ?float $top_p = null;

    /**
     * @var string|array<int, mixed>|null
     */
    public string|array|null $stop = null;

    /**
     * @throws GatewayException when the payload is not something the gateway can serve
     */
    public function toChatCompletionRequest(): ChatCompletionRequest
    {
        try {
            return new ChatCompletionRequest(
                model: $this->model,
                messages: $this->toMessages(),
                temperature: $this->temperature,
                maxTokens: $this->max_tokens,
                topP: $this->top_p,
                stop: $this->toStopSequences(),
            );
        } catch (InvalidChatRequestException $exception) {
            throw GatewayException::invalidRequest($exception->getMessage());
        }
    }

    /**
     * @return list<ChatMessage>
     *
     * @throws InvalidChatRequestException
     */
    private function toMessages(): array
    {
        $messages = [];

        foreach ($this->messages as $index => $message) {
            if (!\is_array($message)) {
                throw new InvalidChatRequestException(\sprintf('Message #%d must be an object.', $index));
            }

            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;

            if (!\is_string($role)) {
                throw new InvalidChatRequestException(\sprintf('Message #%d is missing a role.', $index));
            }

            if (!\is_string($content)) {
                // Multi-modal content parts are out of scope: refuse them explicitly
                // instead of silently answering from a truncated conversation.
                throw new InvalidChatRequestException(\sprintf(
                    'Message #%d must have a string "content"; this gateway does not support content parts.',
                    $index,
                ));
            }

            $messages[] = ChatMessage::create(ChatRole::fromString($role), $content);
        }

        return $messages;
    }

    /**
     * @return list<string>|null
     *
     * @throws InvalidChatRequestException
     */
    private function toStopSequences(): ?array
    {
        if (null === $this->stop || '' === $this->stop) {
            return null;
        }

        $sequences = \is_string($this->stop) ? [$this->stop] : $this->stop;

        foreach ($sequences as $sequence) {
            if (!\is_string($sequence) || '' === $sequence) {
                throw new InvalidChatRequestException('"stop" must be a string or a list of non-empty strings.');
            }
        }

        return array_values($sequences);
    }
}
