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
        self::assertFalse($this->terrain->isWalkable(-980.0, -980.0));
        self::assertLessThan(WorldCatalog::SEA_LEVEL, $this->terrain->getHeight(-980.0, -980.0));
        self::assertSame(WorldCatalog::BIOME_OCEAN, $this->terrain->getBiome(-980.0, -980.0));
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
        $peakY = $this->terrain->getHeight(-75.0, -65.0);
        $hubY = $this->terrain->getHeight(-585.0, -95.0);

        self::assertGreaterThan(130.0, $peakY);
        self::assertGreaterThan($hubY + 100.0, $peakY);
        self::assertSame('piton_neiges', $this->terrain->getZoneCode(-75.0, -65.0));
    }

    public function testFournaiseCraterHasVolcanicBiomeAndZone(): void
    {
        self::assertSame('fournaise', $this->terrain->getZoneCode(440.0, 275.0));
        self::assertSame(WorldCatalog::BIOME_VOLCANO_LAVA, $this->terrain->getBiome(440.0, 275.0));
    }

    public function testAllSevenZonesAreRepresentedOnIsland(): void
    {
        self::assertSame('salazie', $this->terrain->getZoneCode(95.0, -290.0));
        self::assertSame('mafate', $this->terrain->getZoneCode(-250.0, -240.0));
        self::assertSame('cilaos', $this->terrain->getZoneCode(-130.0, 145.0));
        self::assertSame('sud_sauvage', $this->terrain->getZoneCode(190.0, 520.0));
    }
}
