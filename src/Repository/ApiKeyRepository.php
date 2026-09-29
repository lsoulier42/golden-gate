<?php

namespace App\Repository;

use App\Entity\ApiKey;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractRepository<ApiKey>
 */
class ApiKeyRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * Candidate keys sharing a prefix.
     *
     * A list is returned because the prefix only narrows the search: the caller must
     * still verify the hash to know which candidate - if any - matches the secret.
     *
     * @return list<ApiKey>
     */
    public function findByPrefix(string $prefix): array
    {
        return $this->findBy(['keyPrefix' => $prefix]);
    }
}
