<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\ItemDefinition;
use App\Game\ItemRegistry;
use App\Game\NpcRegistry;
use App\Game\WorldCatalog;
use App\Service\Game\CombatService;
use App\Service\Game\InventoryService;
use App\Service\Game\LevelingService;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use App\Service\Game\WorldSimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MountSystemTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private InventoryService $inventory;
    private ItemRegistry $items;
    private NpcRegistry $npcs;
    private ReunionTerrainService $terrain;
    private StatCalculator $stats;
    private CombatService $combat;
    private WorldSimulationService $sim;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->inventory = $c->get(InventoryService::class);
        $this->items = $c->get(ItemRegistry::class);
        $this->npcs = $c->get(NpcRegistry::class);
        $this->terrain = $c->get(ReunionTerrainService::class);
        $this->stats = $c->get(StatCalculator::class);
        $this->combat = $c->get(CombatService::class);
        $this->sim = $c->get(WorldSimulationService::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $c->get(WorldSeederService::class)->seed();
    }

    public function testThreeMountTiersSoldByNpcsWithEscalatingPricesAndLevelSixtyFlightUnlock(): void
    {
        $m1 = $this->items->get('mount_sol_60');
        $m2 = $this->items->get('mount_sol_100');
        $m3 = $this->items->get('mount_vol_100');

        self::assertNotNull($m1);
        self::assertNotNull($m2);
        self::assertNotNull($m3);

        // Speeds & flight flags: doubled total speed across all 3 mounts (3.2x ground, 4.0x ground, 4.0x flying)
        self::assertSame(2.20, $m1->mountSpeedBonus);
        self::assertFalse($m1->mountCanFly);
        self::assertSame(3.00, $m2->mountSpeedBonus);
        self::assertFalse($m2->mountCanFly);
        self::assertSame(3.00, $m3->mountSpeedBonus);
        self::assertTrue($m3->mountCanFly);

        // Escalating prices: 200 < 500 < 1000, flying locked to Level 60
        self::assertSame(200, $m1->buyPrice);
        self::assertSame(500, $m2->buyPrice);
        self::assertSame(1000, $m3->buyPrice);
        self::assertSame(LevelingService::MAX_LEVEL, $m3->minLevel);
        self::assertGreaterThan($m1->buyPrice, $m2->buyPrice);
        self::assertGreaterThan($m2->buyPrice, $m3->buyPrice);

        // All hub NPCs sell the 3 mounts
        foreach ($this->npcs->all() as $npc) {
            $stock = $this->items->vendorStockForNpc($npc->code);
            self::assertContains('mount_sol_60', $stock);
            self::assertContains('mount_sol_100', $stock);
            self::assertContains('mount_vol_100', $stock);
        }

        $tiJean = $this->npcs->get('gramoun_tijean');
        self::assertNotNull($tiJean);
        $npcPos = $this->npcs->resolvedPosition($tiJean, $this->terrain);

        $user = (new User())->setUsername('mount_buyer')->setEmail('mb@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        // Level 59 hero with 2000 gold CANNOT buy the Level 60 flying mount
        $hero = (new Character($user, 'Chevaucheur', CharacterClass::DPS))
            ->setLevel(59)
            ->setGold(2000)
            ->setZoneCode('lagon_ouest')
            ->setPosX($npcPos['x'] + 2.0)
            ->setPosY($npcPos['y'])
            ->setPosZ($npcPos['z']);
        $s = $this->stats->compute($hero);
        $hero->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
        $this->em->persist($hero);
        $this->em->flush();

        $failFly = $this->inventory->buyItem($hero, 'mount_vol_100', 'gramoun_tijean');
        self::assertFalse($failFly['ok'], 'La monture volante ne doit pas être achetable avant le niveau 60.');
        self::assertSame(2000, $hero->getGold());

        // Level 59 can buy the +60% ground mount (200g) -> auto-equipped into SLOT_MOUNT
        $buy60 = $this->inventory->buyItem($hero, 'mount_sol_60', 'gramoun_tijean');
        self::assertTrue($buy60['ok']);
        self::assertSame(1800, $hero->getGold());
        $inv = $this->inventory->buildInventoryState($hero);
        self::assertSame('mount_sol_60', $inv['equipped'][ItemDefinition::SLOT_MOUNT]['itemCode']);

        // Reach Level 60 -> can buy flying mount for 1000g -> replaces SLOT_MOUNT & moves mount_sol_60 to bag
        $hero->setLevel(60);
        $this->em->flush();

        $buyFly = $this->inventory->buyItem($hero, 'mount_vol_100', 'gramoun_tijean');
        self::assertTrue($buyFly['ok']);
        self::assertSame(800, $hero->getGold());

        $invAfter = $this->inventory->buildInventoryState($hero);
        self::assertSame('mount_vol_100', $invAfter['equipped'][ItemDefinition::SLOT_MOUNT]['itemCode']);

        // Duplicate mount purchase blocked
        $dup = $this->inventory->buyItem($hero, 'mount_vol_100', 'gramoun_tijean');
        self::assertFalse($dup['ok']);
        self::assertSame(800, $hero->getGold());
    }

    public function testToggleMountHttpAndSyncFlightAndCombatRules(): void
    {
        $now = microtime(true);
        $hub = WorldCatalog::zones()['lagon_ouest'];
        $safeHub = $this->terrain->findNearestWalkable($hub['respawnX'], $hub['respawnZ']);

        $user = (new User())->setUsername('sky_rider')->setEmail('sky@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $rider = (new Character($user, 'VolcanRider', CharacterClass::DPS))
            ->setLevel(60)
            ->setGold(2500)
            ->setZoneCode('lagon_ouest')
            ->setPosX($safeHub['x'])
            ->setPosY($safeHub['y'])
            ->setPosZ($safeHub['z'])
            ->setLastSeenAt($now);
        $growth = CharacterClass::DPS->levelGrowth();
        $rider->setVitality($rider->getVitality() + $growth['vitality'] * 59)
            ->setStrength($rider->getStrength() + $growth['strength'] * 59 + 80);
        $s = $this->stats->compute($rider, $now);
        $rider->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
        $this->em->persist($rider);
        $this->em->flush();

        $this->client->loginUser($user);

        // 1. Toggling mount without owning one returns 422
        $this->client->request('POST', '/api/character/mount/toggle', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
        ]));
        self::assertResponseStatusCodeSame(422);

        // 2. Give rider mount_sol_100 in bag (not equipped yet) -> toggle C auto-equips & mounts at +100%
        $this->inventory->addItemToBag($rider, 'mount_sol_100', 1);
        $this->em->flush();

        $this->client->request('POST', '/api/character/mount/toggle', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
        ]));
        self::assertResponseIsSuccessful();
        $mOn = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($mOn['ok']);
        self::assertTrue($mOn['mounted']);
        self::assertSame('mount_sol_100', $mOn['self']['activeMountCode']);
        self::assertSame(3.0, (float) $mOn['self']['mountSpeedBonus']);
        self::assertFalse($mOn['self']['mountCanFly']);

        // 3. Server sync accepts doubled +300% (4.0x) ground mount movement speed without rubber-banding
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Character::class, $rider->getId());
        self::assertInstanceOf(Character::class, $fresh);
        $fresh->setLastSeenAt(microtime(true) - 1.0);
        $em->flush();

        // Pick a walkable point ~64 units away (exceeds previous 48 u/s limit, allowed at 96 u/s doubled speed)
        $dest = null;
        foreach ([64.0, 60.0, 56.0] as $radius) {
            for ($ang = 0; $ang < 360; $ang += 15) {
                $cx = $safeHub['x'] + cos(deg2rad((float) $ang)) * $radius;
                $cz = $safeHub['z'] + sin(deg2rad((float) $ang)) * $radius;
                if ($this->terrain->isWalkable($cx, $cz)) {
                    $dest = [$cx, $cz];
                    break 2;
                }
            }
        }
        self::assertNotNull($dest);

        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
            'x' => $dest[0],
            'y' => $safeHub['y'],
            'z' => $dest[1],
            'rotY' => 0.0,
            'isMoving' => true,
            'lastEventId' => 0,
            'lastChatId' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $syncGround = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($syncGround['correctedPosition']);
        self::assertEqualsWithDelta($dest[0], $syncGround['self']['x'], 0.2);

        // 4. Attacking from a ground mount auto-dismounts cleanly
        $tpl = (new MonsterTemplate('mount_dummy', 'Crabe Test'))->setLevel(1)->setMaxHp(200);
        $em->persist($tpl);
        $mob = new MonsterInstance($tpl, 'lagon_ouest', $dest[0] + 2.0, $safeHub['y'], $dest[1]);
        $em->persist($mob);
        $em->flush();

        $this->client->request('POST', '/api/combat/attack', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
            'targetType' => 'MONSTER',
            'targetId' => $mob->getId(),
        ]));
        self::assertResponseIsSuccessful();
        $aa = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($aa['ok']);
        self::assertNull($aa['self']['activeMountCode'], 'Attaquer depuis une monture terrestre doit faire descendre le joueur.');

        // 5. Switch to flying mount (mount_vol_100), mount up, fly over ocean & elevated altitude Y!
        $em2 = self::getContainer()->get(EntityManagerInterface::class);
        $inv2 = self::getContainer()->get(InventoryService::class);
        $r2 = $em2->find(Character::class, $rider->getId());
        self::assertInstanceOf(Character::class, $r2);
        $inv2->addItemToBag($r2, 'mount_vol_100', 1);
        $em2->flush();
        $flyBagItem = $inv2->buildInventoryState($r2)['bag'];
        $flyCiId = null;
        foreach ($flyBagItem as $row) {
            if ($row['itemCode'] === 'mount_vol_100') {
                $flyCiId = (int) $row['id'];
                break;
            }
        }
        self::assertNotNull($flyCiId);
        $this->client->request('POST', '/api/inventory/equip', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
            'characterItemId' => $flyCiId,
        ]));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/character/mount/toggle', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
        ]));
        self::assertResponseIsSuccessful();
        $flyOn = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($flyOn['mounted']);
        self::assertTrue($flyOn['canFly']);
        self::assertSame('mount_vol_100', $flyOn['self']['activeMountCode']);

        // Combat blocked while flying
        $this->client->request('POST', '/api/combat/attack', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
            'targetType' => 'MONSTER',
            'targetId' => $mob->getId(),
        ]));
        self::assertResponseStatusCodeSame(422);

        // Fly out above unwalkable deep ocean (-3400, -3400) & doubled +36 u/s climb + high horizontal flight speed
        $em3 = self::getContainer()->get(EntityManagerInterface::class);
        $r3 = $em3->find(Character::class, $rider->getId());
        self::assertInstanceOf(Character::class, $r3);
        $r3->setPosX(-3350.0)->setPosZ(-3350.0)->setPosY(120.0)->setLastSeenAt(microtime(true) - 1.0);
        $em3->flush();

        self::assertFalse($this->terrain->isWalkable(-3400.0, -3400.0));
        $this->client->request('POST', '/api/world/sync', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
            'x' => -3400.0,
            'y' => 156.0,
            'z' => -3400.0,
            'rotY' => 1.0,
            'isMoving' => true,
            'lastEventId' => 0,
            'lastChatId' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $syncFly = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($syncFly['correctedPosition']);
        self::assertEqualsWithDelta(156.0, $syncFly['self']['y'], 0.2);

        // Dismounting over deep ocean is refused, over walkable land succeeds
        $this->client->request('POST', '/api/character/mount/toggle', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
        ]));
        self::assertResponseStatusCodeSame(422);

        $em4 = self::getContainer()->get(EntityManagerInterface::class);
        $r4 = $em4->find(Character::class, $rider->getId());
        self::assertInstanceOf(Character::class, $r4);
        $r4->setPosX($safeHub['x'])->setPosZ($safeHub['z'])->setPosY($safeHub['y'] + 25.0);
        $em4->flush();

        $this->client->request('POST', '/api/character/mount/toggle', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'characterId' => $rider->getId(),
        ]));
        self::assertResponseIsSuccessful();
        $dismountOk = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($dismountOk['mounted']);
        self::assertNull($dismountOk['self']['activeMountCode']);
        self::assertEqualsWithDelta($safeHub['y'], $dismountOk['self']['y'], 0.2);

        // 6. Death clears active mount cleanly
        $em5 = self::getContainer()->get(EntityManagerInterface::class);
        $r5 = $em5->find(Character::class, $rider->getId());
        self::assertInstanceOf(Character::class, $r5);
        $r5->setActiveMountCode('mount_vol_100')->setCurrentHp(1)->setLastCombatAt(microtime(true));
        $em5->flush();

        $lethalTpl = (new MonsterTemplate('lethal_mount_mob', 'Gardien Test'))
            ->setLevel(60)->setMaxHp(1000)->setAttack(5000);
        $em5->persist($lethalTpl);
        $lethalMob = new MonsterInstance($lethalTpl, 'lagon_ouest', $safeHub['x'], $safeHub['y'], $safeHub['z']);
        $em5->persist($lethalMob);
        $em5->flush();

        $sim5 = self::getContainer()->get(WorldSimulationService::class);
        $atkMethod = new \ReflectionMethod(WorldSimulationService::class, 'performMonsterAttack');
        $events = [];
        $atkMethod->invokeArgs($sim5, [$lethalMob, $r5, microtime(true), &$events]);
        self::assertTrue($r5->isDead());
        self::assertNull($r5->getActiveMountCode(), 'La mort du personnage doit retirer sa monture active.');
    }
}
