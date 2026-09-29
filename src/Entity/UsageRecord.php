<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Money\UsdAmount;
use App\Repository\UsageRecordRepository;
use App\Usage\UsageStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One immutable record per gateway request attempt.
 *
 * Prompt and completion contents are deliberately never stored: usage metadata is
 * enough for observability and avoids keeping client data in the gateway database.
 * The record is append-only: only a GetCollection operation is exposed.
 */
#[ORM\Entity(repositoryClass: UsageRecordRepository::class)]
#[ORM\Table(name: 'usage_record')]
#[ORM\Index(name: 'idx_usage_record_key_created', columns: ['api_key_id', 'created_at'])]
#[ORM\Index(name: 'idx_usage_record_created', columns: ['created_at'])]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/gateway/usage',
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['usage:read']],
        ),
        new Get(
            uriTemplate: '/gateway/usage/{id}',
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['usage:read']],
        ),
    ],
    order: ['createdAt' => 'DESC'],
    paginationItemsPerPage: 30,
    paginationMaximumItemsPerPage: 100,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'modelAlias' => 'exact',
    'provider' => 'exact',
    'status' => 'exact',
    'errorCategory' => 'exact',
    'apiKey.keyPrefix' => 'exact',
    'apiKey.name' => 'ipartial',
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(RangeFilter::class, properties: ['latencyMs'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'totalTokens', 'latencyMs'])]
class UsageRecord extends AbstractEntity
{
    /**
     * Deleted keys must not take their usage history with them, so the relation is
     * nullable and cleared on delete.
     */
    #[ORM\ManyToOne(targetEntity: ApiKey::class)]
    #[ORM\JoinColumn(name: 'api_key_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['usage:read'])]
    private ?ApiKey $apiKey = null;

    /**
     * Snapshot of the key prefix, kept even if the key is later deleted.
     */
    #[ORM\Column(name: 'api_key_prefix', length: 32)]
    #[Groups(['usage:read'])]
    private string $apiKeyPrefix = '';

    /**
     * Gateway-generated identifier of the completion, echoed to the caller.
     */
    #[ORM\Column(name: 'request_id', length: 64)]
    #[Groups(['usage:read'])]
    private string $requestId = '';

    #[ORM\Column(name: 'model_alias', length: 120)]
    #[Groups(['usage:read'])]
    private string $modelAlias = '';

    #[ORM\Column(length: 60)]
    #[Groups(['usage:read'])]
    private string $provider = '';

    #[ORM\Column(name: 'upstream_model', length: 160)]
    #[Groups(['usage:read'])]
    private string $upstreamModel = '';

    #[ORM\Column(type: 'string', length: 16, enumType: UsageStatus::class)]
    #[Groups(['usage:read'])]
    private UsageStatus $status = UsageStatus::Success;

    #[ORM\Column(name: 'error_category', length: 64, nullable: true)]
    #[Groups(['usage:read'])]
    private ?string $errorCategory = null;

    #[ORM\Column(name: 'prompt_tokens', type: Types::INTEGER, nullable: true)]
    #[Groups(['usage:read'])]
    private ?int $promptTokens = null;

    #[ORM\Column(name: 'completion_tokens', type: Types::INTEGER, nullable: true)]
    #[Groups(['usage:read'])]
    private ?int $completionTokens = null;

    #[ORM\Column(name: 'total_tokens', type: Types::BIGINT, nullable: true)]
    #[Groups(['usage:read'])]
    private ?int $totalTokens = null;

    /**
     * Estimated cost in micro-USD, kept as an exact integer (1 micro-USD = 0.000001 USD).
     * The human-readable decimal is derived on read.
     */
    #[ORM\Column(name: 'estimated_cost_micro_usd', type: Types::BIGINT, options: ['default' => 0])]
    private int $estimatedCostMicroUsd = 0;

    #[ORM\Column(name: 'latency_ms', type: Types::INTEGER, nullable: true)]
    #[Groups(['usage:read'])]
    private ?int $latencyMs = null;

    /**
     * Provider-side request identifier, for support investigations. Not returned to
     * the calling application.
     */
    #[ORM\Column(name: 'provider_request_id', length: 190, nullable: true)]
    private ?string $providerRequestId = null;

    /**
     * Model name as reported by the provider, which can be a dated snapshot.
     */
    #[ORM\Column(name: 'provider_model', length: 190, nullable: true)]
    private ?string $providerModel = null;

    #[Groups(['usage:read'])]
    public function getId(): ?int
    {
        return parent::getId();
    }

    #[Groups(['usage:read'])]
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    public function setApiKey(?ApiKey $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getApiKeyPrefix(): string
    {
        return $this->apiKeyPrefix;
    }

    public function setApiKeyPrefix(string $apiKeyPrefix): static
    {
        $this->apiKeyPrefix = $apiKeyPrefix;

        return $this;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): static
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function getModelAlias(): string
    {
        return $this->modelAlias;
    }

    public function setModelAlias(string $modelAlias): static
    {
        $this->modelAlias = $modelAlias;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getUpstreamModel(): string
    {
        return $this->upstreamModel;
    }

    public function setUpstreamModel(string $upstreamModel): static
    {
        $this->upstreamModel = $upstreamModel;

        return $this;
    }

    public function getStatus(): UsageStatus
    {
        return $this->status;
    }

    public function setStatus(UsageStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getErrorCategory(): ?string
    {
        return $this->errorCategory;
    }

    public function setErrorCategory(?string $errorCategory): static
    {
        $this->errorCategory = $errorCategory;

        return $this;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(?int $promptTokens): static
    {
        $this->promptTokens = $promptTokens;

        return $this;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(?int $completionTokens): static
    {
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(?int $totalTokens): static
    {
        $this->totalTokens = $totalTokens;

        return $this;
    }

    public function getEstimatedCost(): UsdAmount
    {
        return UsdAmount::fromMicroUsd($this->estimatedCostMicroUsd);
    }

    public function setEstimatedCost(UsdAmount $estimatedCost): static
    {
        $this->estimatedCostMicroUsd = $estimatedCost->toMicroUsd();

        return $this;
    }

    #[Groups(['usage:read'])]
    public function getEstimatedCostUsd(): float
    {
        return $this->getEstimatedCost()->toUsd();
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function setLatencyMs(?int $latencyMs): static
    {
        $this->latencyMs = $latencyMs;

        return $this;
    }

    public function getProviderRequestId(): ?string
    {
        return $this->providerRequestId;
    }

    public function setProviderRequestId(?string $providerRequestId): static
    {
        $this->providerRequestId = $providerRequestId;

        return $this;
    }

    public function getProviderModel(): ?string
    {
        return $this->providerModel;
    }

    public function setProviderModel(?string $providerModel): static
    {
        $this->providerModel = $providerModel;

        return $this;
    }
}
