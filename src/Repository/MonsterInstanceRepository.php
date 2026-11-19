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
     * @return list<MonsterInstance>
     */
    public function findNearby(float $x, float $z, float $radius): array
    {
        /** @var list<MonsterInstance> $result */
        $result = $this->createQueryBuilder('m')
            ->join('m.template', 't')->addSelect('t')
            ->where('m.posX BETWEEN :minX AND :maxX')
            ->andWhere('m.posZ BETWEEN :minZ AND :maxZ')
            ->setParameter('minX', $x - $radius)
            ->setParameter('maxX', $x + $radius)
            ->setParameter('minZ', $z - $radius)
            ->setParameter('maxZ', $z + $radius)
            ->getQuery()
            ->getResult();

        return $result;
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
}
