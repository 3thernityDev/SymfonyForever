<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\CharacterQuest;
use App\Entity\MonsterInstance;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\NpcRegistry;
use App\Repository\MonsterTemplateRepository;
use App\Service\Game\CombatService;
use App\Service\Game\QuestService;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class QuestSystemTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private QuestService $quests;
    private CombatService $combat;
    private NpcRegistry $npcs;
    private ReunionTerrainService $terrain;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->quests = $c->get(QuestService::class);
        $this->combat = $c->get(CombatService::class);
        $this->npcs = $c->get(NpcRegistry::class);
        $this->terrain = $c->get(ReunionTerrainService::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $c->get(WorldSeederService::class)->seed();
    }

    public function testProgressiveQuestLifecycleKillsAndZoneRelay(): void
    {
        $now = microtime(true);
        $user = (new User())->setUsername('quester')->setEmail('q@reunion.re')->setPassword('hash');
        $this->em->persist($user);

        $tiJean = $this->npcs->get('gramoun_tijean');
        self::assertNotNull($tiJean);
        $npcPos = $this->npcs->resolvedPosition($tiJean, $this->terrain);

        $hero = (new Character($user, 'Explorateur974', CharacterClass::DPS))
            ->setPosX(-200.0)->setPosY(40.0)->setPosZ(100.0)->setZoneCode('cilaos')->setLastSeenAt($now);
        $stats = self::getContainer()->get(StatCalculator::class)->compute($hero);
        $hero->setCurrentHp($stats['maxHp'])->setCurrentMp($stats['maxMp']);
        $this->em->persist($hero);
        $this->em->flush();

        // 1. Initial guidance points player to Gramoun Ti-Jean for Q1
        $state0 = $this->quests->buildQuestState($hero);
        self::assertSame('AVAILABLE', $state0['npcMarkers']['gramoun_tijean']);
        self::assertSame('TALK_GIVER', $state0['guidance']['mode']);
        self::assertSame('q1_lagon_crabs', $state0['availableCodes'][0]);

        // 2. Too far from NPC -> rejected
        $farRes = $this->quests->acceptQuest($hero, 'q1_lagon_crabs');
        self::assertFalse($farRes['ok']);

        // 3. Walk up to Gramoun Ti-Jean -> accept Q1 succeeds
        $hero->setPosX($npcPos['x'] + 2.0)->setPosY($npcPos['y'])->setPosZ($npcPos['z'])->setZoneCode('lagon_ouest');
        $this->em->flush();

        $acc1 = $this->quests->acceptQuest($hero, 'q1_lagon_crabs');
        self::assertTrue($acc1['ok']);
        self::assertSame(CharacterQuest::STATUS_ACTIVE, $acc1['quest']['status']);

        // 4. Kill 3 crabe_recif via CombatService -> Q1 progresses 1/3, 2/3, 3/3 (READY)
        $crabTpl = self::getContainer()->get(MonsterTemplateRepository::class)->findOneByCode('crabe_recif');
        self::assertNotNull($crabTpl);

        for ($i = 0; $i < 3; ++$i) {
            $m = new MonsterInstance($crabTpl, 'lagon_ouest', $hero->getPosX() + 2.0, $hero->getPosY(), $hero->getPosZ());
            $m->setCurrentHp(5);
            $this->em->persist($m);
            $this->em->flush();

            $cast = $this->combat->castSkill($hero, 'ember_strike', 'MONSTER', $m->getId(), $now + ($i * 3.0));
            self::assertTrue($cast['ok']);
        }

        $stateAfterKills = $this->quests->buildQuestState($hero);
        self::assertSame('TURN_IN', $stateAfterKills['npcMarkers']['gramoun_tijean']);
        self::assertSame(CharacterQuest::STATUS_READY, $stateAfterKills['active'][0]['status']);
        self::assertSame(3, $stateAfterKills['active'][0]['progress']);

        // 5. Turn in Q1 -> grants reward XP, completes Q1, unlocks Q2
        $done1 = $this->quests->completeQuest($hero, 'q1_lagon_crabs', $now + 12.0);
        self::assertTrue($done1['ok']);
        self::assertSame(95, $done1['rewardXp']);
        self::assertGreaterThanOrEqual(2, $hero->getLevel());

        $stateAfterQ1 = $this->quests->buildQuestState($hero);
        self::assertContains('q1_lagon_crabs', $stateAfterQ1['completedCodes']);
        self::assertContains('q2_lagon_tangues', $stateAfterQ1['availableCodes']);

        // 6. Simulate Q2 completion and test Q3 REACH_ZONE relay to Sœur Rosalie in Salazie
        $q2Row = (new CharacterQuest($hero, 'q2_lagon_tangues'))->setStatus(CharacterQuest::STATUS_COMPLETED)->setProgress(4);
        $this->em->persist($q2Row);
        $this->em->flush();

        $acc3 = $this->quests->acceptQuest($hero, 'q3_route_salazie');
        self::assertTrue($acc3['ok']);
        self::assertSame(CharacterQuest::STATUS_ACTIVE, $acc3['quest']['status']);

        // Arriving in Salazie marks Q3 READY and puts TURN_IN marker on Sœur Rosalie
        $rosalie = $this->npcs->get('soeur_rosalie');
        self::assertNotNull($rosalie);
        $rPos = $this->npcs->resolvedPosition($rosalie, $this->terrain);
        $hero->setPosX($rPos['x'] + 2.0)->setPosY($rPos['y'])->setPosZ($rPos['z'])->setZoneCode('salazie');
        $this->quests->onZoneEntered($hero, 'salazie');
        $this->em->flush();

        $stateSalazie = $this->quests->buildQuestState($hero);
        self::assertSame('TURN_IN', $stateSalazie['npcMarkers']['soeur_rosalie']);
        $done3 = $this->quests->completeQuest($hero, 'q3_route_salazie', $now + 20.0);
        self::assertTrue($done3['ok']);
        self::assertSame(180, $done3['rewardXp']);
    }
}
