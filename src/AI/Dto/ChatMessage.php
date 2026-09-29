<?php

namespace App\AI\Dto;

use App\AI\Exception\InvalidChatRequestException;

/**
 * One message of a chat conversation, normalized to text-only content.
 *
 * The MVP deliberately accepts a plain string content only: multi-modal parts and
 * tool calls are out of scope, and refusing them explicitly is clearer than
 * silently dropping them.
 */
final class ChatMessage
{
    public const MAX_CONTENT_LENGTH = 1_000_000;

    private function __construct(
        private readonly ChatRole $role,
        private readonly string $content,
    ) {
    }

    public static function create(ChatRole $role, string $content): self
    {
        if ('' === trim($content)) {
            throw new InvalidChatRequestException('Message content cannot be empty.');
        }

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new InvalidChatRequestException(\sprintf(
                'Message content exceeds the %d bytes limit.',
                self::MAX_CONTENT_LENGTH,
            ));
        }

        return new self($role, $content);
    }

    public static function system(string $content): self
    {
        return self::create(ChatRole::System, $content);
    }

    public static function user(string $content): self
    {
        return self::create(ChatRole::User, $content);
    }

    public function getRole(): ChatRole
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
