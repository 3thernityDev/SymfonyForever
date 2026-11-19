<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonsterInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonsterInstance>
 */
class MonsterInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonsterInstance::class);
    }

    /**
     * @return list<MonsterInstance>
     */
    public function findByZone(string $zoneCode): array
    {
        /** @var list<MonsterInstance> $result */
        $result = $this->createQueryBuilder('m')
            ->join('m.template', 't')->addSelect('t')
            ->where('m.zoneCode = :zone')
            ->setParameter('zone', $zoneCode)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Strict Euclidean circle AOI query (sorted closest-first).
     *
     * @return list<MonsterInstance>
     */
    public function findNearby(float $x, float $z, float $radius): array
    {
        /** @var list<MonsterInstance> $candidates */
        $candidates = $this->createQueryBuilder('m')
            ->join('m.template', 't')->addSelect('t')
            ->where('m.posX BETWEEN :minX AND :maxX')
            ->andWhere('m.posZ BETWEEN :minZ AND :maxZ')
            ->setParameter('minX', $x - $radius)
            ->setParameter('maxX', $x + $radius)
            ->setParameter('minZ', $z - $radius)
            ->setParameter('maxZ', $z + $radius)
            ->getQuery()
            ->getResult();

        $r2 = $radius * $radius;
        $within = [];
        foreach ($candidates as $m) {
            $dx = $m->getPosX() - $x;
            $dz = $m->getPosZ() - $z;
            $d2 = $dx * $dx + $dz * $dz;
            if ($d2 <= $r2) {
                $within[] = ['d2' => $d2, 'm' => $m];
            }
        }

        usort($within, static fn (array $a, array $b): int => $a['d2'] <=> $b['d2']);

        return array_map(static fn (array $row): MonsterInstance => $row['m'], $within);
    }

    /**
     * @return list<MonsterInstance>
     */
    public function findAliveInRadius(float $x, float $z, float $radius): array
    {
        $candidates = $this->findNearby($x, $z, $radius);
        $r2 = $radius * $radius;

        return array_values(array_filter($candidates, static function (MonsterInstance $m) use ($x, $z, $r2): bool {
            if ($m->getState() === MonsterInstance::STATE_DEAD) {
                return false;
            }
            $dx = $m->getPosX() - $x;
            $dz = $m->getPosZ() - $z;

            return ($dx * $dx + $dz * $dz) <= $r2;
        }));
    }

    /**
     * @return list<MonsterInstance>
     */
    public function findWorldBosses(): array
    {
        /** @var list<MonsterInstance> $result */
        $result = $this->createQueryBuilder('m')
            ->join('m.template', 't')->addSelect('t')
            ->where('t.isWorldBoss = :wb')
            ->setParameter('wb', true)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
