<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'chat_message')]
#[ORM\Index(name: 'idx_chat_channel_id', columns: ['channel', 'id'])]
class ChatMessage
{
    public const string CHANNEL_GLOBAL = 'GLOBAL';
    public const string CHANNEL_ZONE = 'ZONE';
    public const string CHANNEL_PARTY = 'PARTY';
    public const string CHANNEL_SYSTEM = 'SYSTEM';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $channel = self::CHANNEL_ZONE;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $zoneCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $partyId = null;

    #[ORM\Column(length: 40)]
    private string $senderName;

    #[ORM\Column(length: 16)]
    private string $senderClass = '';

    #[ORM\Column(length: 280)]
    private string $content;

    #[ORM\Column]
    private float $createdAt;

    public function __construct(
        string $channel,
        string $senderName,
        string $senderClass,
        string $content,
        ?string $zoneCode = null,
        ?int $partyId = null,
    ) {
        $this->channel = $channel;
        $this->senderName = $senderName;
        $this->senderClass = $senderClass;
        $this->content = $content;
        $this->zoneCode = $zoneCode;
        $this->partyId = $partyId;
        $this->createdAt = microtime(true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getZoneCode(): ?string
    {
        return $this->zoneCode;
    }

    public function getPartyId(): ?int
    {
        return $this->partyId;
    }

    public function getSenderName(): string
    {
        return $this->senderName;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'zoneCode' => $this->zoneCode,
            'senderName' => $this->senderName,
            'senderClass' => $this->senderClass,
            'content' => $this->content,
            'createdAt' => round($this->createdAt, 2),
        ];
    }
}
