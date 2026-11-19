<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\Party;
use App\Entity\User;
use App\Entity\Zone;
use App\Game\CharacterClass;
use App\Repository\MonsterTemplateRepository;
use App\Service\Game\CombatService;
use App\Service\Game\StatCalculator;
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

        $tank = (new Character($user, 'Tank974', CharacterClass::TANK))
            ->setLevel(4)->setPosX(-585.0)->setPosY(12.0)->setPosZ(-95.0)->setLastSeenAt($now)->setParty($party);
        $dps = (new Character($user, 'Dps974', CharacterClass::DPS))
            ->setLevel(4)->setPosX(-583.0)->setPosY(12.0)->setPosZ(-95.0)->setLastSeenAt($now)->setParty($party);
        $heal = (new Character($user, 'Heal974', CharacterClass::HEAL))
            ->setLevel(4)->setPosX(-588.0)->setPosY(12.0)->setPosZ(-95.0)->setLastSeenAt($now)->setParty($party);

        foreach ([$tank, $dps, $heal] as $ch) {
            $s = $this->stats->compute($ch);
            $ch->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($ch);
        }

        $tpl = (new MonsterTemplate('test_crab', 'Crabe Géant'))
            ->setLevel(3)->setMaxHp(500)->setAttack(20)->setDefense(5)->setXpReward(160);
        $this->em->persist($tpl);

        $monster = new MonsterInstance($tpl, 'lagon_ouest', -582.0, 12.0, -95.0);
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

        // 1. Verify class resource pools & insufficient-resource rejection
        $tank = (new Character($user, 'RageTank', CharacterClass::TANK))
            ->setLevel(1)->setZoneCode('lagon_ouest')->setPosX(-585.0)->setPosY(12.0)->setPosZ(-95.0)->setLastSeenAt($now);
        $dps = (new Character($user, 'EnergyDps', CharacterClass::DPS))
            ->setLevel(1)->setZoneCode('lagon_ouest')->setPosX(-585.0)->setPosY(12.0)->setPosZ(-95.0)->setLastSeenAt($now);
        $heal = (new Character($user, 'ManaHeal', CharacterClass::HEAL))
            ->setLevel(1)->setZoneCode('lagon_ouest')->setPosX(-585.0)->setPosY(12.0)->setPosZ(-95.0)->setLastSeenAt($now);

        self::assertSame(100, $this->stats->compute($tank)['maxMp']);
        self::assertSame(100, $this->stats->compute($dps)['maxMp']);
        self::assertSame(185, $this->stats->compute($heal)['maxMp']);

        foreach ([$tank, $dps, $heal] as $c) {
            $s = $this->stats->compute($c);
            $c->setCurrentHp($s['maxHp'])->setCurrentMp(0);
            $this->em->persist($c);
        }

        $dummy = new MonsterInstance($crabTpl, 'lagon_ouest', -582.0, 12.0, -95.0);
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
        $aaMob = new MonsterInstance($crabTpl, 'lagon_ouest', -582.0, 12.0, -95.0);
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
        $aaTankMob = new MonsterInstance($crabTpl, 'lagon_ouest', -582.0, 12.0, -95.0);
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
            $skillMob = new MonsterInstance($crabTpl, 'lagon_ouest', -582.0, 12.0, -95.0);
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

        $dps = (new Character($user, 'RankDps', CharacterClass::DPS))
            ->setLevel(6)->setZoneCode('lagon_ouest')->setPosX(-585.0)->setPosY(12.0)->setPosZ(-95.0);
        $heal = (new Character($user, 'RankHeal', CharacterClass::HEAL))
            ->setLevel(6)->setZoneCode('lagon_ouest')->setPosX(-585.0)->setPosY(12.0)->setPosZ(-95.0);
        $tank = (new Character($user, 'RankTank', CharacterClass::TANK))
            ->setLevel(6)->setZoneCode('lagon_ouest')->setPosX(-585.0)->setPosY(12.0)->setPosZ(-95.0);

        foreach ([$dps, $heal, $tank] as $c) {
            $s = $this->stats->compute($c);
            $c->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
            $this->em->persist($c);
        }

        $tpl = (new MonsterTemplate('dummy_scale', 'Mannequin Basalte'))
            ->setLevel(6)->setMaxHp(5000)->setDefense(20)->setXpReward(10);
        $this->em->persist($tpl);
        $dummy = new MonsterInstance($tpl, 'lagon_ouest', -582.0, 12.0, -95.0);
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
}
