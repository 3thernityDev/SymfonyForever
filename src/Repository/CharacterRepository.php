<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Character>
 */
class CharacterRepository extends ServiceEntityRepository
{
    public const float ONLINE_WINDOW_SECONDS = 15.0;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Character::class);
    }

    /**
     * Online characters within spatial interest radius (or same zone).
     *
     * @return list<Character>
     */
    public function findOnlineNearby(float $x, float $z, float $radius, float $now, ?int $excludeId = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.lastSeenAt >= :cutoff')
            ->andWhere('c.posX BETWEEN :minX AND :maxX')
            ->andWhere('c.posZ BETWEEN :minZ AND :maxZ')
            ->setParameter('cutoff', $now - self::ONLINE_WINDOW_SECONDS)
            ->setParameter('minX', $x - $radius)
            ->setParameter('maxX', $x + $radius)
            ->setParameter('minZ', $z - $radius)
            ->setParameter('maxZ', $z + $radius);

        if ($excludeId !== null) {
            $qb->andWhere('c.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        /** @var list<Character> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @return list<Character>
     */
    public function findOnlineInZone(string $zoneCode, float $now): array
    {
        /** @var list<Character> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.zoneCode = :zone')
            ->andWhere('c.lastSeenAt >= :cutoff')
            ->setParameter('zone', $zoneCode)
            ->setParameter('cutoff', $now - self::ONLINE_WINDOW_SECONDS)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findOneByNameInsensitive(string $name): ?Character
    {
        return $this->createQueryBuilder('c')
            ->where('LOWER(c.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
