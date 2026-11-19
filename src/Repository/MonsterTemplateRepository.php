<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonsterTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonsterTemplate>
 */
class MonsterTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonsterTemplate::class);
    }

    public function findOneByCode(string $code): ?MonsterTemplate
    {
        return $this->findOneBy(['code' => $code]);
    }
}
