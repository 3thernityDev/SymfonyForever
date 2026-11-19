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
use App\Service\Game\CombatService;
use App\Service\Game\StatCalculator;
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
}
