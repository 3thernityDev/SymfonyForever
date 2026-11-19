<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\ItemDefinition;
use App\Repository\WorldLootDropRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorldLootDropRepository::class)]
#[ORM\Table(name: 'world_loot_drop')]
#[ORM\Index(name: 'idx_loot_zone_exp', columns: ['zone_code', 'expires_at'])]
class WorldLootDrop
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $zoneCode;

    #[ORM\Column(length: 80)]
    private string $sourceName = '';

    #[ORM\Column]
    private float $posX;

    #[ORM\Column]
    private float $posY;

    #[ORM\Column]
    private float $posZ;

    #[ORM\Column]
    private int $gold = 0;

    /** @var list<array{itemCode: string, quantity: int}> */
    #[ORM\Column(type: 'json')]
    private array $items = [];

    #[ORM\Column(length: 16)]
    private string $highestRarity = ItemDefinition::RARITY_COMMON;

    #[ORM\Column(nullable: true)]
    private ?int $ownerCharacterId = null;

    #[ORM\Column(nullable: true)]
    private ?int $ownerPartyId = null;

    #[ORM\Column]
    private float $freeForAllAt;

    #[ORM\Column]
    private float $expiresAt;

    /**
     * @param list<array{itemCode: string, quantity: int}> $items
     */
    public function __construct(
        string $zoneCode,
        string $sourceName,
        float $x,
        float $y,
        float $z,
        int $gold,
        array $items,
        string $highestRarity,
        ?int $ownerCharacterId,
        ?int $ownerPartyId,
        float $now,
    ) {
        $this->zoneCode = $zoneCode;
        $this->sourceName = $sourceName;
        $this->posX = $x;
        $this->posY = $y;
        $this->posZ = $z;
        $this->gold = max(0, $gold);
        $this->items = $items;
        $this->highestRarity = $highestRarity;
        $this->ownerCharacterId = $ownerCharacterId;
        $this->ownerPartyId = $ownerPartyId;
        $this->freeForAllAt = $now + 60.0;
        $this->expiresAt = $now + 150.0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getZoneCode(): string
    {
        return $this->zoneCode;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function getPosX(): float
    {
        return $this->posX;
    }

    public function getPosY(): float
    {
        return $this->posY;
    }

    public function getPosZ(): float
    {
        return $this->posZ;
    }

    public function getGold(): int
    {
        return $this->gold;
    }

    public function setGold(int $gold): self
    {
        $this->gold = max(0, $gold);

        return $this;
    }

    /**
     * @return list<array{itemCode: string, quantity: int}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param list<array{itemCode: string, quantity: int}> $items
     */
    public function setItems(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    public function getHighestRarity(): string
    {
        return $this->highestRarity;
    }

    public function canBeLootedBy(Character $character, float $now): bool
    {
        if ($now >= $this->freeForAllAt) {
            return true;
        }
        if ($this->ownerCharacterId === null && $this->ownerPartyId === null) {
            return true;
        }
        if ($this->ownerCharacterId !== null && $this->ownerCharacterId === $character->getId()) {
            return true;
        }

        return $this->ownerPartyId !== null && $character->getParty()?->getId() === $this->ownerPartyId;
    }

    public function isEmpty(): bool
    {
        return $this->gold <= 0 && $this->items === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Character $viewer, float $now): array
    {
        return [
            'id' => $this->id,
            'zoneCode' => $this->zoneCode,
            'sourceName' => $this->sourceName,
            'x' => round($this->posX, 2),
            'y' => round($this->posY, 2),
            'z' => round($this->posZ, 2),
            'gold' => $this->gold,
            'items' => $this->items,
            'highestRarity' => $this->highestRarity,
            'canLoot' => $this->canBeLootedBy($viewer, $now),
        ];
    }
}
