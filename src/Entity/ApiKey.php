<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Money\UsdAmount;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Credentials a client application uses to call the gateway.
 *
 * Only a cryptographic hash of the secret is persisted: the clear-text key is
 * returned once by the creation endpoint and can never be recovered afterwards.
 * Authentication tries the candidates sharing a key prefix, then compares hashes
 * with hash_equals, so no timing signal leaks the matching prefix.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_key')]
#[ORM\Index(name: 'idx_api_key_prefix', columns: ['key_prefix'])]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/gateway/api-keys',
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['apiKey:read']],
        ),
        new Get(
            uriTemplate: '/gateway/api-keys/{id}',
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['apiKey:read']],
        ),
        new Patch(
            uriTemplate: '/gateway/api-keys/{id}',
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['apiKey:read']],
            denormalizationContext: ['groups' => ['apiKey:write']],
        ),
        new Delete(
            uriTemplate: '/gateway/api-keys/{id}',
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    paginationItemsPerPage: 20,
    paginationMaximumItemsPerPage: 100,
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'ipartial', 'keyPrefix' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['enabled'])]
class ApiKey extends AbstractEntity
{
    public const KEY_PREFIX = 'gg_';

    #[ORM\Column(length: 120)]
    #[Groups(['apiKey:read', 'apiKey:write'])]
    private string $name = '';

    /**
     * The first characters of the secret, kept in clear to identify a key in the UI
     * without ever storing enough material to reconstruct it.
     */
    #[ORM\Column(name: 'key_prefix', length: 32)]
    #[Groups(['apiKey:read'])]
    private string $keyPrefix = '';

    #[ORM\Column(name: 'key_hash', length: 64)]
    private string $keyHash = '';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['apiKey:read', 'apiKey:write'])]
    private bool $enabled = true;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['apiKey:read', 'apiKey:write'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'monthly_request_limit', type: 'integer', nullable: true)]
    #[Groups(['apiKey:read', 'apiKey:write'])]
    private ?int $monthlyRequestLimit = null;

    #[ORM\Column(name: 'monthly_token_limit', type: 'bigint', nullable: true)]
    #[Groups(['apiKey:read', 'apiKey:write'])]
    private ?int $monthlyTokenLimit = null;

    /**
     * Monthly spend ceiling in USD, stored as an exact integer number of micro-USD
     * so budget comparisons never rely on floating point.
     */
    #[ORM\Column(name: 'monthly_budget_micro_usd', type: 'bigint', nullable: true)]
    private ?int $monthlyBudgetMicroUsd = null;

    /**
     * Public model aliases this key may call. A null value means "every enabled
     * model"; an explicit list restricts the key to those aliases.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'allowed_models', type: 'json', nullable: true)]
    private ?array $allowedModels = null;

    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['apiKey:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[Groups(['apiKey:read'])]
    public function getId(): ?int
    {
        return parent::getId();
    }

    #[Groups(['apiKey:read'])]
    public function getUuid(): ?\Symfony\Component\Uid\Uuid
    {
        return parent::getUuid();
    }

    #[Groups(['apiKey:read'])]
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    /**
     * The secret is only ever passed in, never read back out.
     */
    public function setKeyMaterial(string $keyPrefix, string $keyHash): static
    {
        $this->keyPrefix = $keyPrefix;
        $this->keyHash = $keyHash;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getMonthlyRequestLimit(): ?int
    {
        return $this->monthlyRequestLimit;
    }

    public function setMonthlyRequestLimit(?int $monthlyRequestLimit): static
    {
        $this->monthlyRequestLimit = $monthlyRequestLimit;

        return $this;
    }

    public function getMonthlyTokenLimit(): ?int
    {
        return $this->monthlyTokenLimit;
    }

    public function setMonthlyTokenLimit(?int $monthlyTokenLimit): static
    {
        $this->monthlyTokenLimit = $monthlyTokenLimit;

        return $this;
    }

    public function getMonthlyBudget(): ?UsdAmount
    {
        return null === $this->monthlyBudgetMicroUsd
            ? null
            : UsdAmount::fromMicroUsd($this->monthlyBudgetMicroUsd);
    }

    #[Groups(['apiKey:read'])]
    public function getMonthlyBudgetUsd(): ?float
    {
        return $this->getMonthlyBudget()?->toUsd();
    }

    #[Groups(['apiKey:write'])]
    public function setMonthlyBudgetUsd(int|float|string|null $monthlyBudgetUsd): static
    {
        $this->monthlyBudgetMicroUsd = null === $monthlyBudgetUsd || '' === $monthlyBudgetUsd
            ? null
            : UsdAmount::fromUsd($monthlyBudgetUsd)->toMicroUsd();

        return $this;
    }

    /**
     * @return list<string>|null
     */
    #[Groups(['apiKey:read'])]
    public function getAllowedModels(): ?array
    {
        return $this->allowedModels;
    }

    /**
     * @param list<string>|null $allowedModels
     */
    #[Groups(['apiKey:write'])]
    public function setAllowedModels(?array $allowedModels): static
    {
        if (null === $allowedModels || [] === $allowedModels) {
            $this->allowedModels = null;

            return $this;
        }

        $aliases = array_values(array_unique(array_map(strval(...), $allowedModels)));
        sort($aliases);

        $this->allowedModels = $aliases;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $usedAt): static
    {
        $this->lastUsedAt = $usedAt;

        return $this;
    }

    /**
     * A key is usable when it is enabled and not past its optional expiry date.
     */
    public function isUsableAt(\DateTimeImmutable $moment): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return null === $this->expiresAt || $this->expiresAt > $moment;
    }

    public function isExpiredAt(\DateTimeImmutable $moment): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $moment;
    }

    /**
     * Model access is either unrestricted (null allow-list) or an explicit allow-list.
     */
    public function canAccessModel(string $modelAlias): bool
    {
        return null === $this->allowedModels || \in_array($modelAlias, $this->allowedModels, true);
    }
}
