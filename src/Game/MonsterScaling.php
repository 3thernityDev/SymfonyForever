<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Deterministic level-based stat scaling for Réunion monsters (Levels 1–60 + Level 65 World Boss).
 */
final class MonsterScaling
{
    public const string ARCHETYPE_BALANCED = 'balanced';
    public const string ARCHETYPE_AGILE = 'agile';
    public const string ARCHETYPE_BRUISER = 'bruiser';
    public const string ARCHETYPE_ARMORED = 'armored';
    public const string ARCHETYPE_ELITE = 'elite';
    public const string ARCHETYPE_WORLD_BOSS = 'world_boss';

    /**
     * Computes scaled monster stats strictly monotonically with level and archetype.
     *
     * @return array{maxHp: int, attack: int, defense: int, xpReward: int}
     */
    public static function computeStats(int $level, string $archetype = self::ARCHETYPE_BALANCED): array
    {
        $lvl = max(1, $level);
        $d = $lvl - 1;

        $baseHp = 200.0 + 82.0 * $d + 2.95 * $d * $d;
        $baseAtk = 10.0 + 6.4 * $d + 0.070 * $d * $d;
        $baseDef = 5.0 + 2.6 * $d + 0.019 * $d * $d;
        $baseXp = 42.0 * ($lvl ** 1.18);

        [$hpMult, $atkMult, $defMult, $xpMult] = match ($archetype) {
            self::ARCHETYPE_AGILE => [0.88, 1.10, 0.85, 1.00],
            self::ARCHETYPE_BRUISER => [1.08, 1.08, 0.95, 1.05],
            self::ARCHETYPE_ARMORED => [1.22, 0.94, 1.25, 1.10],
            self::ARCHETYPE_ELITE => [3.20, 1.45, 1.30, 2.60],
            self::ARCHETYPE_WORLD_BOSS => [25.00, 2.60, 1.30, 22.00],
            default => [1.00, 1.00, 1.00, 1.00],
        };

        $maxHp = $archetype === self::ARCHETYPE_WORLD_BOSS
            ? (int) (round(($baseHp * $hpMult) / 10000.0) * 10000)
            : max(50, (int) round($baseHp * $hpMult));

        return [
            'maxHp' => $maxHp,
            'attack' => max(1, (int) round($baseAtk * $atkMult)),
            'defense' => max(1, (int) round($baseDef * $defMult)),
            'xpReward' => max(10, (int) round($baseXp * $xpMult)),
        ];
    }
}
