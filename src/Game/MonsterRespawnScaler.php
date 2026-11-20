<?php

declare(strict_types=1);

namespace App\Game;

use App\Entity\MonsterTemplate;

/**
 * Dynamic monster respawn acceleration based on active player population in the zone.
 */
final class MonsterRespawnScaler
{
    public const float SPEED_BONUS_PER_EXTRA_PLAYER = 0.25;
    public const float MAX_SPEED_MULTIPLIER = 4.0;
    public const float MIN_REGULAR_RESPAWN_SECONDS = 4.0;
    public const float MIN_BOSS_RESPAWN_SECONDS = 12.0;

    public const float WORLD_BOSS_SPEED_BONUS_PER_EXTRA_PLAYER = 0.10;
    public const float WORLD_BOSS_MAX_SPEED_MULTIPLIER = 3.0;
    public const float MIN_WORLD_BOSS_RESPAWN_SECONDS = 45.0;

    public static function computeSpeedMultiplier(int $zonePlayerCount, bool $isWorldBoss = false): float
    {
        $extraPlayers = max(0, $zonePlayerCount - 1);

        if ($isWorldBoss) {
            return round(
                min(self::WORLD_BOSS_MAX_SPEED_MULTIPLIER, 1.0 + ($extraPlayers * self::WORLD_BOSS_SPEED_BONUS_PER_EXTRA_PLAYER)),
                3,
            );
        }

        return round(
            min(self::MAX_SPEED_MULTIPLIER, 1.0 + ($extraPlayers * self::SPEED_BONUS_PER_EXTRA_PLAYER)),
            3,
        );
    }

    public static function computeRespawnSeconds(MonsterTemplate $template, int $zonePlayerCount): float
    {
        $base = max(1.0, (float) $template->getRespawnSeconds());
        $multiplier = self::computeSpeedMultiplier($zonePlayerCount, $template->isWorldBoss());

        if ($template->isWorldBoss()) {
            $floor = min($base, self::MIN_WORLD_BOSS_RESPAWN_SECONDS);
        } elseif ($template->isBoss()) {
            $floor = min($base, self::MIN_BOSS_RESPAWN_SECONDS);
        } else {
            $floor = min($base, self::MIN_REGULAR_RESPAWN_SECONDS);
        }

        return round(max($floor, $base / $multiplier), 2);
    }
}
