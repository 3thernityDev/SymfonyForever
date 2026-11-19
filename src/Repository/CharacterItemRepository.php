<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterItem>
 */
class CharacterItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterItem::class);
    }

    /**
     * @return list<CharacterItem>
     */
    public function findAllForCharacter(Character $character): array
    {
        /** @var list<CharacterItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->where('i.character = :char')
            ->setParameter('char', $character)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CharacterItem>
     */
    public function findBagItemsForCharacter(Character $character): array
    {
        /** @var list<CharacterItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->where('i.character = :char')
            ->andWhere('i.equippedSlot IS NULL')
            ->setParameter('char', $character)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findEquippedInSlot(Character $character, string $slot): ?CharacterItem
    {
        return $this->findOneBy([
            'character' => $character,
            'equippedSlot' => $slot,
        ]);
    }

    public function findStackableInBag(Character $character, string $itemCode): ?CharacterItem
    {
        return $this->findOneBy([
            'character' => $character,
            'itemCode' => $itemCode,
            'equippedSlot' => null,
        ]);
    }
}
