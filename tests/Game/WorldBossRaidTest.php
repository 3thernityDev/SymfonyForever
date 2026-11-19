<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\ChatMessage;
use App\Entity\MonsterInstance;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\ItemDefinition;
use App\Game\ItemRegistry;
use App\Repository\ChatMessageRepository;
use App\Repository\MonsterInstanceRepository;
use App\Repository\WorldLootDropRepository;
use App\Service\Game\CombatService;
use App\Service\Game\InventoryService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use App\Service\Game\WorldSimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorldBossRaidTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CombatService $combat;
    private WorldSimulationService $sim;
    private InventoryService $inventory;
    private StatCalculator $stats;
    private ItemRegistry $itemRegistry;
    private MonsterInstanceRepository $monsterRepo;
    private WorldLootDropRepository $lootRepo;
    private ChatMessageRepository $chatRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->combat = $c->get(CombatService::class);
        $this->sim = $c->get(WorldSimulationService::class);
        $this->inventory = $c->get(InventoryService::class);
        $this->stats = $c->get(StatCalculator::class);
        $this->itemRegistry = $c->get(ItemRegistry::class);
        $this->monsterRepo = $c->get(MonsterInstanceRepository::class);
        $this->lootRepo = $c->get(WorldLootDropRepository::class);
        $this->chatRepo = $c->get(ChatMessageRepository::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $c->get(WorldSeederService::class)->seed();
    }

    public function testSoloPlayerCannotDefeatWorldBossDueToCarapaceAndLethalOutput(): void
    {
        $now = 10000.0;
        $bosses = $this->monsterRepo->findWorldBosses();
        self::assertCount(1, $bosses);
        $boss = $bosses[0];
        self::assertSame('seigneur_dolomieu', $boss->getTemplate()->getCode());
        self::assertSame(65, $boss->getTemplate()->getLevel());
        self::assertSame(440000, $boss->getTemplate()->getMaxHp());

        $solo = $this->createLevel60Hero('solo_user', 'SoloDps', CharacterClass::DPS, $boss->getPosX() + 4.0, $boss->getPosY(), $boss->getPosZ(), $now);
        $this->em->flush();

        // Solo player hits the World Boss and fights over 14 seconds of ticks
        for ($sec = 0; $sec < 14; ++$sec) {
            $t = $now + $sec * 1.6;
            $solo->setLastSeenAt($t);
            $this->em->flush();

            if (!$solo->isDead()) {
                $this->combat->castSkill($solo, 'ember_strike', 'MONSTER', $boss->getId(), $t);
            }
            $this->sim->maybeTickZone('fournaise', $t + 1.1);
        }

        // Solo player MUST be defeated and World Boss remains near full HP thanks to Carapace Magmatique!
        self::assertTrue($solo->isDead(), 'Un joueur seul doit être vaincu par le World Boss.');
        self::assertGreaterThan(400000, $boss->getCurrentHp(), 'La Carapace Magmatique doit empêcher un joueur solo de descendre le World Boss.');
        self::assertNotSame(MonsterInstance::STATE_DEAD, $boss->getState());
    }

    public function testTenAlliedPlayersDefeatWorldBossAndEveryParticipantGetsGuaranteedLegendary(): void
    {
        $now = 20000.0;
        $boss = $this->monsterRepo->findWorldBosses()[0];

        // 10 allied players: 2 Tanks, 2 Healers (who ONLY heal!), 6 DPS — even without being in the same party!
        $heroes = [];
        $roster = [
            ['Tank1', CharacterClass::TANK],
            ['Tank2', CharacterClass::TANK],
            ['Heal1', CharacterClass::HEAL],
            ['Heal2', CharacterClass::HEAL],
            ['Dps1', CharacterClass::DPS],
            ['Dps2', CharacterClass::DPS],
            ['Dps3', CharacterClass::DPS],
            ['Dps4', CharacterClass::DPS],
            ['Dps5', CharacterClass::DPS],
            ['Dps6', CharacterClass::DPS],
        ];

        foreach ($roster as $idx => [$name, $cls]) {
            $angle = ($idx / 10) * M_PI * 2.0;
            $dist = $cls === CharacterClass::HEAL ? 14.0 : 5.0;
            $heroes[$name] = $this->createLevel60Hero(
                'raid_u_'.$idx,
                $name,
                $cls,
                $boss->getPosX() + cos($angle) * $dist,
                $boss->getPosY(),
                $boss->getPosZ() + sin($angle) * $dist,
                $now,
            );
        }
        $this->em->flush();

        // Tanks engage & taunt; DPS unleash damage; Healers ONLY heal the Tank (never touch boss directly!)
        $t = $now;
        $resTank1 = $this->combat->castSkill($heroes['Tank1'], 'piton_taunt', 'MONSTER', $boss->getId(), $t);
        self::assertTrue($resTank1['ok']);
        $resTank2 = $this->combat->castSkill($heroes['Tank2'], 'shield_bash', 'MONSTER', $boss->getId(), $t);
        self::assertTrue($resTank2['ok']);

        foreach (['Dps1', 'Dps2', 'Dps3', 'Dps4', 'Dps5', 'Dps6'] as $dpsName) {
            $res = $this->combat->castSkill($heroes[$dpsName], 'ember_strike', 'MONSTER', $boss->getId(), $t);
            self::assertTrue($res['ok']);
        }

        // Tick zone: with >= 3 engaged players, Carapace Magmatique is broken (no combat regen!)
        $hpBeforeTick = $boss->getCurrentHp();
        $this->sim->maybeTickZone('fournaise', $t + 1.5);
        self::assertSame($hpBeforeTick, $boss->getCurrentHp(), 'Avec 3+ joueurs engagés, la Carapace Magmatique ne doit plus régénérer le boss.');

        // Healers heal Tank1 -> must register both Healers as World Boss raid contributors!
        $heroes['Tank1']->setCurrentHp(max(1, $heroes['Tank1']->getCurrentHp() - 200));
        $this->em->flush();
        $h1 = $this->combat->castSkill($heroes['Heal1'], 'faham_infusion', 'PLAYER', $heroes['Tank1']->getId(), $t + 2.0);
        $h2 = $this->combat->castSkill($heroes['Heal2'], 'lagoon_blessing', null, null, $t + 2.0);
        self::assertTrue($h1['ok']);
        self::assertTrue($h2['ok']);
        self::assertSame(10, $boss->getRaidContributorCount(), 'Les 10 alliés (y compris les 2 Healers) doivent être enregistrés comme participants.');

        // Drop boss below 50% HP and tick -> triggers Phase 2 Fureur Magmatique!
        $boss->setCurrentHp(215000);
        $this->em->flush();
        $this->sim->maybeTickZone('fournaise', $t + 4.0);
        self::assertSame(2, $boss->getPhase(), 'Sous 50% PV, le World Boss doit passer en Phase 2.');

        // Simulate Tank2 falling heroically right before the kill: Tank2 MUST still get guaranteed Legendary loot!
        $heroes['Tank2']->setCurrentHp(0)->setIsDead(true);
        $boss->setCurrentHp(10);
        $this->em->flush();

        // Final blow by Dps1!
        $killRes = $this->combat->castSkill($heroes['Dps1'], 'ember_strike', 'MONSTER', $boss->getId(), $t + 6.0);
        self::assertTrue($killRes['ok']);
        self::assertSame(MonsterInstance::STATE_DEAD, $boss->getState());

        // Verify ALL 10 participants received their OWN personal WorldLootDrop chest with a 100% GUARANTEED LEGENDARY item!
        $drops = $this->lootRepo->findActiveNearby($boss->getPosX(), $boss->getPosZ(), 20.0, $t + 6.0);
        self::assertCount(10, $drops, 'Chaque participant parmi les 10 alliés doit obtenir son propre coffre Légendaire.');

        // Revive Tank2 to verify pickup as well
        $heroes['Tank2']->setIsDead(false)->setCurrentHp(500);
        $this->em->flush();

        $legendariesObtained = 0;
        foreach ($heroes as $heroName => $hero) {
            // Move hero close to the boss corpse to loot their personal chest
            $hero->setPosX($boss->getPosX())->setPosZ($boss->getPosZ());
            $this->em->flush();

            $myChests = array_values(array_filter(
                $drops,
                static fn ($d): bool => $d->getOwnerCharacterId() === $hero->getId(),
            ));
            self::assertCount(1, $myChests, sprintf('%s doit avoir exactement 1 coffre personnel.', $heroName));
            $myChest = $myChests[0];
            self::assertSame(ItemDefinition::RARITY_LEGENDARY, $myChest->getHighestRarity());

            // Another player CANNOT steal this personal chest
            $otherHero = $heroName === 'Dps1' ? $heroes['Dps2'] : $heroes['Dps1'];
            self::assertFalse($myChest->canBeLootedBy($otherHero, $t + 6.5));

            // Pick up personal Legendary chest!
            $pick = $this->inventory->pickupLoot($hero, (int) $myChest->getId(), $t + 7.0);
            self::assertTrue($pick['ok'], sprintf('%s doit pouvoir ramasser son coffre Légendaire.', $heroName));

            $hasLegendary = false;
            foreach ($pick['itemsGained'] ?? [] as $gained) {
                $def = $this->itemRegistry->get($gained['itemCode']);
                if ($def !== null && $def->rarity === ItemDefinition::RARITY_LEGENDARY) {
                    $hasLegendary = true;
                    ++$legendariesObtained;
                }
            }
            self::assertTrue($hasLegendary, sprintf('%s doit recevoir à coup sûr un objet LÉGENDAIRE.', $heroName));
        }

        self::assertSame(10, $legendariesObtained);

        // Verify Global System Chat announcements (pull, Phase 2, victory) were broadcast!
        $sysMsgs = $this->chatRepo->findVisibleSince('lagon_ouest', null, 0);
        $joined = implode("\n", array_map(static fn (ChatMessage $m): string => (string) $m->toArray()['content'], $sysMsgs));
        self::assertStringContainsString('[WORLD BOSS]', $joined);
        self::assertStringContainsString('Phase 2', $joined);
        self::assertStringContainsString('10 héros', $joined);
    }

    public function testWorldBossOneShotsNonTankOrSubLevel60AndKillsUnbuffedLevel60TankInThreeHitsMax(): void
    {
        $boss = $this->monsterRepo->findWorldBosses()[0];
        $simRef = new \ReflectionMethod(WorldSimulationService::class, 'performMonsterAttack');
        $events = [];

        // 1. Level 60 DPS, Level 60 Heal, and Level 59 Tank must ALL get ONE-SHOT in 1 swing!
        $lvl60Dps = $this->createLevel60Hero('os_dps', 'OneShotDps', CharacterClass::DPS, $boss->getPosX() + 3, $boss->getPosY(), $boss->getPosZ(), 30000.0);
        $lvl60Heal = $this->createLevel60Hero('os_heal', 'OneShotHeal', CharacterClass::HEAL, $boss->getPosX() + 3, $boss->getPosY(), $boss->getPosZ(), 30000.0);

        $user59 = (new User())->setUsername('os_t59')->setEmail('t59@reunion.re')->setPassword('hash');
        $this->em->persist($user59);
        $lvl59Tank = (new Character($user59, 'TankLvl59', CharacterClass::TANK))
            ->setLevel(59)->setZoneCode('fournaise')->setPosX($boss->getPosX() + 3)->setPosY($boss->getPosY())->setPosZ($boss->getPosZ());
        $g59 = CharacterClass::TANK->levelGrowth();
        $lvl59Tank->setVitality($lvl59Tank->getVitality() + $g59['vitality'] * 58)
            ->setDefense($lvl59Tank->getDefense() + $g59['defense'] * 58);
        $s59 = $this->stats->compute($lvl59Tank);
        $lvl59Tank->setCurrentHp($s59['maxHp'])->setCurrentMp($s59['maxMp']);
        $this->em->persist($lvl59Tank);
        $this->em->flush();

        foreach (['DPS Niv.60' => $lvl60Dps, 'Heal Niv.60' => $lvl60Heal, 'Tank Niv.59' => $lvl59Tank] as $lbl => $victim) {
            $simRef->invokeArgs($this->sim, [$boss, $victim, 30000.0, &$events]);
            self::assertTrue($victim->isDead(), $lbl.' doit être one-shot par le World Boss Niv. 65.');
            self::assertSame(0, $victim->getCurrentHp());
        }

        // 2. Unbuffed & unhealed Level 60 Tank survives 2 hits and MUST die on the 3rd hit (3 hits max)!
        $tank60 = $this->createLevel60Hero('tk60', 'BasaltLvl60', CharacterClass::TANK, $boss->getPosX() + 3, $boss->getPosY(), $boss->getPosZ(), 30010.0);
        $this->em->flush();

        $simRef->invokeArgs($this->sim, [$boss, $tank60, 30010.0, &$events]);
        self::assertFalse($tank60->isDead(), 'Le Tank Niv.60 doit survivre au 1er coup.');
        $simRef->invokeArgs($this->sim, [$boss, $tank60, 30012.0, &$events]);
        self::assertFalse($tank60->isDead(), 'Le Tank Niv.60 doit encore survivre au 2e coup sans buff/soin.');
        $simRef->invokeArgs($this->sim, [$boss, $tank60, 30014.0, &$events]);
        self::assertTrue($tank60->isDead(), 'Le Tank Niv.60 sans buff ni soin doit mourir au 3e coup (3 coups max).');

        // 3. With Peau de Basalte (basalt_skin) buff active, Level 60 Tank survives 3 hits easily!
        $buffedTank = $this->createLevel60Hero('tk60b', 'BasaltBuffed', CharacterClass::TANK, $boss->getPosX() + 3, $boss->getPosY(), $boss->getPosZ(), 30020.0);
        $this->em->flush();
        $buffRes = $this->combat->castSkill($buffedTank, 'basalt_skin', null, null, 30020.0);
        self::assertTrue($buffRes['ok']);

        for ($i = 0; $i < 3; ++$i) {
            $simRef->invokeArgs($this->sim, [$boss, $buffedTank, 30021.0 + $i * 2.0, &$events]);
        }
        self::assertFalse($buffedTank->isDead(), 'Sous Peau de Basalte (-40%), un Tank Niv.60 survit au 3e coup pour laisser le temps au Heal.');
    }

    private function createLevel60Hero(
        string $username,
        string $name,
        CharacterClass $cls,
        float $x,
        float $y,
        float $z,
        float $now,
    ): Character {
        $user = (new User())->setUsername($username)->setEmail($username.'@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $c = (new Character($user, $name, $cls))
            ->setLevel(60)
            ->setZoneCode('fournaise')
            ->setPosX($x)
            ->setPosY($y)
            ->setPosZ($z)
            ->setLastSeenAt($now);

        // Apply realistic Level 60 growth (59 level-ups) + allocated free stat points & Rank 5 core skill
        $growth = $cls->levelGrowth();
        $c->setVitality($c->getVitality() + $growth['vitality'] * 59 + 45)
            ->setStrength($c->getStrength() + $growth['strength'] * 59 + ($cls === CharacterClass::DPS ? 110 : 25))
            ->setIntelligence($c->getIntelligence() + $growth['intelligence'] * 59 + ($cls === CharacterClass::HEAL ? 110 : 20))
            ->setDefense($c->getDefense() + $growth['defense'] * 59 + ($cls === CharacterClass::TANK ? 95 : 22))
            ->setSpirit($c->getSpirit() + $growth['spirit'] * 59 + 30);

        $computed = $this->stats->compute($c, $now);
        $c->setCurrentHp($computed['maxHp'])->setCurrentMp($computed['maxMp']);
        $this->em->persist($c);

        return $c;
    }
}
