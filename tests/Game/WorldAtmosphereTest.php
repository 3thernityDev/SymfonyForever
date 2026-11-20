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
            $atmo = WorldAtmosphere::computeGlobal($t);

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
        $a1 = WorldAtmosphere::computeGlobal(12345.0);
        $a2 = WorldAtmosphere::computeGlobal(12345.0 + $cycle);
        self::assertEqualsWithDelta($a1['timeOfDay'], $a2['timeOfDay'], 0.002);
        self::assertSame($a1['phase'], $a2['phase']);
        self::assertSame($a1['isNight'], $a2['isNight']);
    }

    public function testWeatherAndTimeAreCommonAcrossAllZones(): void
    {
        $zones = ['lagon_ouest', 'fournaise', 'salazie', 'piton_neiges', 'cilaos', 'mafate', 'sud_sauvage'];
        foreach ([50000.0, 50155.0, 50310.0, 50465.0] as $ts) {
            $global = WorldAtmosphere::computeGlobal($ts);
            foreach ($zones as $zone) {
                self::assertSame($global, WorldAtmosphere::compute($zone, $ts));
            }
        }

        $seenWeather = [];
        for ($w = 0; $w < 60; ++$w) {
            $t = 60000.0 + ($w * WorldAtmosphere::WEATHER_WINDOW_SECONDS) + 5.0;
            $seenWeather[WorldAtmosphere::computeGlobal($t)['weather']] = true;
        }

        self::assertArrayHasKey(WorldAtmosphere::WEATHER_CLEAR, $seenWeather);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_RAIN, $seenWeather);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_STORM, $seenWeather);
        self::assertArrayHasKey(WorldAtmosphere::WEATHER_SANDSTORM, $seenWeather);
    }

    public function testOverridesControlSharedWeatherTimeOffsetAndFreeze(): void
    {
        $baseNow = 80000.0;

        // 1. Weather override applies uniformly across every zone and ignores invalid weather codes
        $sand = WorldAtmosphere::compute('lagon_ouest', $baseNow, WorldAtmosphere::WEATHER_SANDSTORM);
        self::assertSame(WorldAtmosphere::WEATHER_SANDSTORM, $sand['weather']);
        self::assertTrue($sand['isWeatherOverridden']);
        self::assertSame(WorldAtmosphere::WEATHER_SANDSTORM, $sand['weatherOverride']);
        self::assertSame($sand, WorldAtmosphere::compute('fournaise', $baseNow, WorldAtmosphere::WEATHER_SANDSTORM));

        $invalid = WorldAtmosphere::computeGlobal($baseNow, 'TORNADO');
        self::assertFalse($invalid['isWeatherOverridden']);
        self::assertNull($invalid['weatherOverride']);

        // 2. Flowing time offset shifts the clock globally and keeps advancing with real time
        $natural = WorldAtmosphere::computeNaturalTimeOfDay($baseNow);
        $offset = 6.5;
        $shifted1 = WorldAtmosphere::computeGlobal($baseNow, null, $offset);
        $shifted2 = WorldAtmosphere::computeGlobal($baseNow + 60.0, null, $offset);
        self::assertEqualsWithDelta(WorldAtmosphere::normalizeHours($natural + $offset), $shifted1['timeOfDay'], 0.002);
        self::assertTrue($shifted1['isTimeOverridden']);
        self::assertFalse($shifted1['isTimeFrozen']);
        self::assertEqualsWithDelta(2.0, fmod($shifted2['timeOfDay'] - $shifted1['timeOfDay'] + 24.0, 24.0), 0.01);

        // 3. Frozen time locks timeOfDay & phase regardless of elapsed wall-clock seconds
        $frozenNight1 = WorldAtmosphere::computeGlobal($baseNow, WorldAtmosphere::WEATHER_STORM, 0.0, 23.0);
        $frozenNight2 = WorldAtmosphere::computeGlobal($baseNow + 300.0, WorldAtmosphere::WEATHER_STORM, 0.0, 23.0);
        self::assertSame(23.0, $frozenNight1['timeOfDay']);
        self::assertSame($frozenNight1['timeOfDay'], $frozenNight2['timeOfDay']);
        self::assertSame('NIGHT', $frozenNight1['phase']);
        self::assertTrue($frozenNight1['isNight']);
        self::assertTrue($frozenNight1['isTimeFrozen']);
        self::assertTrue($frozenNight1['isTimeOverridden']);

        $frozenDay = WorldAtmosphere::computeGlobal($baseNow, null, 0.0, 12.5);
        self::assertSame('DAY', $frozenDay['phase']);
        self::assertFalse($frozenDay['isNight']);
        self::assertTrue($frozenDay['isTimeFrozen']);
    }
}
