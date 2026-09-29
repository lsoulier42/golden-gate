<?php

namespace App\Repository;

use App\Entity\ApiKey;
use App\Entity\UsageRecord;
use App\Usage\MonthlyConsumption;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractRepository<UsageRecord>
 */
class UsageRecordRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageRecord::class);
    }

    /**
     * Requests, tokens and spend consumed by one key since a point in time.
     *
     * Aggregated in the database over the whole month, in integer arithmetic, so a
     * quota decision never depends on the size of the result set.
     */
    public function monthlyConsumption(ApiKey $apiKey, \DateTimeImmutable $since): MonthlyConsumption
    {
        $row = $this->createQueryBuilder('record')
            ->select(
                'COUNT(record.id) AS requests',
                'COALESCE(SUM(record.totalTokens), 0) AS tokens',
                'COALESCE(SUM(record.estimatedCostMicroUsd), 0) AS costMicroUsd',
            )
            ->andWhere('record.apiKey = :apiKey')
            ->andWhere('record.createdAt >= :since')
            ->setParameter('apiKey', $apiKey)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleResult();

        return new MonthlyConsumption(
            requests: (int) ($row['requests'] ?? 0),
            tokens: (int) ($row['tokens'] ?? 0),
            costMicroUsd: (int) ($row['costMicroUsd'] ?? 0),
        );
    }

    /**
     * Gateway-wide consumption since a point in time, for the administration dashboard.
     */
    public function consumptionSince(\DateTimeImmutable $since): MonthlyConsumption
    {
        $row = $this->createQueryBuilder('record')
            ->select(
                'COUNT(record.id) AS requests',
                'COALESCE(SUM(record.totalTokens), 0) AS tokens',
                'COALESCE(SUM(record.estimatedCostMicroUsd), 0) AS costMicroUsd',
            )
            ->andWhere('record.createdAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleResult();

        return new MonthlyConsumption(
            requests: (int) ($row['requests'] ?? 0),
            tokens: (int) ($row['tokens'] ?? 0),
            costMicroUsd: (int) ($row['costMicroUsd'] ?? 0),
        );
    }

    /**
     * Request counts per status since a point in time: `['success' => 12, 'error' => 2]`.
     *
     * @return array<string, int>
     */
    public function countByStatusSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('record')
            ->select('record.status AS status', 'COUNT(record.id) AS total')
            ->andWhere('record.createdAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->groupBy('record.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = $row['status'];

            if ($status instanceof \BackedEnum) {
                $status = $status->value;
            }

            $counts[(string) $status] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Usage per public model alias since a point in time.
     *
     * @return list<array{modelAlias: string, provider: string, requests: int, tokens: int, costMicroUsd: int}>
     */
    public function usageByModelSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('record')
            ->select(
                'record.modelAlias AS modelAlias',
                'record.provider AS provider',
                'COUNT(record.id) AS requests',
                'COALESCE(SUM(record.totalTokens), 0) AS tokens',
                'COALESCE(SUM(record.estimatedCostMicroUsd), 0) AS costMicroUsd',
            )
            ->andWhere('record.createdAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->groupBy('record.modelAlias')
            ->addGroupBy('record.provider')
            ->orderBy('requests', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $usage = [];

        foreach ($rows as $row) {
            $usage[] = [
                'modelAlias' => (string) $row['modelAlias'],
                'provider' => (string) $row['provider'],
                'requests' => (int) $row['requests'],
                'tokens' => (int) $row['tokens'],
                'costMicroUsd' => (int) $row['costMicroUsd'],
            ];
        }

        return $usage;
    }
}
