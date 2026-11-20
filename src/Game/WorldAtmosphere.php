<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Shared global Day/Night cycle and common island-wide dynamic weather for La Réunion.
 */
final class WorldAtmosphere
{
    public const float DAY_CYCLE_SECONDS = 720.0; // 12 real minutes = 24 in-game hours
    public const float WEATHER_WINDOW_SECONDS = 150.0;

    public const string WEATHER_CLEAR = 'CLEAR';
    public const string WEATHER_RAIN = 'RAIN';
    public const string WEATHER_STORM = 'STORM';
    public const string WEATHER_SANDSTORM = 'SANDSTORM';

    public const array VALID_WEATHERS = [
        self::WEATHER_CLEAR,
        self::WEATHER_RAIN,
        self::WEATHER_STORM,
        self::WEATHER_SANDSTORM,
    ];

    public const array WEATHER_LABELS = [
        self::WEATHER_CLEAR => 'Ciel clair',
        self::WEATHER_RAIN => 'Pluie tropicale',
        self::WEATHER_STORM => 'Orage tropical',
        self::WEATHER_SANDSTORM => 'Tempête de sable',
    ];

    public static function isValidWeather(?string $weather): bool
    {
        return $weather !== null && in_array($weather, self::VALID_WEATHERS, true);
    }

    public static function normalizeHours(float $hours): float
    {
        $wrapped = fmod($hours, 24.0);
        if ($wrapped < 0.0) {
            $wrapped += 24.0;
        }
        $normalized = round($wrapped, 3);
        if ($normalized >= 24.0 || $normalized < 0.0) {
            return 0.0;
        }

        return $normalized;
    }

    public static function computeNaturalTimeOfDay(float $now): float
    {
        return self::normalizeHours(($now / self::DAY_CYCLE_SECONDS) * 24.0 + 9.0);
    }

    /**
     * Computes the global island-wide day/night clock and shared weather for any Unix timestamp.
     *
     * @return array{
     *   timeOfDay: float,
     *   phase: string,
     *   isNight: bool,
     *   weather: string,
     *   weatherLabel: string,
     *   dayCycleSeconds: float,
     *   isTimeFrozen: bool,
     *   isTimeOverridden: bool,
     *   isWeatherOverridden: bool,
     *   weatherOverride: string|null
     * }
     */
    public static function computeGlobal(
        float $now,
        ?string $weatherOverride = null,
        float $timeOffsetHours = 0.0,
        ?float $fixedTimeOfDay = null,
    ): array {
        $isTimeFrozen = $fixedTimeOfDay !== null;
        if ($fixedTimeOfDay !== null) {
            $timeOfDay = self::normalizeHours($fixedTimeOfDay);
        } else {
            $timeOfDay = self::normalizeHours(self::computeNaturalTimeOfDay($now) + $timeOffsetHours);
        }

        $phase = match (true) {
            $timeOfDay >= 5.5 && $timeOfDay < 7.5 => 'DAWN',
            $timeOfDay >= 7.5 && $timeOfDay < 17.0 => 'DAY',
            $timeOfDay >= 17.0 && $timeOfDay < 19.5 => 'DUSK',
            default => 'NIGHT',
        };
        $isNight = $timeOfDay < 6.0 || $timeOfDay >= 19.25;

        $window = (int) floor($now / self::WEATHER_WINDOW_SECONDS);
        $roll = abs(crc32('reunion_global:'.$window)) % 100;
        $autoWeather = match (true) {
            $roll < 46 => self::WEATHER_CLEAR,
            $roll < 70 => self::WEATHER_RAIN,
            $roll < 88 => self::WEATHER_STORM,
            default => self::WEATHER_SANDSTORM,
        };

        $validOverride = self::isValidWeather($weatherOverride) ? $weatherOverride : null;
        $weather = $validOverride ?? $autoWeather;

        return [
            'timeOfDay' => $timeOfDay,
            'phase' => $phase,
            'isNight' => $isNight,
            'weather' => $weather,
            'weatherLabel' => self::WEATHER_LABELS[$weather] ?? 'Ciel clair',
            'dayCycleSeconds' => self::DAY_CYCLE_SECONDS,
            'isTimeFrozen' => $isTimeFrozen,
            'isTimeOverridden' => $fixedTimeOfDay !== null || abs($timeOffsetHours) > 0.0001,
            'isWeatherOverridden' => $validOverride !== null,
            'weatherOverride' => $validOverride,
        ];
    }

    /**
     * Backward-compatible wrapper delegating to computeGlobal so all zones share the exact same time and weather.
     *
     * @return array{
     *   timeOfDay: float,
     *   phase: string,
     *   isNight: bool,
     *   weather: string,
     *   weatherLabel: string,
     *   dayCycleSeconds: float,
     *   isTimeFrozen: bool,
     *   isTimeOverridden: bool,
     *   isWeatherOverridden: bool,
     *   weatherOverride: string|null
     * }
     */
    public static function compute(
        string $zoneCode,
        float $now,
        ?string $weatherOverride = null,
        float $timeOffsetHours = 0.0,
        ?float $fixedTimeOfDay = null,
    ): array {
        unset($zoneCode);

        return self::computeGlobal($now, $weatherOverride, $timeOffsetHours, $fixedTimeOfDay);
    }
}
