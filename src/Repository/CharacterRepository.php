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
     * Online characters within strict circular spatial interest radius (sorted closest-first).
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

        /** @var list<Character> $candidates */
        $candidates = $qb->getQuery()->getResult();

        $r2 = $radius * $radius;
        $within = [];
        foreach ($candidates as $c) {
            $dx = $c->getPosX() - $x;
            $dz = $c->getPosZ() - $z;
            $d2 = $dx * $dx + $dz * $dz;
            if ($d2 <= $r2) {
                $within[] = ['d2' => $d2, 'c' => $c];
            }
        }

        usort($within, static fn (array $a, array $b): int => $a['d2'] <=> $b['d2']);

        return array_map(static fn (array $row): Character => $row['c'], $within);
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

    /**
     * @return list<Character>
     */
    public function findAllBots(): array
    {
        /** @var list<Character> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.isBot = true')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<Character>
     */
    public function findAliveBots(): array
    {
        /** @var list<Character> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.isBot = true')
            ->andWhere('c.isDead = false')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<Character>
     */
    public function findDeadBots(): array
    {
        /** @var list<Character> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.isBot = true')
            ->andWhere('c.isDead = true')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countOnlineHumans(float $now): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.isBot = false')
            ->andWhere('c.lastSeenAt >= :cutoff')
            ->setParameter('cutoff', $now - self::ONLINE_WINDOW_SECONDS)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOnline(float $now): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.lastSeenAt >= :cutoff')
            ->andWhere('c.isDead = false')
            ->setParameter('cutoff', $now - self::ONLINE_WINDOW_SECONDS)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
