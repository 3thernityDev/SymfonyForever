<?php

declare(strict_types=1);

namespace App\Game;

enum CharacterClass: string
{
    case TANK = 'TANK';
    case DPS = 'DPS';
    case HEAL = 'HEAL';

    public function label(): string
    {
        return match ($this) {
            self::TANK => 'Gardien du Basalte',
            self::DPS => 'Lame de Lave',
            self::HEAL => 'Tisaneur des Cirques',
        };
    }

    public function roleLabel(): string
    {
        return match ($this) {
            self::TANK => 'Tank',
            self::DPS => 'DPS',
            self::HEAL => 'Heal',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TANK => 'Rempart inébranlable forgé dans la roche des cirques. Encaisse les coups, provoque les créatures et protège ses alliés.',
            self::DPS => 'Combattant incandescent maniant la fureur du Piton de la Fournaise. Inflige des dégâts dévastateurs, brûlures et exécutions.',
            self::HEAL => 'Guérisseur maîtrisant les plantes endémiques et la lumière du lagon. Maintient son groupe en vie tout en frappant à distance.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TANK => '#38bdf8',
            self::DPS => '#f97316',
            self::HEAL => '#10b981',
        };
    }

    public function threatMultiplier(): float
    {
        return match ($this) {
            self::TANK => 2.2,
            self::DPS => 1.0,
            self::HEAL => 0.7,
        };
    }

    public function resourceType(): string
    {
        return match ($this) {
            self::TANK => 'RAGE',
            self::DPS => 'ENERGY',
            self::HEAL => 'MANA',
        };
    }

    public function resourceLabel(): string
    {
        return match ($this) {
            self::TANK => 'Rage',
            self::DPS => 'Énergie',
            self::HEAL => 'Mana',
        };
    }

    public function resourceShort(): string
    {
        return match ($this) {
            self::TANK => 'RG',
            self::DPS => 'ÉN',
            self::HEAL => 'PM',
        };
    }

    public function resourceColor(): string
    {
        return match ($this) {
            self::TANK => '#ef4444',
            self::DPS => '#facc15',
            self::HEAL => '#38bdf8',
        };
    }

    public function insufficientResourceError(): string
    {
        return match ($this) {
            self::TANK => 'Rage insuffisante.',
            self::DPS => 'Énergie insuffisante.',
            self::HEAL => 'Mana insuffisant.',
        };
    }

    /**
     * @return array{vitality: int, strength: int, intelligence: int, defense: int, spirit: int}
     */
    public function baseStats(): array
    {
        return match ($this) {
            self::TANK => ['vitality' => 16, 'strength' => 10, 'intelligence' => 6, 'defense' => 16, 'spirit' => 8],
            self::DPS => ['vitality' => 11, 'strength' => 15, 'intelligence' => 12, 'defense' => 9, 'spirit' => 9],
            self::HEAL => ['vitality' => 11, 'strength' => 6, 'intelligence' => 16, 'defense' => 9, 'spirit' => 14],
        };
    }

    /**
     * @return array{vitality: int, strength: int, intelligence: int, defense: int, spirit: int}
     */
    public function levelGrowth(): array
    {
        return match ($this) {
            self::TANK => ['vitality' => 4, 'strength' => 2, 'intelligence' => 0, 'defense' => 3, 'spirit' => 1],
            self::DPS => ['vitality' => 2, 'strength' => 4, 'intelligence' => 3, 'defense' => 1, 'spirit' => 0],
            self::HEAL => ['vitality' => 2, 'strength' => 0, 'intelligence' => 4, 'defense' => 1, 'spirit' => 3],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->value,
            'label' => $this->label(),
            'role' => $this->roleLabel(),
            'description' => $this->description(),
            'color' => $this->color(),
            'threatMultiplier' => $this->threatMultiplier(),
            'resourceType' => $this->resourceType(),
            'resourceLabel' => $this->resourceLabel(),
            'resourceShort' => $this->resourceShort(),
            'resourceColor' => $this->resourceColor(),
            'baseStats' => $this->baseStats(),
            'levelGrowth' => $this->levelGrowth(),
        ];
    }
}
