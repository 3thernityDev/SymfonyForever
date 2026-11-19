<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\ItemDefinition;
use App\Game\NpcRegistry;
use App\Repository\MonsterTemplateRepository;
use App\Repository\WorldLootDropRepository;
use App\Service\Game\CombatService;
use App\Service\Game\InventoryService;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InventoryLootVendorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InventoryService $inventory;
    private CombatService $combat;
    private StatCalculator $stats;
    private NpcRegistry $npcs;
    private ReunionTerrainService $terrain;
    private WorldLootDropRepository $lootRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->inventory = $c->get(InventoryService::class);
        $this->combat = $c->get(CombatService::class);
        $this->stats = $c->get(StatCalculator::class);
        $this->npcs = $c->get(NpcRegistry::class);
        $this->terrain = $c->get(ReunionTerrainService::class);
        $this->lootRepo = $c->get(WorldLootDropRepository::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $c->get(WorldSeederService::class)->seed();
    }

    public function testLootDropPickupEquipConsumeAndNpcVendorFlow(): void
    {
        $now = microtime(true);
        $user = (new User())->setUsername('looter')->setEmail('loot@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $tiJean = $this->npcs->get('gramoun_tijean');
        self::assertNotNull($tiJean);
        $npcPos = $this->npcs->resolvedPosition($tiJean, $this->terrain);

        $hero = (new Character($user, 'ChasseurLoot', CharacterClass::DPS))
            ->setGold(100)
            ->setPosX($npcPos['x'] + 2.0)
            ->setPosY($npcPos['y'])
            ->setPosZ($npcPos['z'])
            ->setZoneCode('lagon_ouest')
            ->setLastSeenAt($now);
        $baseStats = $this->stats->compute($hero);
        $hero->setCurrentHp($baseStats['maxHp'])->setCurrentMp($baseStats['maxMp']);
        $this->em->persist($hero);
        $this->inventory->grantStarterItems($hero);
        $this->em->flush();

        // 1. Kill a Crabe de Récif -> spawns a ground WorldLootDrop
        $crabTpl = self::getContainer()->get(MonsterTemplateRepository::class)->findOneByCode('crabe_recif');
        self::assertNotNull($crabTpl);
        $monster = new MonsterInstance($crabTpl, 'lagon_ouest', $hero->getPosX() + 2.0, $hero->getPosY(), $hero->getPosZ());
        $monster->setCurrentHp(5);
        $this->em->persist($monster);
        $this->em->flush();

        $kill = $this->combat->castSkill($hero, 'ember_strike', 'MONSTER', $monster->getId(), $now);
        self::assertTrue($kill['ok']);

        $drops = $this->lootRepo->findActiveNearby($hero->getPosX(), $hero->getPosZ(), 20.0, $now);
        self::assertCount(1, $drops);
        $dropId = (int) $drops[0]->getId();
        $expectedGold = $drops[0]->getGold();
        self::assertGreaterThan(0, $expectedGold);

        // 2. Pickup loot -> gold & items transferred, drop removed
        $goldBefore = $hero->getGold();
        $pickup = $this->inventory->pickupLoot($hero, $dropId, $now + 0.5);
        self::assertTrue($pickup['ok']);
        self::assertSame($goldBefore + $expectedGold, $hero->getGold());
        self::assertNull($this->lootRepo->find($dropId));

        // 3. Buy a Lame de Corail from Gramoun Ti-Jean & equip it -> boosts attackPower & maxHp!
        $buy = $this->inventory->buyItem($hero, 'lame_corail', 'gramoun_tijean');
        self::assertTrue($buy['ok']);
        $inv = $this->inventory->buildInventoryState($hero);
        $swordEntry = null;
        foreach ($inv['bag'] as $b) {
            if ($b['itemCode'] === 'lame_corail') {
                $swordEntry = $b;
                break;
            }
        }
        self::assertNotNull($swordEntry);

        $atkBefore = $this->stats->compute($hero)['attackPower'];
        $hpMaxBefore = $this->stats->compute($hero)['maxHp'];
        $eqRes = $this->inventory->equipItem($hero, (int) $swordEntry['id']);
        self::assertTrue($eqRes['ok']);

        $statsAfter = $this->stats->compute($hero);
        self::assertGreaterThan($atkBefore, $statsAfter['attackPower']);
        self::assertGreaterThan($hpMaxBefore, $statsAfter['maxHp']);
        self::assertSame(3, $statsAfter['equipmentBonuses']['strength']);

        // Unequip puts it back into bag
        $unEq = $this->inventory->unequipSlot($hero, ItemDefinition::SLOT_WEAPON);
        self::assertTrue($unEq['ok']);
        self::assertSame(0, $this->stats->compute($hero)['equipmentBonuses']['strength']);

        // 4. Use a Tisane de Soin consumable to heal
        $hero->setCurrentHp(80);
        $this->em->flush();
        $inv2 = $this->inventory->buildInventoryState($hero);
        $potion = null;
        foreach ($inv2['bag'] as $b) {
            if ($b['itemCode'] === 'tisane_soin') {
                $potion = $b;
                break;
            }
        }
        self::assertNotNull($potion);
        $use = $this->inventory->useItem($hero, (int) $potion['id'], $now + 1.0);
        self::assertTrue($use['ok']);
        self::assertGreaterThan(80, $hero->getCurrentHp());

        // 5. Add junk items and sell all junk to Gramoun Ti-Jean in one click
        $this->inventory->addItemToBag($hero, 'pince_crabe', 3);
        $this->em->flush();
        $goldBeforeJunk = $hero->getGold();
        $sellJunk = $this->inventory->sellAllJunk($hero, 'gramoun_tijean');
        self::assertTrue($sellJunk['ok']);
        self::assertGreaterThanOrEqual(18, $sellJunk['goldEarned']);
        self::assertSame($goldBeforeJunk + $sellJunk['goldEarned'], $hero->getGold());

        // 6. Selling individual unequipped sword works nearby, fails when far away
        $hero->setPosX(400.0)->setPosZ(250.0);
        $this->em->flush();
        $sellFar = $this->inventory->sellItem($hero, (int) $swordEntry['id'], 'gramoun_tijean');
        self::assertFalse($sellFar['ok']);

        $hero->setPosX($npcPos['x'] + 2.0)->setPosZ($npcPos['z']);
        $this->em->flush();
        $goldBeforeSell = $hero->getGold();
        $sellNear = $this->inventory->sellItem($hero, (int) $swordEntry['id'], 'gramoun_tijean');
        self::assertTrue($sellNear['ok']);
        self::assertSame($goldBeforeSell + 14, $hero->getGold());
    }
}
