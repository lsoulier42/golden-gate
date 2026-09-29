<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated body of POST /api/gateway/api-keys.
 *
 * The monthly limits are optional: a key without limits is only bounded by its
 * model allow-list and the per-minute rate limit.
 */
final class CreateApiKeyInput
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 120)]
    public string $name = '';

    #[Assert\Positive]
    public ?int $monthlyRequestLimit = null;

    #[Assert\Positive]
    public ?int $monthlyTokenLimit = null;

    #[Assert\Positive]
    public ?float $monthlyBudgetUsd = null;

    /**
     * @var list<string>|null
     */
    #[Assert\Type('array')]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    public ?array $allowedModels = null;

    #[Assert\GreaterThan('now')]
    public ?\DateTimeImmutable $expiresAt = null;
}
