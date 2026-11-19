<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterQuest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterQuest>
 */
class CharacterQuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterQuest::class);
    }

    /**
     * @return list<CharacterQuest>
     */
    public function findAllForCharacter(Character $character): array
    {
        /** @var list<CharacterQuest> $rows */
        $rows = $this->createQueryBuilder('q')
            ->where('q.character = :char')
            ->setParameter('char', $character)
            ->orderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CharacterQuest>
     */
    public function findActiveForCharacter(Character $character): array
    {
        /** @var list<CharacterQuest> $rows */
        $rows = $this->createQueryBuilder('q')
            ->where('q.character = :char')
            ->andWhere('q.status = :active')
            ->setParameter('char', $character)
            ->setParameter('active', CharacterQuest::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneForCharacterAndCode(Character $character, string $questCode): ?CharacterQuest
    {
        return $this->findOneBy([
            'character' => $character,
            'questCode' => $questCode,
        ]);
    }
}
