<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CombatEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CombatEventRepository::class)]
#[ORM\Table(name: 'combat_event')]
#[ORM\Index(name: 'idx_event_zone_id', columns: ['zone_code', 'id'])]
#[ORM\Index(name: 'idx_event_created', columns: ['created_at'])]
class CombatEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $zoneCode;

    #[ORM\Column(length: 24)]
    private string $eventType; // DAMAGE | HEAL | TAUNT | BUFF | DOT | HOT | KILL | LEVEL_UP | DEATH

    #[ORM\Column(length: 16)]
    private string $sourceType; // PLAYER | MONSTER

    #[ORM\Column]
    private int $sourceId;

    #[ORM\Column(length: 48)]
    private string $sourceName = '';

    #[ORM\Column(length: 16)]
    private string $targetType; // PLAYER | MONSTER

    #[ORM\Column]
    private int $targetId;

    #[ORM\Column(length: 48)]
    private string $targetName = '';

    #[ORM\Column(length: 40)]
    private string $skillCode = '';

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column]
    private bool $isCrit = false;

    #[ORM\Column]
    private float $posX = 0.0;

    #[ORM\Column]
    private float $posY = 0.0;

    #[ORM\Column]
    private float $posZ = 0.0;

    #[ORM\Column]
    private float $createdAt;

    public function __construct(
        string $zoneCode,
        string $eventType,
        string $sourceType,
        int $sourceId,
        string $sourceName,
        string $targetType,
        int $targetId,
        string $targetName,
        string $skillCode,
        int $amount,
        bool $isCrit,
        float $posX,
        float $posY,
        float $posZ,
        ?float $createdAt = null,
    ) {
        $this->zoneCode = $zoneCode;
        $this->eventType = $eventType;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->sourceName = $sourceName;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->targetName = $targetName;
        $this->skillCode = $skillCode;
        $this->amount = $amount;
        $this->isCrit = $isCrit;
        $this->posX = $posX;
        $this->posY = $posY;
        $this->posZ = $posZ;
        $this->createdAt = $createdAt ?? microtime(true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'zoneCode' => $this->zoneCode,
            'eventType' => $this->eventType,
            'sourceType' => $this->sourceType,
            'sourceId' => $this->sourceId,
            'sourceName' => $this->sourceName,
            'targetType' => $this->targetType,
            'targetId' => $this->targetId,
            'targetName' => $this->targetName,
            'skillCode' => $this->skillCode,
            'amount' => $this->amount,
            'isCrit' => $this->isCrit,
            'x' => round($this->posX, 2),
            'y' => round($this->posY, 2),
            'z' => round($this->posZ, 2),
            'createdAt' => round($this->createdAt, 3),
        ];
    }
}
