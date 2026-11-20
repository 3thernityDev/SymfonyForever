<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\CombatEvent;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\Party;
use App\Entity\User;
use App\Entity\Zone;
use App\Game\CharacterClass;
use App\Game\MonsterRespawnScaler;
use App\Game\WorldCatalog;
use App\Repository\MonsterTemplateRepository;
use App\Service\Game\CombatService;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldAtmosphereService;
use App\Service\Game\WorldSeederService;
use App\Service\Game\WorldSimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CombatAndSimulationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CombatService $combat;
    private WorldSimulationService $sim;
    private StatCalculator $stats;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->combat = $c->get(CombatService::class);
        $this->sim = $c->get(WorldSimulationService::class);
        $this->stats = $c->get(StatCalculator::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testTrinityCombatTankAggroDpsDamageAndHealSupport(): void
    {
        $now = microtime(true);

        $zone = (new Zone('lagon_ouest', 'Lagon'))->setLastTickAt($now - 5.0);
        $this->em->persist($zone);

        $user = (new User())->setUsername('trinity')->setEmail('trinity@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $party = (new Party())->setName('Escouade 974');
        $this->em->persist($party);

        $hub = WorldCatalog::zones()['lagon_ouest'];
        $hx = $hub['respawnX'];
        $hz = $hub['respawnZ'];

        $tank = (new Character($user, 'Tank974', CharacterClass::TANK))
            ->setLevel(4)->setPosX($hx)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now)->setParty($party);
        $dps = (new Character($user, 'Dps974', CharacterClass::DPS))
            ->setLevel(4)->setPosX($hx + 2.0)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now)->setParty($party);
        $heal = (new Character($user, 'Heal974', CharacterClass::HEAL))
            ->setLevel(4)->setPosX($hx - 3.0)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now)->setParty($party);

        foreach ([$tank, $dps, $heal] as $ch) {
            $s = $this->stats->compute($ch);
            $ch->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($ch);
        }

        $tpl = (new MonsterTemplate('test_crab', 'Crabe Géant'))
            ->setLevel(3)->setMaxHp(500)->setAttack(20)->setDefense(5)->setXpReward(160);
        $this->em->persist($tpl);

        $monster = new MonsterInstance($tpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz);
        $this->em->persist($monster);
        $this->em->flush();

        // 1. DPS hits with ember_strike and burning_brand (DoT)
        $r1 = $this->combat->castSkill($dps, 'ember_strike', 'MONSTER', $monster->getId(), $now);
        self::assertTrue($r1['ok']);
        self::assertLessThan(500, $monster->getCurrentHp());

        $r2 = $this->combat->castSkill($dps, 'burning_brand', 'MONSTER', $monster->getId(), $now + 2.0);
        self::assertTrue($r2['ok']);
        self::assertNotEmpty($monster->getActiveEffects());

        // 2. Tank uses piton_taunt -> Tank MUST become top threat target!
        $rTaunt = $this->combat->castSkill($tank, 'piton_taunt', 'MONSTER', $monster->getId(), $now + 2.2);
        self::assertTrue($rTaunt['ok']);
        self::assertSame($tank->getId(), $monster->getTopThreatCharacterId($now + 2.5));

        // 3. Injure Tank and have Heal cast faham_infusion + salazie_mist (HoT)
        $tank->setCurrentHp(80);
        $this->em->flush();

        $rHeal = $this->combat->castSkill($heal, 'faham_infusion', 'PLAYER', $tank->getId(), $now + 2.5);
        self::assertTrue($rHeal['ok']);
        self::assertGreaterThan(80, $tank->getCurrentHp());

        $rHot = $this->combat->castSkill($heal, 'salazie_mist', 'PLAYER', $tank->getId(), $now + 4.5);
        self::assertTrue($rHot['ok']);
        self::assertNotEmpty($tank->getActiveEffects());

        // 4. Simulation tick executes DoT on monster & HoT on tank
        $hpBeforeDot = $monster->getCurrentHp();
        $ticked = $this->sim->maybeTickZone('lagon_ouest', $now + 7.0);
        self::assertTrue($ticked);
        self::assertLessThan($hpBeforeDot, $monster->getCurrentHp());

        // 5. Finish off monster with DPS volcanic_fury -> grants shared XP to party!
        $monster->setCurrentHp(15);
        $this->em->flush();
        $rKill = $this->combat->castSkill($dps, 'volcanic_fury', 'MONSTER', $monster->getId(), $now + 10.0);
        self::assertTrue($rKill['ok']);
        self::assertSame(MonsterInstance::STATE_DEAD, $monster->getState());
        self::assertGreaterThan(0, $rKill['xpGained']);
        self::assertGreaterThan(0, $tank->getXp());
        self::assertGreaterThan(0, $heal->getXp());
    }

    public function testAutoAttackThirtySecondBalanceFourSkillMaxAndClassResources(): void
    {
        self::getContainer()->get(WorldSeederService::class)->seed();
        $tplRepo = self::getContainer()->get(MonsterTemplateRepository::class);
        $crabTpl = $tplRepo->findOneByCode('crabe_recif');
        self::assertNotNull($crabTpl);
        self::assertSame(200, $crabTpl->getMaxHp());

        $now = 5000.0;
        $user = (new User())->setUsername('balancer')->setEmail('bal@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $hub = WorldCatalog::zones()['lagon_ouest'];
        $hx = $hub['respawnX'];
        $hz = $hub['respawnZ'];

        // 1. Verify class resource pools & insufficient-resource rejection
        $tank = (new Character($user, 'RageTank', CharacterClass::TANK))
            ->setLevel(1)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now);
        $dps = (new Character($user, 'EnergyDps', CharacterClass::DPS))
            ->setLevel(1)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now);
        $heal = (new Character($user, 'ManaHeal', CharacterClass::HEAL))
            ->setLevel(1)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now);

        self::assertSame(100, $this->stats->compute($tank)['maxMp']);
        self::assertSame(100, $this->stats->compute($dps)['maxMp']);
        self::assertSame(185, $this->stats->compute($heal)['maxMp']);

        foreach ([$tank, $dps, $heal] as $c) {
            $s = $this->stats->compute($c);
            $c->setCurrentHp($s['maxHp'])->setCurrentMp(0);
            $this->em->persist($c);
        }

        $dummy = new MonsterInstance($crabTpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz);
        $this->em->persist($dummy);
        $this->em->flush();

        $noRage = $this->combat->castSkill($tank, 'shield_bash', 'MONSTER', $dummy->getId(), $now);
        self::assertFalse($noRage['ok']);
        self::assertSame('Rage insuffisante.', $noRage['error']);

        $noEnergy = $this->combat->castSkill($dps, 'ember_strike', 'MONSTER', $dummy->getId(), $now);
        self::assertFalse($noEnergy['ok']);
        self::assertSame('Énergie insuffisante.', $noEnergy['error']);

        $noMana = $this->combat->castSkill($heal, 'solar_flare', 'MONSTER', $dummy->getId(), $now);
        self::assertFalse($noMana['ok']);
        self::assertSame('Mana insuffisant.', $noMana['error']);

        // 2. Auto-Attack costs 0 resource, generates Rage/Énergie/Mana, and takes ~30s (~20-34 hits) on a same-level mob
        $aaMob = new MonsterInstance($crabTpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz);
        $this->em->persist($aaMob);
        $this->em->flush();

        $t = $now + 10.0;
        $aa1 = $this->combat->performAutoAttack($dps, 'MONSTER', $aaMob->getId(), $t);
        self::assertTrue($aa1['ok']);
        self::assertSame(10, $aa1['resourceGain']);
        self::assertSame(10, $dps->getCurrentMp());

        // Too rapid swing (< 1.2s) rejected, while skill GCD remains free
        $aaTooSoon = $this->combat->performAutoAttack($dps, 'MONSTER', $aaMob->getId(), $t + 0.3);
        self::assertFalse($aaTooSoon['ok']);

        $swings = 1;
        while ($aaMob->getState() !== MonsterInstance::STATE_DEAD && $swings < 45) {
            $t += 1.25;
            ++$swings;
            $res = $this->combat->performAutoAttack($dps, 'MONSTER', $aaMob->getId(), $t);
            self::assertTrue($res['ok']);
        }
        self::assertSame(MonsterInstance::STATE_DEAD, $aaMob->getState());
        self::assertGreaterThanOrEqual(16, $swings);
        self::assertLessThanOrEqual(34, $swings, 'Tuer un monstre de même niveau à l\'auto-attaque doit prendre ~30s.');

        // Also check Tank (+15 Rage/hit) & Heal (+10 Mana/hit) generation
        $aaTankMob = new MonsterInstance($crabTpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz);
        $this->em->persist($aaTankMob);
        $this->em->flush();
        $aaTank = $this->combat->performAutoAttack($tank, 'MONSTER', $aaTankMob->getId(), $t + 5.0);
        self::assertTrue($aaTank['ok']);
        self::assertSame(15, $tank->getCurrentMp());

        // 3. Using class skills kills a same-level mob in <= 4 skills MAX across DPS, TANK and HEAL
        foreach ([
            [$dps, 'ember_strike', 'DPS'],
            [$tank, 'shield_bash', 'TANK'],
            [$heal, 'solar_flare', 'HEAL'],
        ] as [$hero, $skillCode, $label]) {
            $s = $this->stats->compute($hero);
            $hero->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $skillMob = new MonsterInstance($crabTpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz);
            $this->em->persist($skillMob);
            $this->em->flush();

            $casts = 0;
            $tCast = $t + 50.0;
            while ($skillMob->getState() !== MonsterInstance::STATE_DEAD && $casts < 6) {
                $tCast += 2.0;
                ++$casts;
                $mpBefore = $hero->getCurrentMp();
                $r = $this->combat->castSkill($hero, $skillCode, 'MONSTER', $skillMob->getId(), $tCast);
                self::assertTrue($r['ok'], $label.' skill '.$skillCode.' failed: '.($r['error'] ?? ''));
                self::assertLessThan($mpBefore, $hero->getCurrentMp(), $label.' skill must consume resource.');
            }
            self::assertSame(MonsterInstance::STATE_DEAD, $skillMob->getState());
            self::assertLessThanOrEqual(4, $casts, sprintf('%s doit tuer un monstre de même niveau en 4 skills max (trouvé %d).', $label, $casts));
            $t = $tCast;
        }
    }

    public function testSkillRanksScaleDamageHealingAndDefensiveBuffs(): void
    {
        mt_srand(42);
        $now = 8000.0;
        $user = (new User())->setUsername('scaler')->setEmail('sc@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $hub = WorldCatalog::zones()['lagon_ouest'];
        $hx = $hub['respawnX'];
        $hz = $hub['respawnZ'];

        $dps = (new Character($user, 'RankDps', CharacterClass::DPS))
            ->setLevel(6)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz);
        $heal = (new Character($user, 'RankHeal', CharacterClass::HEAL))
            ->setLevel(6)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz);
        $tank = (new Character($user, 'RankTank', CharacterClass::TANK))
            ->setLevel(6)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz);

        foreach ([$dps, $heal, $tank] as $c) {
            $s = $this->stats->compute($c);
            $c->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($c);
        }

        $tpl = (new MonsterTemplate('dummy_scale', 'Mannequin Basalte'))
            ->setLevel(6)->setMaxHp(5000)->setDefense(20)->setXpReward(10);
        $this->em->persist($tpl);
        $dummy = new MonsterInstance($tpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz);
        $this->em->persist($dummy);
        $this->em->flush();

        // 1. Damage scaling: ember_strike Rank 1 vs Rank 3 (+44%, normalizing crit multiplier)
        $evDmg1 = $this->combat->castSkill($dps, 'ember_strike', 'MONSTER', $dummy->getId(), $now)['events'][0];
        $dmgR1Norm = $evDmg1['isCrit'] ? ((float) $evDmg1['amount'] / 1.65) : (float) $evDmg1['amount'];
        $dps->setSkillLevel('ember_strike', 3);
        $evDmg3 = $this->combat->castSkill($dps, 'ember_strike', 'MONSTER', $dummy->getId(), $now + 5.0)['events'][0];
        $dmgR3Norm = $evDmg3['isCrit'] ? ((float) $evDmg3['amount'] / 1.65) : (float) $evDmg3['amount'];
        self::assertGreaterThan($dmgR1Norm * 1.25, $dmgR3Norm, 'Un sort offensif Rang 3 doit infliger nettement plus de dégâts qu\'au Rang 1.');

        // 2. Healing scaling: faham_infusion Rank 1 vs Rank 3 (+44%, normalizing crit multiplier)
        $tank->setCurrentHp(50);
        $evHeal1 = $this->combat->castSkill($heal, 'faham_infusion', 'PLAYER', $tank->getId(), $now + 10.0)['events'][0];
        $healR1Norm = $evHeal1['isCrit'] ? ((float) $evHeal1['amount'] / 1.5) : (float) $evHeal1['amount'];
        $heal->setSkillLevel('faham_infusion', 3);
        $tank->setCurrentHp(50);
        $evHeal3 = $this->combat->castSkill($heal, 'faham_infusion', 'PLAYER', $tank->getId(), $now + 15.0)['events'][0];
        $healR3Norm = $evHeal3['isCrit'] ? ((float) $evHeal3['amount'] / 1.5) : (float) $evHeal3['amount'];
        self::assertGreaterThan($healR1Norm * 1.35, $healR3Norm, 'Un soin Rang 3 doit soigner nettement plus qu\'au Rang 1.');

        // 3. Buff scaling: basalt_skin Rank 1 (40%) -> Rank 3 (48%)
        $buffR1 = (int) $this->combat->castSkill($tank, 'basalt_skin', null, null, $now + 20.0)['events'][0]['amount'];
        self::assertSame(40, $buffR1);
        $tank->setSkillLevel('basalt_skin', 3);
        $buffR3 = (int) $this->combat->castSkill($tank, 'basalt_skin', null, null, $now + 40.0)['events'][0]['amount'];
        self::assertSame(48, $buffR3);
    }

    public function testAutoAttackScalesOnStrForDpsVitForTankAndIntForHeal(): void
    {
        mt_srand(974);
        $now = 12000.0;
        $user = (new User())->setUsername('aa_scaler')->setEmail('aa@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $hub = WorldCatalog::zones()['lagon_ouest'];
        $hx = $hub['respawnX'];
        $hz = $hub['respawnZ'];

        $dps = (new Character($user, 'StrDps', CharacterClass::DPS))->setLevel(5)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz);
        $tank = (new Character($user, 'VitTank', CharacterClass::TANK))->setLevel(5)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz);
        $heal = (new Character($user, 'IntHeal', CharacterClass::HEAL))->setLevel(5)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz);

        foreach ([$dps, $tank, $heal] as $c) {
            $s = $this->stats->compute($c, $now);
            $c->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($c);
        }

        $tpl = (new MonsterTemplate('aa_dummy', 'Cible Basalte'))
            ->setLevel(5)->setMaxHp(5000)->setDefense(10)->setXpReward(10);
        $this->em->persist($tpl);
        $dummy = new MonsterInstance($tpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz);
        $this->em->persist($dummy);
        $this->em->flush();

        $normAa = function (Character $actor, float $t) use ($dummy): float {
            $res = $this->combat->performAutoAttack($actor, 'MONSTER', $dummy->getId(), $t);
            self::assertTrue($res['ok']);
            $ev = $res['events'][0];

            return $ev['isCrit'] ? ((float) $ev['amount'] / 1.5) : (float) $ev['amount'];
        };

        // 1. DPS auto-attack scales strictly on STR (VIT untouched)
        $dpsBase = $normAa($dps, $now);
        $dps->setVitality($dps->getVitality() + 30);
        $dpsWithVit = $normAa($dps, $now + 2.0);
        self::assertLessThan($dpsBase * 1.25, $dpsWithVit, 'La VIT ne doit pas augmenter l\'auto-attaque du DPS mêlée.');
        $dps->setStrength($dps->getStrength() + 30);
        $dpsWithStr = $normAa($dps, $now + 4.0);
        self::assertGreaterThan($dpsBase * 1.5, $dpsWithStr, 'La STR doit nettement augmenter l\'auto-attaque du DPS mêlée.');

        // 2. TANK auto-attack scales strictly on VIT (STR & DEF untouched)
        $tankBase = $normAa($tank, $now + 6.0);
        $tank->setStrength($tank->getStrength() + 30)->setDefense($tank->getDefense() + 30);
        $tankWithStrDef = $normAa($tank, $now + 8.0);
        self::assertLessThan($tankBase * 1.25, $tankWithStrDef, 'STR/DEF ne doivent pas augmenter l\'auto-attaque du Tank.');
        $tank->setVitality($tank->getVitality() + 30);
        $tankWithVit = $normAa($tank, $now + 10.0);
        self::assertGreaterThan($tankBase * 1.5, $tankWithVit, 'La VIT doit nettement augmenter l\'auto-attaque du Tank.');

        // 3. HEAL auto-attack scales strictly on INT (STR untouched)
        $healBase = $normAa($heal, $now + 12.0);
        $heal->setStrength($heal->getStrength() + 30);
        $healWithStr = $normAa($heal, $now + 14.0);
        self::assertLessThan($healBase * 1.25, $healWithStr, 'La STR ne doit pas augmenter l\'auto-attaque du Heal.');
        $heal->setIntelligence($heal->getIntelligence() + 30);
        $healWithInt = $normAa($heal, $now + 16.0);
        self::assertGreaterThan($healBase * 1.5, $healWithInt, 'L\'INT doit nettement augmenter l\'auto-attaque du Heal.');
    }

    public function testDynamicMobRespawnAcceleratesWithZonePopulation(): void
    {
        // 1. Pure scaler: monotonic speedup, caps & floors across regular, elite & world boss templates
        $reg24 = (new MonsterTemplate('reg24', 'Tangue Test'))->setRespawnSeconds(24);
        $reg10 = (new MonsterTemplate('reg10', 'Crabe Rapide'))->setRespawnSeconds(10);
        $reg2 = (new MonsterTemplate('reg2', 'Micro Crabe'))->setRespawnSeconds(2);
        $elite42 = (new MonsterTemplate('elite42', 'Colosse Test'))->setRespawnSeconds(42)->setIsBoss(true);
        $wb180 = (new MonsterTemplate('wb180', 'Boss Dolomieu Test'))->setRespawnSeconds(180)->setIsBoss(true)->setIsWorldBoss(true);
        $wb120 = (new MonsterTemplate('wb120', 'Boss Rapide Test'))->setRespawnSeconds(120)->setIsBoss(true)->setIsWorldBoss(true);

        self::assertSame(1.0, MonsterRespawnScaler::computeSpeedMultiplier(1));
        self::assertSame(24.0, MonsterRespawnScaler::computeRespawnSeconds($reg24, 1));
        self::assertSame(2.0, MonsterRespawnScaler::computeSpeedMultiplier(5));
        self::assertSame(12.0, MonsterRespawnScaler::computeRespawnSeconds($reg24, 5));
        self::assertSame(3.25, MonsterRespawnScaler::computeSpeedMultiplier(10));
        self::assertSame(7.38, MonsterRespawnScaler::computeRespawnSeconds($reg24, 10));
        self::assertSame(4.0, MonsterRespawnScaler::computeSpeedMultiplier(13));
        self::assertSame(6.0, MonsterRespawnScaler::computeRespawnSeconds($reg24, 13));
        self::assertSame(4.0, MonsterRespawnScaler::computeSpeedMultiplier(25));
        self::assertSame(6.0, MonsterRespawnScaler::computeRespawnSeconds($reg24, 25));

        self::assertSame(4.0, MonsterRespawnScaler::computeRespawnSeconds($reg10, 13));
        self::assertSame(2.0, MonsterRespawnScaler::computeRespawnSeconds($reg2, 13));
        self::assertSame(12.0, MonsterRespawnScaler::computeRespawnSeconds($elite42, 13));

        self::assertSame(1.0, MonsterRespawnScaler::computeSpeedMultiplier(1, true));
        self::assertSame(180.0, MonsterRespawnScaler::computeRespawnSeconds($wb180, 1));
        self::assertSame(2.0, MonsterRespawnScaler::computeSpeedMultiplier(11, true));
        self::assertSame(90.0, MonsterRespawnScaler::computeRespawnSeconds($wb180, 11));
        self::assertSame(3.0, MonsterRespawnScaler::computeSpeedMultiplier(21, true));
        self::assertSame(60.0, MonsterRespawnScaler::computeRespawnSeconds($wb180, 21));
        self::assertSame(3.0, MonsterRespawnScaler::computeSpeedMultiplier(30, true));
        self::assertSame(60.0, MonsterRespawnScaler::computeRespawnSeconds($wb180, 30));
        self::assertSame(45.0, MonsterRespawnScaler::computeRespawnSeconds($wb120, 21));

        // 2. Solo zone (1 online player in lagon_ouest): 24s respawn
        $now = 20000.0;
        $lagon = (new Zone('lagon_ouest', 'Lagon'))->setLastTickAt($now - 5.0);
        $this->em->persist($lagon);

        $user = (new User())->setUsername('respawn_u')->setEmail('respawn@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $tpl = (new MonsterTemplate('respawn_mob', 'Crabe Chronométré'))
            ->setLevel(1)->setMaxHp(100)->setAttack(5)->setDefense(0)->setXpReward(10)->setRespawnSeconds(24);
        $this->em->persist($tpl);

        $heroSolo = (new Character($user, 'SoloTimer', CharacterClass::DPS))
            ->setLevel(3)->setZoneCode('lagon_ouest')->setPosX(0.0)->setPosY(12.0)->setPosZ(0.0)->setLastSeenAt($now);
        $hs = $this->stats->compute($heroSolo, $now);
        $heroSolo->setCurrentHp($hs['maxHp'])->setCurrentMp($hs['maxMp']);
        $this->em->persist($heroSolo);

        $m1 = (new MonsterInstance($tpl, 'lagon_ouest', 0.0, 12.0, 3.0))->setCurrentHp(5);
        $this->em->persist($m1);
        $this->em->flush();

        $kill1 = $this->combat->castSkill($heroSolo, 'ember_strike', 'MONSTER', $m1->getId(), $now);
        self::assertTrue($kill1['ok']);
        self::assertSame(MonsterInstance::STATE_DEAD, $m1->getState());
        self::assertEqualsWithDelta($now + 24.0, $m1->getRespawnAt(), 0.05);

        $heroSolo->setLastSeenAt($now + 13.0);
        $this->em->flush();
        $this->sim->maybeTickZone('lagon_ouest', $now + 13.0);
        self::assertSame(MonsterInstance::STATE_DEAD, $m1->getState());

        $heroSolo->setLastSeenAt($now + 24.5);
        $this->em->flush();
        $this->sim->maybeTickZone('lagon_ouest', $now + 24.5);
        self::assertSame(MonsterInstance::STATE_IDLE, $m1->getState());
        self::assertSame(100, $m1->getCurrentHp());

        // 3. Populated zone (5 players in lagon_ouest + 3 isolated players in salazie): 2.0x -> 12s respawn
        $t2 = 25000.0;
        $heroSolo->setLastSeenAt($t2)->setCurrentHp($hs['maxHp'])->setCurrentMp($hs['maxMp']);
        $lagonExtras = [];
        for ($i = 2; $i <= 5; ++$i) {
            $extra = (new Character($user, 'LagonP'.$i, CharacterClass::DPS))
                ->setLevel(3)->setZoneCode('lagon_ouest')->setPosX(10.0 * $i)->setPosY(12.0)->setPosZ(0.0)->setLastSeenAt($t2);
            $es = $this->stats->compute($extra, $t2);
            $extra->setCurrentHp($es['maxHp'])->setCurrentMp($es['maxMp']);
            $this->em->persist($extra);
            $lagonExtras[] = $extra;
        }
        for ($i = 1; $i <= 3; ++$i) {
            $sal = (new Character($user, 'SalazieP'.$i, CharacterClass::DPS))
                ->setLevel(12)->setZoneCode('salazie')->setPosX(500.0)->setPosY(12.0)->setPosZ(500.0)->setLastSeenAt($t2);
            $ss = $this->stats->compute($sal, $t2);
            $sal->setCurrentHp($ss['maxHp'])->setCurrentMp($ss['maxMp']);
            $this->em->persist($sal);
        }

        $m2 = (new MonsterInstance($tpl, 'lagon_ouest', 0.0, 12.0, 3.0))->setCurrentHp(5);
        $this->em->persist($m2);
        $this->em->flush();

        $kill2 = $this->combat->castSkill($heroSolo, 'ember_strike', 'MONSTER', $m2->getId(), $t2);
        self::assertTrue($kill2['ok']);
        self::assertSame(MonsterInstance::STATE_DEAD, $m2->getState());
        self::assertEqualsWithDelta($t2 + 12.0, $m2->getRespawnAt(), 0.05);

        $lagonPlayers = array_merge([$heroSolo], $lagonExtras);
        foreach ($lagonPlayers as $lp) {
            $lp->setLastSeenAt($t2 + 11.0);
        }
        $this->em->flush();
        $this->sim->maybeTickZone('lagon_ouest', $t2 + 11.0);
        self::assertSame(MonsterInstance::STATE_DEAD, $m2->getState());

        foreach ($lagonPlayers as $lp) {
            $lp->setLastSeenAt($t2 + 12.5);
        }
        $this->em->flush();
        $this->sim->maybeTickZone('lagon_ouest', $t2 + 12.5);
        self::assertSame(MonsterInstance::STATE_IDLE, $m2->getState());
        self::assertSame(100, $m2->getCurrentHp());

        // 4. Mid-wait population surge in cilaos: killed at 1 player (24s), then 9 more arrive at t3+2s (10 players -> 7.38s cap)
        $t3 = 30000.0;
        $cilaos = (new Zone('cilaos', 'Cilaos'))->setLastTickAt($t3 - 5.0);
        $this->em->persist($cilaos);

        $cilaosLead = (new Character($user, 'CilaosP1', CharacterClass::DPS))
            ->setLevel(30)->setZoneCode('cilaos')->setPosX(100.0)->setPosY(12.0)->setPosZ(100.0)->setLastSeenAt($t3);
        $cs = $this->stats->compute($cilaosLead, $t3);
        $cilaosLead->setCurrentHp($cs['maxHp'])->setCurrentMp($cs['maxMp']);
        $this->em->persist($cilaosLead);

        $m3 = (new MonsterInstance($tpl, 'cilaos', 103.0, 12.0, 100.0))->setCurrentHp(5);
        $this->em->persist($m3);
        $this->em->flush();

        $kill3 = $this->combat->castSkill($cilaosLead, 'ember_strike', 'MONSTER', $m3->getId(), $t3);
        self::assertTrue($kill3['ok']);
        self::assertSame(MonsterInstance::STATE_DEAD, $m3->getState());
        self::assertEqualsWithDelta($t3 + 24.0, $m3->getRespawnAt(), 0.05);

        $cilaosPlayers = [$cilaosLead];
        $cilaosLead->setLastSeenAt($t3 + 2.0);
        for ($i = 2; $i <= 10; ++$i) {
            $cp = (new Character($user, 'CilaosP'.$i, CharacterClass::DPS))
                ->setLevel(30)->setZoneCode('cilaos')->setPosX(100.0 + $i)->setPosY(12.0)->setPosZ(100.0)->setLastSeenAt($t3 + 2.0);
            $cps = $this->stats->compute($cp, $t3 + 2.0);
            $cp->setCurrentHp($cps['maxHp'])->setCurrentMp($cps['maxMp']);
            $this->em->persist($cp);
            $cilaosPlayers[] = $cp;
        }
        $this->em->flush();

        $this->sim->maybeTickZone('cilaos', $t3 + 2.0);
        self::assertSame(MonsterInstance::STATE_DEAD, $m3->getState());
        self::assertEqualsWithDelta($t3 + 9.38, $m3->getRespawnAt(), 0.05);

        // Subsequent tick at constant population must NOT double-count acceleration
        foreach ($cilaosPlayers as $cp) {
            $cp->setLastSeenAt($t3 + 4.0);
        }
        $this->em->flush();
        $this->sim->maybeTickZone('cilaos', $t3 + 4.0);
        self::assertSame(MonsterInstance::STATE_DEAD, $m3->getState());
        self::assertEqualsWithDelta($t3 + 9.38, $m3->getRespawnAt(), 0.05);

        foreach ($cilaosPlayers as $cp) {
            $cp->setLastSeenAt($t3 + 10.0);
        }
        $this->em->flush();
        $this->sim->maybeTickZone('cilaos', $t3 + 10.0);
        self::assertSame(MonsterInstance::STATE_IDLE, $m3->getState());
        self::assertSame(100, $m3->getCurrentHp());
    }

    public function testSocialMobFleesAtLowHpAndSummonsSameSpeciesCompatriot(): void
    {
        $now = 35000.0;
        $terrain = self::getContainer()->get(ReunionTerrainService::class);
        $salazie = WorldCatalog::zones()['salazie'];
        $origin = $terrain->findNearestWalkable($salazie['respawnX'], $salazie['respawnZ']);

        // Pick a guaranteed walkable 60m corridor direction
        $ux = 1.0;
        $uz = 0.0;
        for ($deg = 0; $deg < 360; $deg += 15) {
            $rad = deg2rad((float) $deg);
            $cx = cos($rad);
            $cz = sin($rad);
            $ok = true;
            for ($d = 2.0; $d <= 60.0; $d += 4.0) {
                if (!$terrain->isWalkable($origin['x'] + $cx * $d, $origin['z'] + $cz * $d)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $ux = $cx;
                $uz = $cz;
                break;
            }
        }

        $pt = static fn (float $dist): array => [
            'x' => $origin['x'] + $ux * $dist,
            'y' => $terrain->getHeight($origin['x'] + $ux * $dist, $origin['z'] + $uz * $dist),
            'z' => $origin['z'] + $uz * $dist,
        ];

        $zone = (new Zone('salazie', 'Salazie'))->setLastTickAt($now - 5.0);
        $this->em->persist($zone);

        $user = (new User())->setUsername('social_ai')->setEmail('social@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $dps = (new Character($user, 'SocialDps', CharacterClass::DPS))
            ->setLevel(10)->setZoneCode('salazie')
            ->setPosX($origin['x'])->setPosY($origin['y'])->setPosZ($origin['z'])
            ->setLastSeenAt($now);
        $tank = (new Character($user, 'SocialTank', CharacterClass::TANK))
            ->setLevel(10)->setZoneCode('salazie')
            ->setPosX($origin['x'])->setPosY($origin['y'])->setPosZ($origin['z'])
            ->setLastSeenAt($now);

        foreach ([$dps, $tank] as $hero) {
            $s = $this->stats->compute($hero, $now);
            $hero->setCurrentHp($s['maxHp'] + 5000)->setCurrentMp($s['maxMp']);
            $this->em->persist($hero);
        }

        $socialTpl = (new MonsterTemplate('social_bird', 'Tec-Tec Social'))
            ->setLevel(10)->setMaxHp(1000)->setAttack(5)->setDefense(0)->setXpReward(100)
            ->setMoveSpeed(12.0)->setAggressive(false)
            ->setSocial(true)->setFleeHpRatio(0.35)->setCallHelpRadius(80.0);
        $otherTpl = (new MonsterTemplate('other_boar', 'Sanglier Solitaire'))
            ->setLevel(10)->setMaxHp(1000)->setAttack(5)->setDefense(0)->setXpReward(100)
            ->setMoveSpeed(10.0)->setAggressive(false)
            ->setSocial(false)->setFleeHpRatio(0.0)->setCallHelpRadius(0.0);
        $this->em->persist($socialTpl);
        $this->em->persist($otherTpl);

        $pCaller = $pt(3.0);
        $pStranger = $pt(14.0);
        $pAllySame = $pt(30.0);
        $pThirdSame = $pt(58.0);

        $caller = (new MonsterInstance($socialTpl, 'salazie', $pCaller['x'], $pCaller['y'], $pCaller['z']))
            ->setCurrentHp(600)->setState(MonsterInstance::STATE_COMBAT);
        $caller->addThreat(1, 50.0); // placeholder replaced right after flush
        $strangerOther = new MonsterInstance($otherTpl, 'salazie', $pStranger['x'], $pStranger['y'], $pStranger['z']);
        $allySame = new MonsterInstance($socialTpl, 'salazie', $pAllySame['x'], $pAllySame['y'], $pAllySame['z']);
        $thirdSame = new MonsterInstance($socialTpl, 'salazie', $pThirdSame['x'], $pThirdSame['y'], $pThirdSame['z']);

        foreach ([$caller, $strangerOther, $allySame, $thirdSame] as $m) {
            $this->em->persist($m);
        }
        $this->em->flush();

        $caller->setThreatTable([(string) $dps->getId() => 50.0]);
        $this->em->flush();

        // 1. Above 35% HP -> stays in COMBAT, does not flee
        $this->sim->maybeTickZone('salazie', $now + 1.5);
        self::assertSame(MonsterInstance::STATE_COMBAT, $caller->getState());
        self::assertSame(MonsterInstance::STATE_IDLE, $allySame->getState());
        self::assertSame(MonsterInstance::STATE_IDLE, $strangerOther->getState());

        // 2. Drops to <= 35% HP -> enters FLEEING toward same-species allySame (ignoring closer other_boar)
        $caller->setCurrentHp(300);
        $dps->setLastSeenAt($now + 3.0);
        $tank->setLastSeenAt($now + 3.0);
        $this->em->flush();

        $distBeforeFlee = hypot($allySame->getPosX() - $caller->getPosX(), $allySame->getPosZ() - $caller->getPosZ());
        $this->sim->maybeTickZone('salazie', $now + 3.0);
        $distAfterFlee = hypot($allySame->getPosX() - $caller->getPosX(), $allySame->getPosZ() - $caller->getPosZ());

        self::assertSame(MonsterInstance::STATE_FLEEING, $caller->getState());
        self::assertSame($allySame->getId(), $caller->getHelpTargetMonsterId());
        self::assertLessThan($distBeforeFlee, $distAfterFlee, 'Le mob social doit courir vers son compatriote.');

        $fleeEvents = $this->em->createQuery('SELECT e FROM App\Entity\CombatEvent e WHERE e.eventType = :t AND e.sourceId = :id')
            ->setParameter('t', 'FLEE')
            ->setParameter('id', $caller->getId())
            ->getResult();
        self::assertNotEmpty($fleeEvents, 'Un événement FLEE doit être émis.');

        // 3. Hitting a fleeing mob with non-lethal damage preserves STATE_FLEEING
        $dps->setPosX($caller->getPosX() - $ux * 2.0)->setPosZ($caller->getPosZ() - $uz * 2.0)->setLastSeenAt($now + 4.0);
        $this->em->flush();
        $aa = $this->combat->performAutoAttack($dps, 'MONSTER', $caller->getId(), $now + 4.0);
        self::assertTrue($aa['ok']);
        self::assertSame(MonsterInstance::STATE_FLEEING, $caller->getState());

        // 4. Reaches allySame -> rallies allySame into COMBAT, keeps other species & far 3rd ally IDLE, emits CALL_HELP
        $dps->setLastSeenAt($now + 6.0);
        $tank->setLastSeenAt($now + 6.0);
        $this->em->flush();
        $this->sim->maybeTickZone('salazie', $now + 6.0);

        self::assertSame(MonsterInstance::STATE_COMBAT, $caller->getState());
        self::assertTrue($caller->hasCalledHelp());
        self::assertSame(MonsterInstance::STATE_COMBAT, $allySame->getState());
        self::assertTrue($allySame->hasCalledHelp());
        self::assertSame($dps->getId(), $allySame->getTopThreatCharacterId($now + 6.0));
        self::assertSame(MonsterInstance::STATE_IDLE, $strangerOther->getState(), 'Une autre espèce ne doit pas être ralliée.');
        self::assertSame(MonsterInstance::STATE_IDLE, $thirdSame->getState());

        $helpEvents = $this->em->createQuery('SELECT e FROM App\Entity\CombatEvent e WHERE e.eventType = :t AND e.sourceId = :id')
            ->setParameter('t', 'CALL_HELP')
            ->setParameter('id', $caller->getId())
            ->getResult();
        self::assertNotEmpty($helpEvents, 'Un événement CALL_HELP doit être émis.');

        // 5. No infinite chain-fleeing: summoned allySame at 20% HP next to 3rd same-species mob stays COMBAT
        $allySame->setCurrentHp(200);
        $dps->setLastSeenAt($now + 8.0);
        $tank->setLastSeenAt($now + 8.0);
        $this->em->flush();
        $this->sim->maybeTickZone('salazie', $now + 8.0);
        self::assertSame(MonsterInstance::STATE_COMBAT, $allySame->getState());
        self::assertSame(MonsterInstance::STATE_IDLE, $thirdSame->getState());

        // 6. Tank Taunt interrupts FLEEING back into COMBAT and prevents re-fleeing while taunted
        $pFlee2 = $pt(18.0);
        $fleeable2 = (new MonsterInstance($socialTpl, 'salazie', $pFlee2['x'], $pFlee2['y'], $pFlee2['z']))
            ->setCurrentHp(250)->setState(MonsterInstance::STATE_COMBAT);
        $fleeable2->setThreatTable([(string) $dps->getId() => 50.0]);
        $this->em->persist($fleeable2);
        $this->em->flush();

        $dps->setLastSeenAt($now + 10.0);
        $tank->setLastSeenAt($now + 10.0);
        $this->em->flush();
        $this->sim->maybeTickZone('salazie', $now + 10.0);
        self::assertSame(MonsterInstance::STATE_FLEEING, $fleeable2->getState());

        $tank->setPosX($fleeable2->getPosX() - $ux * 3.0)->setPosZ($fleeable2->getPosZ() - $uz * 3.0)->setLastSeenAt($now + 11.0);
        $this->em->flush();
        $tauntRes = $this->combat->castSkill($tank, 'piton_taunt', 'MONSTER', $fleeable2->getId(), $now + 11.0);
        self::assertTrue($tauntRes['ok']);
        self::assertSame(MonsterInstance::STATE_COMBAT, $fleeable2->getState());
        self::assertNull($fleeable2->getHelpTargetMonsterId());

        $dps->setLastSeenAt($now + 12.5);
        $tank->setLastSeenAt($now + 12.5);
        $this->em->flush();
        $this->sim->maybeTickZone('salazie', $now + 12.5);
        self::assertSame(MonsterInstance::STATE_COMBAT, $fleeable2->getState(), 'Sous Provocation, le mob ne doit pas repartir fuir.');

        // 7a. Non-social mob at 15% HP next to same-species ally does NOT flee
        $pOther2 = $pt(16.0);
        $otherAlly = new MonsterInstance($otherTpl, 'salazie', $pOther2['x'], $pOther2['y'], $pOther2['z']);
        $strangerOther->setCurrentHp(150)->setState(MonsterInstance::STATE_COMBAT)
            ->setThreatTable([(string) $dps->getId() => 50.0]);
        $this->em->persist($otherAlly);
        $this->em->flush();

        $dps->setLastSeenAt($now + 14.5);
        $tank->setLastSeenAt($now + 14.5);
        $this->em->flush();
        $this->sim->maybeTickZone('salazie', $now + 14.5);
        self::assertSame(MonsterInstance::STATE_COMBAT, $strangerOther->getState());
        self::assertSame(MonsterInstance::STATE_IDLE, $otherAlly->getState());

        // 7b. Isolated social mob with no idle compatriot within callHelpRadius fights instead of fleeing
        $isolated = (new MonsterInstance($socialTpl, 'salazie', $origin['x'] - 250.0, $origin['y'], $origin['z'] - 250.0))
            ->setCurrentHp(150)->setState(MonsterInstance::STATE_COMBAT);
        $isoHero = (new Character($user, 'IsoHero', CharacterClass::DPS))
            ->setLevel(10)->setZoneCode('salazie')
            ->setPosX($origin['x'] - 247.0)->setPosY($origin['y'])->setPosZ($origin['z'] - 250.0)
            ->setLastSeenAt($now + 16.5);
        $is = $this->stats->compute($isoHero, $now + 16.5);
        $isoHero->setCurrentHp($is['maxHp'])->setCurrentMp($is['maxMp']);
        $this->em->persist($isoHero);
        $this->em->persist($isolated);
        $this->em->flush();

        $isolated->setThreatTable([(string) $isoHero->getId() => 50.0]);
        $dps->setLastSeenAt($now + 16.5);
        $tank->setLastSeenAt($now + 16.5);
        $this->em->flush();
        $this->sim->maybeTickZone('salazie', $now + 16.5);
        self::assertSame(MonsterInstance::STATE_COMBAT, $isolated->getState());
    }

    public function testNightMakesAllMobsMoreAggressiveFromAfarExceptInsideTownsAndCheckpoints(): void
    {
        $zones = WorldCatalog::zones();
        $lagon = $zones['lagon_ouest'];
        $cilaos = $zones['cilaos'];

        // 1. Safe sanctuary boundary helper checks (town <= 55m, checkpoint <= 48m, wild false)
        self::assertTrue(WorldCatalog::isInSafeSanctuary($lagon['respawnX'], $lagon['respawnZ']));
        self::assertTrue(WorldCatalog::isInSafeSanctuary($lagon['respawnX'] + 54.0, $lagon['respawnZ']));
        self::assertFalse(WorldCatalog::isInSafeSanctuary($lagon['respawnX'] + 65.0, $lagon['respawnZ']));

        self::assertTrue(WorldCatalog::isInSafeSanctuary($cilaos['respawnX'], $cilaos['respawnZ']));
        self::assertTrue(WorldCatalog::isInSafeSanctuary($cilaos['respawnX'] + 47.0, $cilaos['respawnZ']));
        self::assertFalse(WorldCatalog::isInSafeSanctuary($cilaos['respawnX'] + 55.0, $cilaos['respawnZ']));

        $passiveTpl = (new MonsterTemplate('night_passive', 'Crabe Nocturne'))
            ->setLevel(1)->setMaxHp(200)->setAttack(8)->setDefense(2)->setXpReward(25)
            ->setAggroRadius(10.0)->setAggressive(false);
        $aggressiveTpl = (new MonsterTemplate('night_hunter', 'Guêpe Chasseresse'))
            ->setLevel(5)->setMaxHp(260)->setAttack(12)->setDefense(4)->setXpReward(40)
            ->setAggroRadius(15.0)->setAggressive(true);
        $this->em->persist($passiveTpl);
        $this->em->persist($aggressiveTpl);

        // 2. Pure aggro & effective radius checks (day vs night)
        self::assertFalse(WorldSimulationService::isMonsterAggressiveAt($passiveTpl, false));
        self::assertTrue(WorldSimulationService::isMonsterAggressiveAt($passiveTpl, true));
        self::assertTrue(WorldSimulationService::isMonsterAggressiveAt($aggressiveTpl, false));
        self::assertTrue(WorldSimulationService::isMonsterAggressiveAt($aggressiveTpl, true));

        self::assertSame(10.0, WorldSimulationService::computeEffectiveAggroRadius($passiveTpl, false));
        self::assertSame(24.0, WorldSimulationService::computeEffectiveAggroRadius($passiveTpl, true));
        self::assertSame(15.0, WorldSimulationService::computeEffectiveAggroRadius($aggressiveTpl, false));
        self::assertSame(30.0, WorldSimulationService::computeEffectiveAggroRadius($aggressiveTpl, true));

        // 3. Setup wild & sanctuary actors during frozen DAY (12:30)
        $now = 50000.0;
        $atmoService = self::getContainer()->get(WorldAtmosphereService::class);
        $dayAtmo = $atmoService->applyChanges(null, false, 12.5, false, true, $now);
        self::assertFalse($dayAtmo['isNight']);

        $lagonZone = (new Zone('lagon_ouest', 'Lagon'))->setLastTickAt($now - 5.0);
        $cilaosZone = (new Zone('cilaos', 'Cilaos'))->setLastTickAt($now - 5.0);
        $this->em->persist($lagonZone);
        $this->em->persist($cilaosZone);

        $user = (new User())->setUsername('night_tester')->setEmail('night@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $wildX = $lagon['respawnX'] + 120.0;
        $wildZ = $lagon['respawnZ'];
        self::assertFalse(WorldCatalog::isInSafeSanctuary($wildX, $wildZ));

        $wildPlayer = (new Character($user, 'Explorateur', CharacterClass::DPS))
            ->setLevel(10)->setZoneCode('lagon_ouest')
            ->setPosX($wildX)->setPosY(12.0)->setPosZ($wildZ)
            ->setLastSeenAt($now);
        $townPlayer = (new Character($user, 'Citadin', CharacterClass::DPS))
            ->setLevel(10)->setZoneCode('lagon_ouest')
            ->setPosX($lagon['respawnX'])->setPosY(12.0)->setPosZ($lagon['respawnZ'])
            ->setLastSeenAt($now);
        $checkpointPlayer = (new Character($user, 'VoyageurCilaos', CharacterClass::DPS))
            ->setLevel(30)->setZoneCode('cilaos')
            ->setPosX($cilaos['respawnX'] + 20.0)->setPosY(60.0)->setPosZ($cilaos['respawnZ'])
            ->setLastSeenAt($now);

        foreach ([$wildPlayer, $townPlayer, $checkpointPlayer] as $ch) {
            $s = $this->stats->compute($ch, $now);
            $ch->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($ch);
        }

        // Wild mobs placed 22m from wildPlayer (outside daytime 10m/15m aggro, inside nighttime 24m/30m)
        $wildPassive = new MonsterInstance($passiveTpl, 'lagon_ouest', $wildX + 22.0, 12.0, $wildZ);
        $wildAggressive = new MonsterInstance($aggressiveTpl, 'lagon_ouest', $wildX - 22.0, 12.0, $wildZ);

        // Aggressive mobs placed 12m from Saint-Gilles town player & Cilaos checkpoint player
        $townGuardTarget = new MonsterInstance($aggressiveTpl, 'lagon_ouest', $lagon['respawnX'] + 12.0, 12.0, $lagon['respawnZ']);
        $cilaosGuardTarget = new MonsterInstance($aggressiveTpl, 'cilaos', $cilaos['respawnX'] + 32.0, 60.0, $cilaos['respawnZ']);

        foreach ([$wildPassive, $wildAggressive, $townGuardTarget, $cilaosGuardTarget] as $m) {
            $this->em->persist($m);
        }
        $this->em->flush();

        // Day tick: neither passive nor aggressive mob pulls wildPlayer at 22m
        $this->sim->maybeTickZone('lagon_ouest', $now);
        self::assertSame(MonsterInstance::STATE_IDLE, $wildPassive->getState());
        self::assertSame(MonsterInstance::STATE_IDLE, $wildAggressive->getState());
        self::assertSame(MonsterInstance::STATE_IDLE, $townGuardTarget->getState());

        // 4. Switch server clock to frozen NIGHT (23:00)
        $tNight = $now + 3.0;
        $nightAtmo = $atmoService->applyChanges(null, false, 23.0, false, true, $tNight);
        self::assertTrue($nightAtmo['isNight']);

        $wildPlayer->setLastSeenAt($tNight);
        $townPlayer->setLastSeenAt($tNight);
        $checkpointPlayer->setLastSeenAt($tNight);
        $this->em->flush();

        $this->sim->maybeTickZone('lagon_ouest', $tNight);
        $this->sim->maybeTickZone('cilaos', $tNight);

        // Wild mobs BOTH auto-aggro wildPlayer from 22m at night!
        self::assertSame(MonsterInstance::STATE_COMBAT, $wildPassive->getState(), 'Un mob passif doit devenir agressif la nuit.');
        self::assertSame($wildPlayer->getId(), $wildPassive->getTopThreatCharacterId($tNight));
        self::assertSame(MonsterInstance::STATE_COMBAT, $wildAggressive->getState(), 'Un mob agressif doit aggro de plus loin la nuit (22m < 30m).');
        self::assertSame($wildPlayer->getId(), $wildAggressive->getTopThreatCharacterId($tNight));

        // Players inside town (Saint-Gilles) or near a checkpoint (Cilaos) remain completely safe from auto-aggro!
        self::assertSame(MonsterInstance::STATE_IDLE, $townGuardTarget->getState(), 'Un joueur en ville ne doit pas être attaqué automatiquement la nuit.');
        self::assertSame(MonsterInstance::STATE_IDLE, $cilaosGuardTarget->getState(), 'Un joueur proche d\'un checkpoint ne doit pas être attaqué automatiquement la nuit.');
    }

    public function testAttacksAndSkillsAutomaticallyTargetNearestAliveMonster(): void
    {
        $now = 60000.0;
        $user = (new User())->setUsername('autotarget')->setEmail('auto@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $hub = WorldCatalog::zones()['lagon_ouest'];
        $hx = $hub['respawnX'];
        $hz = $hub['respawnZ'];

        $dps = (new Character($user, 'AutoDps', CharacterClass::DPS))
            ->setLevel(10)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now);
        $tank = (new Character($user, 'AutoTank', CharacterClass::TANK))
            ->setLevel(10)->setZoneCode('lagon_ouest')->setPosX($hx)->setPosY(12.0)->setPosZ($hz)->setLastSeenAt($now);

        foreach ([$dps, $tank] as $c) {
            $s = $this->stats->compute($c, $now);
            $c->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($c);
        }

        $tpl = (new MonsterTemplate('auto_mob', 'Crabe Cible'))
            ->setLevel(5)->setMaxHp(500)->setAttack(10)->setDefense(4)->setXpReward(40);
        $this->em->persist($tpl);

        $farOutMob = new MonsterInstance($tpl, 'lagon_ouest', $hx + 30.0, 12.0, $hz);
        $midMob = new MonsterInstance($tpl, 'lagon_ouest', $hx + 6.5, 12.0, $hz);
        $nearestMob = new MonsterInstance($tpl, 'lagon_ouest', $hx + 2.2, 12.0, $hz);
        $this->em->persist($farOutMob);
        $this->em->persist($midMob);
        $this->em->persist($nearestMob);
        $this->em->flush();

        // 1. Auto-attack with no target selected automatically hits the nearest alive monster
        $aaRes = $this->combat->performAutoAttack($dps, null, null, $now);
        self::assertTrue($aaRes['ok']);
        self::assertSame('MONSTER', $aaRes['targetType'] ?? null);
        self::assertSame($nearestMob->getId(), $aaRes['targetId'] ?? null);
        self::assertLessThan(500, $nearestMob->getCurrentHp());
        self::assertSame(500, $midMob->getCurrentHp());

        // 2. Offensive skill with a distant out-of-range monster selected automatically switches to the nearest alive monster
        $hpBeforeSkill = $nearestMob->getCurrentHp();
        $skillRes = $this->combat->castSkill($dps, 'ember_strike', 'MONSTER', $farOutMob->getId(), $now + 2.0);
        self::assertTrue($skillRes['ok']);
        self::assertSame('MONSTER', $skillRes['targetType'] ?? null);
        self::assertSame($nearestMob->getId(), $skillRes['targetId'] ?? null);
        self::assertLessThan($hpBeforeSkill, $nearestMob->getCurrentHp());
        self::assertSame(500, $farOutMob->getCurrentHp());

        // 3. Tank SELF_AOE (seismic_slam) also reports the nearest hit monster as targetId
        $aoeRes = $this->combat->castSkill($tank, 'seismic_slam', null, null, $now + 4.0);
        self::assertTrue($aoeRes['ok']);
        self::assertSame('MONSTER', $aoeRes['targetType'] ?? null);
        self::assertSame($nearestMob->getId(), $aoeRes['targetId'] ?? null);
    }
}
