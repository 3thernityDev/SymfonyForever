<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\WorldAtmosphere;
use PHPUnit\Framework\TestCase;

final class WorldAtmosphereTest extends TestCase
{
    public function testDayNightCycleWrapsSmoothlyWithExpectedPhases(): void
    {
        $cycle = WorldAtmosphere::DAY_CYCLE_SECONDS;
        self::assertSame(720.0, $cycle);

        $seenPhases = [];
        for ($step = 0; $step < 24; ++$step) {
            $t = 10000.0 + ($step / 24.0) * $cycle;
            $atmo = WorldAtmosphere::compute('lagon_ouest', $t);

            self::assertGreaterThanOrEqual(0.0, $atmo['timeOfDay']);
            self::assertLessThan(24.0, $atmo['timeOfDay']);
            self::assertContains($atmo['phase'], ['DAWN', 'DAY', 'DUSK', 'NIGHT']);
            $seenPhases[$atmo['phase']] = true;
        }

        self::assertArrayHasKey('DAWN', $seenPhases);
        self::assertArrayHasKey('DAY', $seenPhases);
        self::assertArrayHasKey('DUSK', $seenPhases);
        self::assertArrayHasKey('NIGHT', $seenPhases);

        // Full cycle wrap produces the exact same clock time
        $a1 = WorldAtmosphere::compute('lagon_ouest', 12345.0);
        $a2 = WorldAtmosphere::compute('lagon_ouest', 12345.0 + $cycle);
        self::assertEqualsWithDelta($a1['timeOfDay'], $a2['timeOfDay'], 0.002);
        self::assertSame($a1['phase'], $a2['phase']);
        self::assertSame($a1['isNight'], $a2['isNight']);
    }

    public function testWeatherIsDeterministicAndBiomeSpecific(): void
    {
        $w1 = WorldAtmosphere::compute('fournaise', 50000.0);
        $w2 = WorldAtmosphere::compute('fournaise', 50000.0);
        self::assertSame($w1, $w2);

        $fournaiseSeen = [];
        $pitonSeen = [];
        $salazieSeen = [];

        for ($w = 0; $w < 60; ++$w) {
            $t = 60000.0 + ($w * WorldAtmosphere::WEATHER_WINDOW_SECONDS) + 5.0;
            $fournaiseSeen[WorldAtmosphere::compute('fournaise', $t)['weather']] = true;
            $pitonSeen[WorldAtmosphere::compute('piton_neiges', $t)['weather']] = true;
            $salazieSeen[WorldAtmosphere::compute('salazie', $t)['weather']] = true;
        }

        self::assertArrayHasKey(WorldAtmosphere::WEATHER_SANDSTORM, $fournaiseSeen);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_STORM, $fournaiseSeen);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_CLEAR, $fournaiseSeen);

        self::assertArrayHasKey(WorldAtmosphere::WEATHER_SANDSTORM, $pitonSeen);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_RAIN, $pitonSeen);

        self::assertArrayHasKey(WorldAtmosphere::WEATHER_RAIN, $salazieSeen);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_STORM, $salazieSeen);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_CLEAR, $salazieSeen);
        self::assertArrayNotHasKey(WorldAtmosphere::WEATHER_SANDSTORM, $salazieSeen);
    }
}
