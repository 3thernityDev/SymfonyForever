<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\WorldCatalog;
use App\Service\Game\ReunionTerrainService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReunionTerrainServiceTest extends KernelTestCase
{
    private ReunionTerrainService $terrain;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->terrain = self::getContainer()->get(ReunionTerrainService::class);
    }

    public function testOceanOutsideIslandIsUnwalkable(): void
    {
        self::assertFalse($this->terrain->isWalkable(-3450.0, -3450.0));
        self::assertLessThan(WorldCatalog::SEA_LEVEL, $this->terrain->getHeight(-3450.0, -3450.0));
        self::assertSame(WorldCatalog::BIOME_OCEAN, $this->terrain->getBiome(-3450.0, -3450.0));
    }

    public function testSaintGillesHubIsWalkableAndInLagonOuest(): void
    {
        $hub = WorldCatalog::zones()['lagon_ouest'];
        self::assertTrue($this->terrain->isWalkable($hub['respawnX'], $hub['respawnZ']));
        self::assertSame('lagon_ouest', $this->terrain->getZoneCode($hub['respawnX'], $hub['respawnZ']));
        self::assertGreaterThan(WorldCatalog::SEA_LEVEL, $this->terrain->getHeight($hub['respawnX'], $hub['respawnZ']));
    }

    public function testPitonDesNeigesIsHighestMassif(): void
    {
        $hub = WorldCatalog::zones()['lagon_ouest'];
        $px = WorldCatalog::scaleCoord(-75.0);
        $pz = WorldCatalog::scaleCoord(-65.0);
        $peakY = $this->terrain->getHeight($px, $pz);
        $hubY = $this->terrain->getHeight($hub['respawnX'], $hub['respawnZ']);

        self::assertGreaterThan(240.0, $peakY);
        self::assertGreaterThan($hubY + 200.0, $peakY);
        self::assertSame('piton_neiges', $this->terrain->getZoneCode($px, $pz));
    }

    public function testFournaiseCraterHasVolcanicBiomeAndZone(): void
    {
        $cx = WorldCatalog::scaleCoord(440.0);
        $cz = WorldCatalog::scaleCoord(275.0);
        self::assertSame('fournaise', $this->terrain->getZoneCode($cx, $cz));
        self::assertSame(WorldCatalog::BIOME_VOLCANO_LAVA, $this->terrain->getBiome($cx, $cz));
    }

    public function testAllSevenZonesAreRepresentedOnIsland(): void
    {
        $zones = WorldCatalog::zones();
        foreach (['salazie', 'mafate', 'cilaos', 'sud_sauvage'] as $zCode) {
            self::assertSame($zCode, $this->terrain->getZoneCode($zones[$zCode]['centerX'], $zones[$zCode]['centerZ']));
        }
    }

    public function testWorldRequiresAtLeastTenMinutesToCrossOnFoot(): void
    {
        self::assertGreaterThanOrEqual(7000.0, WorldCatalog::WORLD_SIZE);

        $hub = WorldCatalog::zones()['lagon_ouest'];
        $dolomieuX = WorldCatalog::scaleCoord(440.0);
        $dolomieuZ = WorldCatalog::scaleCoord(275.0);
        $hubToBossDist = hypot($dolomieuX - $hub['respawnX'], $dolomieuZ - $hub['respawnZ']);
        $hubToBossSeconds = $hubToBossDist / WorldCatalog::PLAYER_WALK_SPEED;

        self::assertGreaterThanOrEqual(
            600.0,
            $hubToBossSeconds,
            sprintf('La traversée Saint-Gilles -> Dolomieu doit prendre >= 600s (10 min), mesuré: %.1fs', $hubToBossSeconds),
        );

        // Scan full walkable island span West -> East across midband Z
        $minWalkX = INF;
        $maxWalkX = -INF;
        for ($z = -500.0; $z <= 500.0; $z += 125.0) {
            for ($x = -WorldCatalog::HALF_WORLD + 60.0; $x <= WorldCatalog::HALF_WORLD - 60.0; $x += 40.0) {
                if ($this->terrain->isWalkable($x, $z)) {
                    $minWalkX = min($minWalkX, $x);
                    $maxWalkX = max($maxWalkX, $x);
                }
            }
        }

        $coastToCoastSeconds = ($maxWalkX - $minWalkX) / WorldCatalog::PLAYER_WALK_SPEED;
        self::assertGreaterThanOrEqual(
            600.0,
            $coastToCoastSeconds,
            sprintf('La traversée d\'un bout à l\'autre de l\'île doit prendre >= 10 min à pied (mesuré: %.1fs).', $coastToCoastSeconds),
        );
    }

    public function testAllLevelingZoneTrailsAreDefinedAndWalkable(): void
    {
        $trails = WorldCatalog::trails();
        self::assertCount(7, $trails);

        foreach ($trails as $trailIdx => $trail) {
            self::assertGreaterThanOrEqual(4, count($trail));
            for ($i = 0; $i < count($trail); ++$i) {
                $pt = $trail[$i];
                self::assertLessThan(WorldCatalog::HALF_WORLD, abs($pt['x']));
                self::assertLessThan(WorldCatalog::HALF_WORLD, abs($pt['z']));
                self::assertGreaterThan(
                    WorldCatalog::SEA_LEVEL,
                    $this->terrain->getHeight($pt['x'], $pt['z']),
                    sprintf('Point #%d du sentier #%d doit être au-dessus du niveau de la mer.', $i, $trailIdx),
                );

                if ($i < count($trail) - 1) {
                    $next = $trail[$i + 1];
                    $midX = ($pt['x'] + $next['x']) * 0.5;
                    $midZ = ($pt['z'] + $next['z']) * 0.5;
                    self::assertGreaterThan(
                        WorldCatalog::SEA_LEVEL,
                        $this->terrain->getHeight($midX, $midZ),
                        sprintf('Milieu du segment #%d du sentier #%d doit être au-dessus de la mer.', $i, $trailIdx),
                    );
                }
            }
        }
    }

    public function testAllReunionTownsHaveWalkableVillagesInTheirZoneWithHousesScaledByPopulation(): void
    {
        $zones = WorldCatalog::zones();
        $villages = WorldCatalog::villages();
        self::assertGreaterThanOrEqual(24, count($villages), 'Chaque ville/commune de La Réunion doit posséder son village.');

        $byCode = [];
        $countByZone = [];
        foreach ($villages as $v) {
            $byCode[$v['code']] = $v;
            $countByZone[$v['zoneCode']] = ($countByZone[$v['zoneCode']] ?? 0) + 1;

            self::assertArrayHasKey($v['zoneCode'], $zones, sprintf('Zone %s invalide pour %s.', $v['zoneCode'], $v['name']));
            self::assertTrue(
                $this->terrain->isWalkable($v['x'], $v['z']),
                sprintf('Le village de %s (%.1f, %.1f) doit être sur un terrain marchable.', $v['name'], $v['x'], $v['z']),
            );
            self::assertSame(
                $v['zoneCode'],
                $this->terrain->getZoneCode($v['x'], $v['z']),
                sprintf('Le village de %s doit se trouver dans sa zone %s.', $v['name'], $v['zoneCode']),
            );
            self::assertTrue(
                WorldCatalog::isInSafeSanctuary($v['x'], $v['z']),
                sprintf('Le village de %s doit être reconnu comme sanctuaire de ville.', $v['name']),
            );
            self::assertGreaterThanOrEqual(3, $v['houses']);
        }

        // Every one of the 7 zones must contain at least one village
        foreach (array_keys($zones) as $zoneCode) {
            self::assertGreaterThanOrEqual(1, $countByZone[$zoneCode] ?? 0, sprintf('La zone %s doit comporter au moins un village.', $zoneCode));
        }

        // More populated towns must always have >= houses than less populated towns, and major cities strictly more
        foreach ($villages as $a) {
            foreach ($villages as $b) {
                if ($a['population'] > $b['population']) {
                    self::assertGreaterThanOrEqual(
                        $b['houses'],
                        $a['houses'],
                        sprintf('%s (%d hab., %d maisons) doit avoir au moins autant de maisons que %s (%d hab., %d maisons).', $a['name'], $a['population'], $a['houses'], $b['name'], $b['population'], $b['houses']),
                    );
                }
            }
        }

        self::assertGreaterThan($byCode['saint_paul']['houses'], $byCode['saint_denis']['houses']);
        self::assertGreaterThan($byCode['saint_leu']['houses'], $byCode['saint_pierre']['houses']);
        self::assertGreaterThan($byCode['cilaos']['houses'], $byCode['saint_leu']['houses']);
        self::assertGreaterThan($byCode['mafate']['houses'], $byCode['cilaos']['houses']);
    }
}
