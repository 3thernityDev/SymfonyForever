<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return list<ChatMessage>
     */
    public function findVisibleSince(string $zoneCode, ?int $partyId, int $lastChatId): array
    {
        $qb = $this->createQueryBuilder('m');

        $or = $qb->expr()->orX(
            'm.channel = :global',
            'm.channel = :system',
            $qb->expr()->andX('m.channel = :zone', 'm.zoneCode = :zoneCode'),
        );

        if ($partyId !== null) {
            $or->add($qb->expr()->andX('m.channel = :party', 'm.partyId = :partyId'));
            $qb->setParameter('party', ChatMessage::CHANNEL_PARTY)
               ->setParameter('partyId', $partyId);
        }

        $qb->where($or)
            ->setParameter('global', ChatMessage::CHANNEL_GLOBAL)
            ->setParameter('system', ChatMessage::CHANNEL_SYSTEM)
            ->setParameter('zone', ChatMessage::CHANNEL_ZONE)
            ->setParameter('zoneCode', $zoneCode);

        if ($lastChatId > 0) {
            $qb->andWhere('m.id > :lastId')
               ->setParameter('lastId', $lastChatId)
               ->orderBy('m.id', 'ASC')
               ->setMaxResults(40);

            /** @var list<ChatMessage> $result */
            $result = $qb->getQuery()->getResult();

            return $result;
        }

        // Initial load: return latest 20 messages in chronological order
        /** @var list<ChatMessage> $latest */
        $latest = $qb->orderBy('m.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return array_reverse($latest);
    }
}
