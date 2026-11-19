<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;

final class StatCalculator
{
    /**
     * Computes derived combat stats for a character.
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
     *   activeDamageReductionBuff: float
     * }
     */
    public function compute(Character $character, ?float $now = null): array
    {
        $now ??= microtime(true);

        $vit = $character->getVitality();
        $str = $character->getStrength();
        $int = $character->getIntelligence();
        $def = $character->getDefense();
        $spi = $character->getSpirit();
        $lvl = $character->getLevel();

        $maxHp = 80 + $vit * 12 + ($lvl - 1) * 18;
        $maxMp = 45 + $spi * 10 + ($lvl - 1) * 8;
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
            'mpRegenPerSec' => round(3.5 + $spi * 0.85, 2),
            'activeDamageReductionBuff' => $buffReduction,
        ];
    }

    public function xpForNextLevel(int $level): int
    {
        return (int) floor(100.0 * ($level ** 1.6));
    }
}
