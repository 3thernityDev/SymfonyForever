<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Deterministic shared Day/Night cycle and biome-specific dynamic weather for La Réunion.
 */
final class WorldAtmosphere
{
    public const float DAY_CYCLE_SECONDS = 720.0; // 12 real minutes = 24 in-game hours
    public const float WEATHER_WINDOW_SECONDS = 150.0;

    public const string WEATHER_CLEAR = 'CLEAR';
    public const string WEATHER_RAIN = 'RAIN';
    public const string WEATHER_STORM = 'STORM';
    public const string WEATHER_SANDSTORM = 'SANDSTORM';

    public const array WEATHER_LABELS = [
        self::WEATHER_CLEAR => 'Ciel clair',
        self::WEATHER_RAIN => 'Pluie tropicale',
        self::WEATHER_STORM => 'Orage tropical',
        self::WEATHER_SANDSTORM => 'Tempête de sable',
    ];

    /**
     * Computes synchronized day/night clock and deterministic zone weather for any Unix timestamp.
     *
     * @return array{
     *   timeOfDay: float,
     *   phase: string,
     *   isNight: bool,
     *   weather: string,
     *   weatherLabel: string,
     *   dayCycleSeconds: float
     * }
     */
    public static function compute(string $zoneCode, float $now): array
    {
        $rawHours = fmod(($now / self::DAY_CYCLE_SECONDS) * 24.0 + 9.0, 24.0);
        if ($rawHours < 0.0) {
            $rawHours += 24.0;
        }
        $timeOfDay = round($rawHours, 3);
        if ($timeOfDay >= 24.0) {
            $timeOfDay = 0.0;
        }

        $phase = match (true) {
            $timeOfDay >= 5.5 && $timeOfDay < 7.5 => 'DAWN',
            $timeOfDay >= 7.5 && $timeOfDay < 17.0 => 'DAY',
            $timeOfDay >= 17.0 && $timeOfDay < 19.5 => 'DUSK',
            default => 'NIGHT',
        };
        $isNight = $timeOfDay < 6.0 || $timeOfDay >= 19.25;

        $window = (int) floor($now / self::WEATHER_WINDOW_SECONDS);
        $roll = abs(crc32($zoneCode.':'.$window)) % 100;

        $weather = match ($zoneCode) {
            'fournaise' => match (true) {
                $roll < 45 => self::WEATHER_SANDSTORM,
                $roll < 65 => self::WEATHER_STORM,
                default => self::WEATHER_CLEAR,
            },
            'piton_neiges' => match (true) {
                $roll < 30 => self::WEATHER_SANDSTORM,
                $roll < 55 => self::WEATHER_STORM,
                $roll < 70 => self::WEATHER_RAIN,
                default => self::WEATHER_CLEAR,
            },
            'salazie', 'mafate', 'sud_sauvage' => match (true) {
                $roll < 35 => self::WEATHER_RAIN,
                $roll < 60 => self::WEATHER_STORM,
                default => self::WEATHER_CLEAR,
            },
            default => match (true) {
                $roll < 25 => self::WEATHER_RAIN,
                $roll < 45 => self::WEATHER_STORM,
                default => self::WEATHER_CLEAR,
            },
        };

        return [
            'timeOfDay' => $timeOfDay,
            'phase' => $phase,
            'isNight' => $isNight,
            'weather' => $weather,
            'weatherLabel' => self::WEATHER_LABELS[$weather] ?? 'Ciel clair',
            'dayCycleSeconds' => self::DAY_CYCLE_SECONDS,
        ];
    }
}
