<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\NpcRegistry;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Repository\MonsterInstanceRepository;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorldCoverageAndAoiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReunionTerrainService $terrain;
    private WorldSeederService $seeder;
    private NpcRegistry $npcRegistry;
    private MonsterInstanceRepository $monsterRepo;
    private CharacterRepository $charRepo;
    private StatCalculator $stats;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->terrain = $c->get(ReunionTerrainService::class);
        $this->seeder = $c->get(WorldSeederService::class);
        $this->npcRegistry = $c->get(NpcRegistry::class);
        $this->monsterRepo = $c->get(MonsterInstanceRepository::class);
        $this->charRepo = $c->get(CharacterRepository::class);
        $this->stats = $c->get(StatCalculator::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAllQuestNpcsSitAtValidCampLocationsInExpectedZones(): void
    {
        $zones = WorldCatalog::zones();
        $npcs = $this->npcRegistry->all();
        self::assertCount(7, $npcs);

        foreach ($npcs as $npc) {
            self::assertTrue(
                $this->terrain->isWalkable($npc->x, $npc->z),
                sprintf('PNJ %s doit être sur un terrain marchable.', $npc->code),
            );
            $resolved = $this->npcRegistry->resolvedPosition($npc, $this->terrain);
            $drift = hypot($resolved['x'] - $npc->x, $resolved['z'] - $npc->z);
            self::assertLessThan(0.1, $drift, sprintf('PNJ %s ne doit pas dériver.', $npc->code));

            $actualZone = $this->terrain->getZoneCode($resolved['x'], $resolved['z']);
            self::assertSame($npc->zoneCode, $actualZone, sprintf('PNJ %s doit être dans la zone %s.', $npc->code, $npc->zoneCode));

            $zMeta = $zones[$npc->zoneCode];
            $distCamp = hypot($resolved['x'] - $zMeta['respawnX'], $resolved['z'] - $zMeta['respawnZ']);
            self::assertLessThanOrEqual(
                15.0,
                $distCamp,
                sprintf('PNJ %s doit être tout près du camp/respawn de %s (dist=%.1fm).', $npc->code, $npc->zoneCode, $distCamp),
            );
        }
    }

    public function testMonstersPopulateAllSevenZonesAcrossTheEntireIsland(): void
    {
        $summary = $this->seeder->seed();
        self::assertGreaterThanOrEqual(350, $summary['monsters'], 'L\'île entière doit être peuplée de monstres (>= 350).');

        $all = $this->monsterRepo->findAll();
        self::assertCount($summary['monsters'], $all);

        $countsByZone = [];
        $minX = INF;
        $maxX = -INF;
        $minZ = INF;
        $maxZ = -INF;
        $worldBossCount = 0;

        foreach ($all as $m) {
            $z = $m->getZoneCode();
            $countsByZone[$z] = ($countsByZone[$z] ?? 0) + 1;
            $minX = min($minX, $m->getPosX());
            $maxX = max($maxX, $m->getPosX());
            $minZ = min($minZ, $m->getPosZ());
            $maxZ = max($maxZ, $m->getPosZ());
            if ($m->getTemplate()->isWorldBoss()) {
                ++$worldBossCount;
            }
        }

        self::assertSame(1, $worldBossCount);
        foreach (array_keys(WorldCatalog::zones()) as $zoneCode) {
            self::assertArrayHasKey($zoneCode, $countsByZone);
            self::assertGreaterThanOrEqual(
                20,
                $countsByZone[$zoneCode],
                sprintf('La zone %s doit contenir au moins 20 monstres répartis sur son territoire.', $zoneCode),
            );
        }

        self::assertGreaterThanOrEqual(3500.0, $maxX - $minX, 'Les monstres doivent couvrir l\'île d\'Ouest en Est.');
        self::assertGreaterThanOrEqual(2400.0, $maxZ - $minZ, 'Les monstres doivent couvrir l\'île du Nord au Sud.');
    }

    public function testStrictCircularAoiReturnsOnlyNearbyPlayersAndMonsters(): void
    {
        $now = 25000.0;

        // Monsters: 1 inside circle (120m), 1 inside square bounding-box corner (226m > 200m), 1 far away
        $tpl = (new MonsterTemplate('aoi_mob', 'Mob AOI'))->setLevel(1)->setMaxHp(100);
        $this->em->persist($tpl);

        $nearMob = new MonsterInstance($tpl, 'cilaos', 120.0, 50.0, 0.0);
        $cornerMob = new MonsterInstance($tpl, 'cilaos', 160.0, 50.0, 160.0); // hypot = 226.27m > 200m
        $farMob = new MonsterInstance($tpl, 'fournaise', 1500.0, 200.0, 800.0);
        $this->em->persist($nearMob);
        $this->em->persist($cornerMob);
        $this->em->persist($farMob);

        // Players: 1 inside circle (80m), 1 inside bounding-box corner (219m > 200m), 1 far away
        $user = (new User())->setUsername('aoi_user')->setEmail('aoi@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $nearPlayer = (new Character($user, 'ProchePlayer', CharacterClass::DPS))
            ->setPosX(80.0)->setPosY(50.0)->setPosZ(0.0)->setLastSeenAt($now);
        $cornerPlayer = (new Character($user, 'CoinCarre', CharacterClass::TANK))
            ->setPosX(155.0)->setPosY(50.0)->setPosZ(155.0)->setLastSeenAt($now); // hypot = 219.20m > 200m
        $farPlayer = (new Character($user, 'LoinPlayer', CharacterClass::HEAL))
            ->setPosX(-2000.0)->setPosY(12.0)->setPosZ(-300.0)->setLastSeenAt($now);

        foreach ([$nearPlayer, $cornerPlayer, $farPlayer] as $p) {
            $s = $this->stats->compute($p, $now);
            $p->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($p);
        }
        $this->em->flush();

        $nearbyMobs = $this->monsterRepo->findNearby(0.0, 0.0, 200.0);
        self::assertCount(1, $nearbyMobs);
        self::assertSame($nearMob->getId(), $nearbyMobs[0]->getId());

        $nearbyPlayers = $this->charRepo->findOnlineNearby(0.0, 0.0, 200.0, $now);
        self::assertCount(1, $nearbyPlayers);
        self::assertSame($nearPlayer->getId(), $nearbyPlayers[0]->getId());
    }
}
