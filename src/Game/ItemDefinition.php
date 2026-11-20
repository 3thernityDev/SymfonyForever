<?php

declare(strict_types=1);

namespace App\Game;

final readonly class ItemDefinition
{
    public const string TYPE_EQUIPMENT = 'EQUIPMENT';
    public const string TYPE_CONSUMABLE = 'CONSUMABLE';
    public const string TYPE_JUNK = 'JUNK';

    public const string SLOT_WEAPON = 'WEAPON';
    public const string SLOT_ARMOR = 'ARMOR';
    public const string SLOT_HELM = 'HELM';
    public const string SLOT_ACCESSORY = 'ACCESSORY';
    public const string SLOT_MOUNT = 'MOUNT';

    public const string RARITY_COMMON = 'COMMON';
    public const string RARITY_UNCOMMON = 'UNCOMMON';
    public const string RARITY_RARE = 'RARE';
    public const string RARITY_EPIC = 'EPIC';
    public const string RARITY_LEGENDARY = 'LEGENDARY';

    /** @var list<string> */
    public const array EQUIPMENT_SLOTS = [
        self::SLOT_WEAPON,
        self::SLOT_ARMOR,
        self::SLOT_HELM,
        self::SLOT_ACCESSORY,
        self::SLOT_MOUNT,
    ];

    /**
     * @param array{vitality?: int, strength?: int, intelligence?: int, defense?: int, spirit?: int} $bonuses
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public string $type,
        public ?string $slot,
        public string $rarity,
        public int $minLevel,
        public string $icon,
        public int $sellPrice,
        public int $buyPrice,
        public bool $stackable,
        public array $bonuses = [],
        public int $restoreHp = 0,
        public int $restoreMp = 0,
        public float $mountSpeedBonus = 0.0,
        public bool $mountCanFly = false,
    ) {
    }

    public function isMount(): bool
    {
        return $this->slot === self::SLOT_MOUNT && $this->mountSpeedBonus > 0.0;
    }

    public static function rarityRank(string $rarity): int
    {
        return match ($rarity) {
            self::RARITY_LEGENDARY => 5,
            self::RARITY_EPIC => 4,
            self::RARITY_RARE => 3,
            self::RARITY_UNCOMMON => 2,
            default => 1,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'slot' => $this->slot,
            'rarity' => $this->rarity,
            'minLevel' => $this->minLevel,
            'icon' => $this->icon,
            'sellPrice' => $this->sellPrice,
            'buyPrice' => $this->buyPrice,
            'stackable' => $this->stackable,
            'bonuses' => $this->bonuses,
            'restoreHp' => $this->restoreHp,
            'restoreMp' => $this->restoreMp,
            'mountSpeedBonus' => $this->mountSpeedBonus,
            'mountCanFly' => $this->mountCanFly,
        ];
    }
}
