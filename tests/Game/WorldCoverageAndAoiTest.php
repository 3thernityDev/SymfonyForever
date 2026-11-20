<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\MonsterScaling;
use App\Game\NpcRegistry;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Repository\MonsterInstanceRepository;
use App\Repository\MonsterTemplateRepository;
use App\Service\Game\CombatService;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use App\Service\Game\WorldSimulationService;
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

    public function testAllThirtyEndemicAndIconicTemplatesAreSeededWithSpawnedInstances(): void
    {
        $summary = $this->seeder->seed();
        self::assertSame(30, $summary['templates'], 'Le bestiaire doit comporter 30 modèles de créatures (dont les 18 animaux endémiques ajoutés).');

        $c = self::getContainer();
        $tplRepo = $c->get(MonsterTemplateRepository::class);
        $templates = $tplRepo->findAll();
        self::assertCount(30, $templates);

        $endemicCodes = [
            'endormi_bourbon', 'paille_en_queue', 'tec_tec_foret', 'zoizo_lunettes',
            'tuit_tuit', 'gecko_bourbon', 'bibe_mafate', 'oiseau_vert_cilaos',
            'merle_peyi', 'gecko_manapany', 'tortue_bourbon', 'scolopendre_basalte',
            'petrel_barau', 'petrel_noir', 'papillon_salazie', 'roussette_bourbon',
            'ibis_reunion', 'chouette_bourbon',
        ];
        $seededCodes = array_map(static fn ($t): string => $t->getCode(), $templates);
        foreach ($endemicCodes as $code) {
            self::assertContains($code, $seededCodes, sprintf('Le monstre endémique %s doit exister.', $code));
        }

        $instancesByCode = [];
        foreach ($this->monsterRepo->findAll() as $inst) {
            $code = $inst->getTemplate()->getCode();
            $instancesByCode[$code] = ($instancesByCode[$code] ?? 0) + 1;
        }

        foreach ($templates as $tpl) {
            self::assertGreaterThanOrEqual(
                1,
                $instancesByCode[$tpl->getCode()] ?? 0,
                sprintf('Le modèle %s doit être apparu au moins une fois sur La Réunion.', $tpl->getCode()),
            );
        }
    }

    public function testZonesAndMonstersSpanLevelsOneToSixtyFiveWithMonotonicScaling(): void
    {
        $this->seeder->seed();
        $zones = WorldCatalog::zones();

        self::assertSame(1, $zones['lagon_ouest']['minLevel']);
        self::assertSame(60, $zones['fournaise']['maxLevel']);

        $prevMin = 0;
        foreach ($zones as $z) {
            self::assertGreaterThanOrEqual($prevMin, $z['minLevel']);
            self::assertGreaterThan($z['minLevel'], $z['maxLevel']);
            $prevMin = $z['minLevel'];
        }

        // Strict monotonic scaling on computeStats() from Lv 1 to Lv 60 and Lv 65
        $prev = MonsterScaling::computeStats(1, MonsterScaling::ARCHETYPE_BALANCED);
        self::assertSame(['maxHp' => 200, 'attack' => 10, 'defense' => 5, 'xpReward' => 42], $prev);

        for ($lvl = 2; $lvl <= 65; ++$lvl) {
            $cur = MonsterScaling::computeStats($lvl, MonsterScaling::ARCHETYPE_BALANCED);
            self::assertGreaterThan($prev['maxHp'], $cur['maxHp'], sprintf('PV doivent croître strictement au niveau %d.', $lvl));
            self::assertGreaterThan($prev['attack'], $cur['attack'], sprintf('Attaque doit croître strictement au niveau %d.', $lvl));
            self::assertGreaterThan($prev['defense'], $cur['defense'], sprintf('Défense doit croître strictement au niveau %d.', $lvl));
            self::assertGreaterThan($prev['xpReward'], $cur['xpReward'], sprintf('XP doit croître strictement au niveau %d.', $lvl));
            $prev = $cur;
        }

        // Verify seeded non-boss/non-elite monsters obey 1..60 scaling & World Boss is Lv 65
        $c = self::getContainer();
        $tplRepo = $c->get(MonsterTemplateRepository::class);
        $normalByLevel = [];
        $hasLvl1 = false;
        $hasLvl60 = false;
        $hasBoss65 = false;

        foreach ($tplRepo->findAll() as $tpl) {
            if ($tpl->isWorldBoss()) {
                self::assertSame(65, $tpl->getLevel());
                self::assertSame(440000, $tpl->getMaxHp());
                $hasBoss65 = true;
                continue;
            }
            self::assertGreaterThanOrEqual(1, $tpl->getLevel());
            self::assertLessThanOrEqual(60, $tpl->getLevel());
            if ($tpl->getLevel() === 1) {
                $hasLvl1 = true;
            }
            if ($tpl->getLevel() === 60) {
                $hasLvl60 = true;
            }
            if (!$tpl->isBoss()) {
                $normalByLevel[] = $tpl;
            }
        }

        self::assertTrue($hasLvl1 && $hasLvl60 && $hasBoss65);
        usort($normalByLevel, static fn ($a, $b): int => $a->getLevel() <=> $b->getLevel());
        self::assertGreaterThan($normalByLevel[0]->getMaxHp() * 50, end($normalByLevel)->getMaxHp());
        self::assertGreaterThan($normalByLevel[0]->getAttack() * 40, end($normalByLevel)->getAttack());
        self::assertGreaterThan($normalByLevel[0]->getDefense() * 30, end($normalByLevel)->getDefense());
    }

    public function testLevelSixtyCombatBalanceAndLowLevelLethality(): void
    {
        mt_srand(60974);
        $this->seeder->seed();
        $c = self::getContainer();
        $combat = $c->get(CombatService::class);
        $sim = $c->get(WorldSimulationService::class);
        $tplRepo = $c->get(MonsterTemplateRepository::class);

        $lv60Tpl = $tplRepo->findOneByCode('chouette_bourbon');
        self::assertNotNull($lv60Tpl);
        self::assertSame(60, $lv60Tpl->getLevel());

        $now = 40000.0;
        $user = (new User())->setUsername('endgame60')->setEmail('e60@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $growth = CharacterClass::DPS->levelGrowth();
        $dps60 = (new Character($user, 'Chasseur60', CharacterClass::DPS))
            ->setLevel(60)
            ->setZoneCode('fournaise')
            ->setPosX(1200.0)->setPosY(180.0)->setPosZ(850.0)
            ->setLastSeenAt($now);
        $dps60->setVitality($dps60->getVitality() + $growth['vitality'] * 59 + 40)
            ->setStrength($dps60->getStrength() + $growth['strength'] * 59 + 115)
            ->setDefense($dps60->getDefense() + $growth['defense'] * 59 + 22)
            ->setSkillLevel('ember_strike', 5);
        $s60 = $this->stats->compute($dps60, $now);
        $dps60->setCurrentHp($s60['maxHp'])->setCurrentMp($s60['maxMp']);
        $this->em->persist($dps60);

        // 1. Level 60 DPS kills a Level 60 mob in <= 4 Rank 5 skills
        $skillTarget = new MonsterInstance($lv60Tpl, 'fournaise', 1202.0, 180.0, 850.0);
        $this->em->persist($skillTarget);
        $this->em->flush();

        $casts = 0;
        $t = $now;
        while ($skillTarget->getState() !== MonsterInstance::STATE_DEAD && $casts < 6) {
            $t += 2.0;
            ++$casts;
            $res = $combat->castSkill($dps60, 'ember_strike', 'MONSTER', $skillTarget->getId(), $t);
            self::assertTrue($res['ok']);
        }
        self::assertSame(MonsterInstance::STATE_DEAD, $skillTarget->getState());
        self::assertLessThanOrEqual(4, $casts, 'Un DPS Niv. 60 doit vaincre un mob Niv. 60 en <= 4 compétences.');

        // 2. Level 60 DPS kills a Level 60 mob at auto-attack in ~18-38 hits (~30s)
        $aaTarget = new MonsterInstance($lv60Tpl, 'fournaise', 1202.0, 180.0, 850.0);
        $this->em->persist($aaTarget);
        $this->em->flush();

        $swings = 0;
        while ($aaTarget->getState() !== MonsterInstance::STATE_DEAD && $swings < 50) {
            $t += 1.3;
            ++$swings;
            $aa = $combat->performAutoAttack($dps60, 'MONSTER', $aaTarget->getId(), $t);
            self::assertTrue($aa['ok']);
        }
        self::assertSame(MonsterInstance::STATE_DEAD, $aaTarget->getState());
        self::assertGreaterThanOrEqual(18, $swings);
        self::assertLessThanOrEqual(38, $swings);

        // 3. Level 10 DPS gets devastated by a Level 60 mob
        $dps10 = (new Character($user, 'Imprudent10', CharacterClass::DPS))
            ->setLevel(10)
            ->setZoneCode('fournaise')
            ->setPosX(1202.0)->setPosY(180.0)->setPosZ(850.0)
            ->setLastSeenAt($t);
        $dps10->setVitality($dps10->getVitality() + $growth['vitality'] * 9)
            ->setStrength($dps10->getStrength() + $growth['strength'] * 9)
            ->setDefense($dps10->getDefense() + $growth['defense'] * 9);
        $s10 = $this->stats->compute($dps10, $t);
        $dps10->setCurrentHp($s10['maxHp'])->setCurrentMp($s10['maxMp']);
        $this->em->persist($dps10);
        $this->em->flush();

        $lv60AttackTarget = new MonsterInstance($lv60Tpl, 'fournaise', 1202.0, 180.0, 850.0);
        $this->em->persist($lv60AttackTarget);
        $this->em->flush();

        $atkMethod = new \ReflectionMethod(WorldSimulationService::class, 'performMonsterAttack');
        $events = [];
        $hpBefore = $dps10->getCurrentHp();
        $atkMethod->invokeArgs($sim, [$lv60AttackTarget, $dps10, $t + 1.0, &$events]);
        $dmgTaken = $hpBefore - $dps10->getCurrentHp();
        self::assertGreaterThanOrEqual((int) ($s10['maxHp'] * 0.65), $dmgTaken, 'Un mob Niv. 60 doit infliger des dégâts massifs à un joueur Niv. 10.');
        $atkMethod->invokeArgs($sim, [$lv60AttackTarget, $dps10, $t + 3.0, &$events]);
        self::assertTrue($dps10->isDead(), 'Un joueur Niv. 10 doit être vaincu en 2 coups maximum par un mob Niv. 60.');
    }

    public function testSocialSpeciesAreConfiguredAndPairedWithNearbyCompatriots(): void
    {
        $summary1 = $this->seeder->seed();
        $c = self::getContainer();
        $tplRepo = $c->get(MonsterTemplateRepository::class);

        $expectedSocial = ['guepe_cotiere', 'zoizo_lunettes', 'sanglier_salazie', 'cerf_java', 'roussette_bourbon', 'molosse_magma'];
        foreach ($expectedSocial as $code) {
            $tpl = $tplRepo->findOneByCode($code);
            self::assertNotNull($tpl, sprintf('Template %s introuvable.', $code));
            self::assertTrue($tpl->isSocial(), sprintf('%s doit être configuré comme espèce sociale.', $code));
            self::assertGreaterThan(0.0, $tpl->getFleeHpRatio());
            self::assertLessThan(1.0, $tpl->getFleeHpRatio());
            self::assertGreaterThan(0.0, $tpl->getCallHelpRadius());
        }

        $expectedSolitary = ['crabe_recif', 'endormi_bourbon', 'ibis_reunion', 'seigneur_dolomieu'];
        foreach ($expectedSolitary as $code) {
            $tpl = $tplRepo->findOneByCode($code);
            self::assertNotNull($tpl, sprintf('Template %s introuvable.', $code));
            self::assertFalse($tpl->isSocial(), sprintf('%s doit rester solitaire.', $code));
        }

        $all = $this->monsterRepo->findAll();
        $byTemplate = [];
        foreach ($all as $inst) {
            $byTemplate[$inst->getTemplate()->getCode()][] = $inst;
        }

        foreach ($tplRepo->findAll() as $tpl) {
            if (!$tpl->isSocial()) {
                continue;
            }
            $group = $byTemplate[$tpl->getCode()] ?? [];
            self::assertNotEmpty($group);
            $radius = $tpl->getCallHelpRadius();
            foreach ($group as $inst) {
                $hasNearby = false;
                foreach ($group as $other) {
                    if ($other === $inst || $other->getZoneCode() !== $inst->getZoneCode()) {
                        continue;
                    }
                    if (hypot($other->getSpawnX() - $inst->getSpawnX(), $other->getSpawnZ() - $inst->getSpawnZ()) <= $radius) {
                        $hasNearby = true;
                        break;
                    }
                }
                self::assertTrue(
                    $hasNearby,
                    sprintf('L\'espèce sociale %s (#%d) doit avoir un compatriote à portée (<= %.1fm).', $tpl->getCode(), $inst->getId(), $radius),
                );
            }
        }

        // Calling seed() a second time must be strictly idempotent (zero extra mobs)
        $summary2 = $this->seeder->seed();
        self::assertSame($summary1['monsters'], $summary2['monsters']);
        self::assertCount($summary1['monsters'], $this->monsterRepo->findAll());
    }
}
