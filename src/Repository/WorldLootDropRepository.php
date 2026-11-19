<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorldLootDrop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorldLootDrop>
 */
class WorldLootDropRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorldLootDrop::class);
    }

    /**
     * @return list<WorldLootDrop>
     */
    public function findActiveNearby(float $x, float $z, float $radius, float $now): array
    {
        /** @var list<WorldLootDrop> $rows */
        $rows = $this->createQueryBuilder('l')
            ->where('l.expiresAt > :now')
            ->andWhere('l.posX BETWEEN :minX AND :maxX')
            ->andWhere('l.posZ BETWEEN :minZ AND :maxZ')
            ->setParameter('now', $now)
            ->setParameter('minX', $x - $radius)
            ->setParameter('maxX', $x + $radius)
            ->setParameter('minZ', $z - $radius)
            ->setParameter('maxZ', $z + $radius)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function purgeExpired(float $now): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
