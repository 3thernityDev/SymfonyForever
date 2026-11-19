<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Game\CharacterClass;
use App\Game\ItemRegistry;

final class StatCalculator
{
    private readonly ItemRegistry $itemRegistry;

    public function __construct(?ItemRegistry $itemRegistry = null)
    {
        $this->itemRegistry = $itemRegistry ?? new ItemRegistry();
    }

    /**
     * Returns total stat bonuses from currently equipped items.
     *
     * @return array{vitality: int, strength: int, intelligence: int, defense: int, spirit: int}
     */
    public function equipmentBonuses(Character $character): array
    {
        $bonuses = ['vitality' => 0, 'strength' => 0, 'intelligence' => 0, 'defense' => 0, 'spirit' => 0];

        foreach ($character->getItems() as $item) {
            if (!$item->isEquipped()) {
                continue;
            }
            $def = $this->itemRegistry->get($item->getItemCode());
            if ($def === null) {
                continue;
            }
            foreach ($bonuses as $key => $val) {
                $bonuses[$key] = $val + (int) ($def->bonuses[$key] ?? 0);
            }
        }

        return $bonuses;
    }

    /**
     * Computes derived combat stats for a character (base attributes + equipped gear).
     *
     * @return array{
     *   maxHp: int,
     *   maxMp: int,
     *   attackPower: float,
     *   spellPower: float,
     *   damageReduction: float,
     *   critChance: float,
     *   hpRegenPerSec: float,
     *   mpRegenPerSec: float,
     *   activeDamageReductionBuff: float,
     *   equipmentBonuses: array{vitality: int, strength: int, intelligence: int, defense: int, spirit: int}
     * }
     */
    public function compute(Character $character, ?float $now = null): array
    {
        $now ??= microtime(true);

        $eq = $this->equipmentBonuses($character);
        $vit = $character->getVitality() + $eq['vitality'];
        $str = $character->getStrength() + $eq['strength'];
        $int = $character->getIntelligence() + $eq['intelligence'];
        $def = $character->getDefense() + $eq['defense'];
        $spi = $character->getSpirit() + $eq['spirit'];
        $lvl = $character->getLevel();

        $maxHp = 80 + $vit * 12 + ($lvl - 1) * 18;
        $cls = $character->getCharacterClass();
        [$maxMp, $mpRegenPerSec] = match ($cls) {
            CharacterClass::TANK => [100, 4.0],
            CharacterClass::DPS => [100, 10.0],
            CharacterClass::HEAL => [45 + $spi * 10 + ($lvl - 1) * 8, round(3.5 + $spi * 0.85, 2)],
        };
        $attackPower = $str * 2.5 + $lvl * 3.0;
        $spellPower = $int * 2.5 + $lvl * 3.0;
        $baseReduction = $def / ($def + 95.0);
        $critChance = min(0.60, 0.05 + $int * 0.003 + $str * 0.002);

        $buffReduction = 0.0;
        foreach ($character->getActiveEffects() as $effect) {
            if ($effect['type'] === 'DAMAGE_REDUCTION' && $effect['expiresAt'] > $now) {
                $buffReduction = max($buffReduction, (float) $effect['value']);
            }
        }

        // Combined multiplicative mitigation (base armor + active Tank shield buff)
        $totalReduction = 1.0 - (1.0 - $baseReduction) * (1.0 - $buffReduction);

        return [
            'maxHp' => $maxHp,
            'maxMp' => $maxMp,
            'attackPower' => round($attackPower, 2),
            'spellPower' => round($spellPower, 2),
            'damageReduction' => round(min(0.80, $totalReduction), 4),
            'critChance' => round($critChance, 4),
            'hpRegenPerSec' => round(3.0 + $vit * 0.45, 2),
            'mpRegenPerSec' => $mpRegenPerSec,
            'activeDamageReductionBuff' => $buffReduction,
            'equipmentBonuses' => $eq,
        ];
    }

    public function xpForNextLevel(int $level): int
    {
        return (int) floor(100.0 * ($level ** 1.6));
    }
}
