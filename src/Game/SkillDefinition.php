<?php

declare(strict_types=1);

namespace App\Game;

final readonly class SkillDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public CharacterClass $characterClass,
        public int $slot,
        public int $unlockLevel,
        public string $targetType, // ENEMY | ALLY | SELF | SELF_AOE | ENEMY_AOE | PARTY_AOE
        public int $manaCost,
        public float $cooldown,
        public float $range,
        public float $aoeRadius,
        public float $basePower,
        public float $powerMultiplier,
        public string $scalingStat, // STR | INT | STR_DEF
        public float $threatMultiplier,
        public float $bonusCritChance,
        public ?string $effectType, // TAUNT | DAMAGE_REDUCTION | DOT | HOT | EXECUTE
        public float $effectValue,
        public float $effectDuration,
        public float $effectTickInterval,
        public string $icon,
        public string $vfxColor,
        public string $vfxStyle,
    ) {
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
            'classCode' => $this->characterClass->value,
            'slot' => $this->slot,
            'unlockLevel' => $this->unlockLevel,
            'targetType' => $this->targetType,
            'manaCost' => $this->manaCost,
            'cooldown' => $this->cooldown,
            'range' => $this->range,
            'aoeRadius' => $this->aoeRadius,
            'basePower' => $this->basePower,
            'powerMultiplier' => $this->powerMultiplier,
            'scalingStat' => $this->scalingStat,
            'threatMultiplier' => $this->threatMultiplier,
            'bonusCritChance' => $this->bonusCritChance,
            'effectType' => $this->effectType,
            'effectValue' => $this->effectValue,
            'effectDuration' => $this->effectDuration,
            'icon' => $this->icon,
            'vfxColor' => $this->vfxColor,
            'vfxStyle' => $this->vfxStyle,
        ];
    }
}
