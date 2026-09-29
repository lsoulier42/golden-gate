<?php

namespace App\AI\Dto;

use App\AI\Exception\InvalidChatRequestException;

/**
 * Role of a chat message, restricted to the subset the gateway accepts.
 */
enum ChatRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';

    public static function fromString(string $role): self
    {
        return self::tryFrom(strtolower(trim($role))) ?? throw new InvalidChatRequestException(\sprintf(
            'Unsupported message role "%s". Supported roles: system, user, assistant.',
            $role,
        ));
    }
}
