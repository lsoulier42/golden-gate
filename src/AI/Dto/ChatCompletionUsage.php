<?php

namespace App\AI\Dto;

/**
 * Token accounting as reported by the provider.
 *
 * Providers do not always report usage; a null field means "not reported" rather
 * than zero, so downstream accounting can tell the two apart.
 */
final class ChatCompletionUsage
{
    public function __construct(
        private readonly ?int $promptTokens = null,
        private readonly ?int $completionTokens = null,
        private readonly ?int $totalTokens = null,
    ) {
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    /**
     * The provider total when given, otherwise the sum of the known parts.
     */
    public function getTotalTokens(): ?int
    {
        if (null !== $this->totalTokens) {
            return $this->totalTokens;
        }

        if (null === $this->promptTokens && null === $this->completionTokens) {
            return null;
        }

        return ($this->promptTokens ?? 0) + ($this->completionTokens ?? 0);
    }
}
