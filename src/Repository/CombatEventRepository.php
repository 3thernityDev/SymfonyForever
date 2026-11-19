<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CombatEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CombatEvent>
 */
class CombatEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CombatEvent::class);
    }

    /**
     * @return list<CombatEvent>
     */
    public function findSinceInZones(array $zoneCodes, int $lastEventId, float $now): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.zoneCode IN (:zones)')
            ->andWhere('e.createdAt >= :cutoff')
            ->setParameter('zones', $zoneCodes)
            ->setParameter('cutoff', $now - 25.0)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults(80);

        if ($lastEventId > 0) {
            $qb->andWhere('e.id > :lastId')->setParameter('lastId', $lastEventId);
        }

        /** @var list<CombatEvent> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function purgeOlderThan(float $cutoffTimestamp): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->where('e.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoffTimestamp)
            ->getQuery()
            ->execute();
    }
}
