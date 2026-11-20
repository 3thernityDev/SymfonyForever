<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\ChatMessage;
use App\Entity\MonsterInstance;
use App\Entity\Party;
use App\Entity\User;
use App\Game\BotProfileCatalog;
use App\Game\CharacterClass;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\MonsterTemplateRepository;
use App\Service\Game\BotSimulationService;
use App\Service\Game\LevelingService;
use App\Service\Game\StatCalculator;
use App\Service\Game\WorldSeederService;
use App\Service\Game\WorldSimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BotPlayersTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BotSimulationService $botSim;
    private WorldSimulationService $worldSim;
    private LevelingService $leveling;
    private StatCalculator $stats;
    private CharacterRepository $charRepo;
    private ChatMessageRepository $chatRepo;
    private MonsterTemplateRepository $tplRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->botSim = $c->get(BotSimulationService::class);
        $this->worldSim = $c->get(WorldSimulationService::class);
        $this->leveling = $c->get(LevelingService::class);
        $this->stats = $c->get(StatCalculator::class);
        $this->charRepo = $c->get(CharacterRepository::class);
        $this->chatRepo = $c->get(ChatMessageRepository::class);
        $this->tplRepo = $c->get(MonsterTemplateRepository::class);

        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $c->get(WorldSeederService::class)->seed();
    }

    public function testMaintainsTenOnlinePlayersWithReunionPseudonymsAndZoneDistribution(): void
    {
        $now = 1000.0;
        $lagon = WorldCatalog::zones()['lagon_ouest'];
        $human = $this->createHuman('Hero974', CharacterClass::DPS, 'lagon_ouest', $lagon['respawnX'], $lagon['respawnZ'], $now);
        $this->em->flush();

        $ran = $this->botSim->tick('lagon_ouest', $now, false);
        self::assertTrue($ran);

        // 1 online human + 9 AI bots = 10 connected players
        self::assertSame(10, $this->charRepo->countOnline($now));

        $bots = $this->charRepo->findAliveBots();
        self::assertCount(9, $bots);

        $validPool = BotProfileCatalog::pseudonyms();
        $seenNames = [];
        $classes = [];
        $lagonBots = 0;
        $otherZoneBots = 0;

        foreach ($bots as $bot) {
            self::assertTrue($bot->isBot());
            self::assertContains($bot->getName(), $validPool);
            self::assertArrayNotHasKey($bot->getName(), $seenNames);
            $seenNames[$bot->getName()] = true;
            $classes[$bot->getClassCode()] = true;

            if ($bot->getZoneCode() === 'lagon_ouest') {
                ++$lagonBots;
            } else {
                ++$otherZoneBots;
            }
        }

        self::assertGreaterThanOrEqual(3, $lagonBots);
        self::assertGreaterThanOrEqual(4, $otherZoneBots);
        self::assertCount(3, $classes, 'Les 3 classes (TANK, DPS, HEAL) doivent être représentées parmi les bots.');
        self::assertSame('Hero974', $human->getName());
    }

    public function testBotAcceptsPartyInviteGreetsInFrenchAndJoinsLeaderZone(): void
    {
        $now = 2000.0;
        $salazie = WorldCatalog::zones()['salazie'];
        $leader = $this->createHuman('CapitaineSalazie', CharacterClass::TANK, 'salazie', $salazie['respawnX'], $salazie['respawnZ'], $now);
        $this->em->flush();

        $this->botSim->tick('salazie', $now, false);

        // Pick a bot currently in lagon_ouest (a different zone from salazie)
        $lagonBot = null;
        foreach ($this->charRepo->findAliveBots() as $b) {
            if ($b->getZoneCode() === 'lagon_ouest') {
                $lagonBot = $b;
                break;
            }
        }
        self::assertNotNull($lagonBot);

        $party = (new Party())
            ->setName('Groupe de '.$leader->getName())
            ->setLeaderCharacterId((int) $leader->getId());
        $this->em->persist($party);
        $leader->setParty($party);
        $this->em->flush();

        $lagonBot->setPendingPartyInviteId($party->getId());
        $this->botSim->onPartyInviteReceived($lagonBot, $now);
        $this->em->flush();

        // Advance simulation +1.5s -> bot accepts invite, greets in PARTY chat, and travels to salazie!
        $tAccept = $now + 1.5;
        $leader->setLastSeenAt($tAccept);
        $this->em->flush();

        $this->botSim->tick('salazie', $tAccept, false);

        $this->em->refresh($lagonBot);
        $this->em->refresh($party);
        self::assertSame($party->getId(), $lagonBot->getParty()?->getId());
        self::assertNull($lagonBot->getPendingPartyInviteId());
        self::assertSame('salazie', $lagonBot->getZoneCode());

        $partyMsgs = $this->chatRepo->findVisibleSince('salazie', $party->getId(), 0);
        $botGreetings = array_values(array_filter(
            $partyMsgs,
            static fn (ChatMessage $m): bool => $m->getChannel() === ChatMessage::CHANNEL_PARTY && $m->getSenderName() === $lagonBot->getName(),
        ));
        self::assertNotEmpty($botGreetings, 'Le bot doit envoyer un salut en français dans le chat du groupe.');
    }

    public function testBotRepliesInFrenchToPlayerChatWithoutBotLoops(): void
    {
        // 1. Reply delay averages ~2.3 seconds and stays bounded in [1.5s, 3.4s]
        $delays = [];
        $sampleText = 'Oté salut ! La forme ? Paré pou la chasse zordi !';
        for ($i = 0; $i < 40; ++$i) {
            $d = BotSimulationService::computeReplyDelay($sampleText);
            self::assertGreaterThanOrEqual(BotSimulationService::CHAT_REPLY_DELAY_MIN, $d);
            self::assertLessThanOrEqual(BotSimulationService::CHAT_REPLY_DELAY_MAX, $d);
            $delays[] = $d;
        }
        $avgDelay = array_sum($delays) / count($delays);
        self::assertGreaterThanOrEqual(1.9, $avgDelay);
        self::assertLessThanOrEqual(2.7, $avgDelay);

        $now = 3000.0;
        $lagon = WorldCatalog::zones()['lagon_ouest'];
        $human = $this->createHuman('Bavard974', CharacterClass::HEAL, 'lagon_ouest', $lagon['respawnX'], $lagon['respawnZ'], $now);
        $this->em->flush();

        $this->botSim->tick('lagon_ouest', $now, false);

        // 2. Player GLOBAL message schedules a delayed bot reply (must NOT be posted instantaneously)
        $globalMsg = new ChatMessage(ChatMessage::CHANNEL_GLOBAL, $human->getName(), $human->getClassCode(), 'Salut tout le monde !');
        $this->em->persist($globalMsg);
        $this->em->flush();

        $scheduledGlobal = $this->botSim->onPlayerChatMessage($human, $globalMsg, $now + 0.2);
        self::assertIsArray($scheduledGlobal);
        self::assertSame(ChatMessage::CHANNEL_GLOBAL, $scheduledGlobal['channel']);
        self::assertNotSame($human->getName(), $scheduledGlobal['botName']);
        self::assertGreaterThanOrEqual(BotSimulationService::CHAT_REPLY_DELAY_MIN, $scheduledGlobal['delay']);
        self::assertGreaterThan($now + 1.5, $scheduledGlobal['deliverAt']);

        // Immediately & at +1.0s (before deliverAt): reply must NOT be visible in chat yet
        $earlyMsgs = $this->chatRepo->findVisibleSince('lagon_ouest', null, (int) $globalMsg->getId());
        self::assertCount(0, $earlyMsgs, 'Le bot ne doit pas répondre instantanément.');

        $this->botSim->tick('lagon_ouest', $now + 1.0, false);
        $stillEarly = $this->chatRepo->findVisibleSince('lagon_ouest', null, (int) $globalMsg->getId());
        self::assertCount(0, $stillEarly, 'La réponse du bot ne doit pas partir avant la fin du délai.');

        // After deliverAt passes, tick delivers the queued reply (even inside 1.0s bot tick throttle!)
        $delivered = $this->botSim->tick('lagon_ouest', $scheduledGlobal['deliverAt'] + 0.05, false);
        self::assertTrue($delivered);
        $afterGlobal = $this->chatRepo->findVisibleSince('lagon_ouest', null, (int) $globalMsg->getId());
        self::assertCount(1, $afterGlobal);
        $globalReply = $afterGlobal[0];
        self::assertSame(ChatMessage::CHANNEL_GLOBAL, $globalReply->getChannel());
        self::assertSame($scheduledGlobal['botName'], $globalReply->getSenderName());
        self::assertSame($scheduledGlobal['content'], $globalReply->getContent());

        // 3. Player ZONE message also uses delayed delivery
        $tZone = $scheduledGlobal['deliverAt'] + 0.2;
        $zoneMsg = new ChatMessage(ChatMessage::CHANNEL_ZONE, $human->getName(), $human->getClassCode(), 'Coucou ça va par ici ?', 'lagon_ouest');
        $this->em->persist($zoneMsg);
        $this->em->flush();

        $scheduledZone = $this->botSim->onPlayerChatMessage($human, $zoneMsg, $tZone);
        self::assertIsArray($scheduledZone);
        self::assertSame(ChatMessage::CHANNEL_ZONE, $scheduledZone['channel']);
        self::assertCount(0, $this->chatRepo->findVisibleSince('lagon_ouest', null, (int) $zoneMsg->getId()));

        $this->botSim->tick('lagon_ouest', $scheduledZone['deliverAt'] + 0.05, false);
        $afterZone = $this->chatRepo->findVisibleSince('lagon_ouest', null, (int) $zoneMsg->getId());
        self::assertCount(1, $afterZone);
        self::assertSame(ChatMessage::CHANNEL_ZONE, $afterZone[0]->getChannel());
        self::assertSame($scheduledZone['content'], $afterZone[0]->getContent());

        // 4. Loop prevention: a bot message must NEVER trigger another bot reply
        $botChar = $this->charRepo->findOneByNameInsensitive($globalReply->getSenderName());
        self::assertInstanceOf(Character::class, $botChar);
        self::assertTrue($botChar->isBot());
        self::assertNull($this->botSim->onPlayerChatMessage($botChar, $globalReply, $scheduledZone['deliverAt'] + 1.0));
    }

    public function testBotFightsKillsMonsterAndLevelsUpAutomatically(): void
    {
        $now = 4000.0;
        $lagon = WorldCatalog::zones()['lagon_ouest'];
        $hx = $lagon['respawnX'];
        $hz = $lagon['respawnZ'];
        $human = $this->createHuman('TemoinCombat', CharacterClass::TANK, 'lagon_ouest', $hx, $hz, $now);
        $botOwner = (new User())->setUsername('bot_combat_test')->setEmail('bc@reunion.re')->setPassword('unused');
        $this->em->persist($botOwner);

        // Give the bot enough XP so killing a single low-HP Crabe de Récif (+42 XP) triggers a level-up
        $needed = $this->stats->xpForNextLevel(1);
        $bot = (new Character($botOwner, 'Zamalou', CharacterClass::DPS))
            ->setIsBot(true)
            ->setLevel(1)
            ->setXp(max(0, $needed - 10))
            ->setZoneCode('lagon_ouest')
            ->setPosX($hx + 2.0)
            ->setPosY(12.0)
            ->setPosZ($hz)
            ->setLastSeenAt($now - 1.5)
            ->setBotState(['homeZone' => 'lagon_ouest', 'pauseUntil' => 0.0, 'nextChatAt' => $now + 999.0]);

        $s = $this->stats->compute($bot, $now);
        $bot->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
        $this->em->persist($bot);

        // Weakened Crabe right next to the bot (1m away -> strictly closest monster in lagon_ouest)
        $crabTpl = $this->tplRepo->findOneByCode('crabe_recif');
        self::assertNotNull($crabTpl);
        $weakCrab = (new MonsterInstance($crabTpl, 'lagon_ouest', $hx + 3.0, 12.0, $hz))
            ->setCurrentHp(5);
        $this->em->persist($weakCrab);
        $this->em->flush();

        $this->botSim->tick('lagon_ouest', $now, false);

        self::assertSame(MonsterInstance::STATE_DEAD, $weakCrab->getState(), 'Le bot doit attaquer et achever le monstre proche.');
        self::assertGreaterThanOrEqual(2, $bot->getLevel(), 'Le bot doit gagner l\'XP du monstre et passer niveau 2+.');
        self::assertSame(0, $bot->getStatPoints(), 'Le bot doit répartir automatiquement ses points de stats après un level-up.');
    }

    public function testBotPermadeathUngroupsDeletesPermanentlyAndSpawnsFreshReplacement(): void
    {
        $now = 5000.0;
        $lagon = WorldCatalog::zones()['lagon_ouest'];
        $hx = $lagon['respawnX'];
        $hz = $lagon['respawnZ'];
        $human = $this->createHuman('Survivant974', CharacterClass::TANK, 'lagon_ouest', $hx, $hz, $now);
        $this->em->flush();

        // Spawn full 9 bots (total 10 online)
        $this->botSim->tick('lagon_ouest', $now, false);
        $bots = $this->charRepo->findAliveBots();
        self::assertCount(9, $bots);

        $doomedBot = $bots[0];
        $oldBotId = (int) $doomedBot->getId();

        // Group the bot with the human leader and drop the bot to 1 HP next to an aggressive lethal monster
        $party = (new Party())
            ->setName('Escouade Mortelle')
            ->setLeaderCharacterId((int) $human->getId());
        $this->em->persist($party);
        $human->setParty($party);
        $doomedBot->setParty($party)
            ->setZoneCode('lagon_ouest')
            ->setPosX($hx + 30.0)
            ->setPosY(12.0)
            ->setPosZ($hz)
            ->setCurrentHp(1);

        $killerTpl = $this->tplRepo->findOneByCode('molosse_magma');
        self::assertNotNull($killerTpl);
        $killerMob = (new MonsterInstance($killerTpl, 'lagon_ouest', $hx + 31.0, 12.0, $hz))
            ->setState(MonsterInstance::STATE_COMBAT)
            ->setThreatTable([(string) $oldBotId => 500.0])
            ->setLastAttackAt($now - 10.0);
        $this->em->persist($killerMob);

        $tTick = $now + 3.0;
        $human->setLastSeenAt($tTick);
        $doomedBot->setLastSeenAt($tTick)
            ->setLastCombatAt($tTick - 1.0);
        $this->em->flush();

        // Tick zone simulation -> monster strikes and kills the bot!
        $ticked = $this->worldSim->maybeTickZone('lagon_ouest', $tTick);
        self::assertTrue($ticked);

        // 1. Dead bot MUST be immediately marked dead, removed from Party, and announced in Party chat
        self::assertTrue($doomedBot->isDead());
        self::assertNull($doomedBot->getParty(), 'Un bot qui meurt doit quitter automatiquement son groupe.');

        $this->em->refresh($party);
        self::assertSame(1, $party->getMembers()->count(), 'Le joueur humain reste seul dans son groupe.');

        $partyMsgs = $this->chatRepo->findVisibleSince('lagon_ouest', $party->getId(), 0);
        $hasDeathNotice = false;
        foreach ($partyMsgs as $msg) {
            if ($msg->getChannel() === ChatMessage::CHANNEL_PARTY && str_contains($msg->getContent(), 'mort définitive')) {
                $hasDeathNotice = true;
            }
        }
        self::assertTrue($hasDeathNotice, 'Une notification de mort définitive doit être postée dans le chat de groupe.');

        // 2. Granting XP CANNOT revive a permanently dead bot
        $xpRes = $this->leveling->grantXp($doomedBot, 5000);
        self::assertFalse($xpRes['leveledUp']);
        self::assertTrue($doomedBot->isDead());

        // 3. Permadeath cleanup permanently removes dead bot from DB
        $purged = $this->botSim->purgeDeadBots();
        self::assertSame(1, $purged);
        self::assertNull($this->charRepo->find($oldBotId), 'Un bot mort doit être définitivement supprimé.');

        // 4. Subsequent tick spawns a brand-new replacement bot to restore 10 online players
        $tReplace = $tTick + 2.0;
        $human->setLastSeenAt($tReplace);
        $this->em->flush();

        $this->botSim->tick('lagon_ouest', $tReplace, false);
        self::assertSame(10, $this->charRepo->countOnline($tReplace));
        self::assertNull($this->charRepo->find($oldBotId));
    }

    private function createHuman(
        string $name,
        CharacterClass $cls,
        string $zoneCode,
        float $x,
        float $z,
        float $now,
    ): Character {
        $username = strtolower($name).'_'.mt_rand(100, 999);
        $user = (new User())->setUsername($username)->setEmail($username.'@reunion.re')->setPassword('unused');
        $this->em->persist($user);

        $c = (new Character($user, $name, $cls))
            ->setIsBot(false)
            ->setLevel(2)
            ->setZoneCode($zoneCode)
            ->setPosX($x)
            ->setPosY(12.0)
            ->setPosZ($z)
            ->setLastSeenAt($now);

        $s = $this->stats->compute($c, $now);
        $c->setCurrentHp($s['maxHp'])->setCurrentMp($s['maxMp']);
        $this->em->persist($c);

        return $c;
    }
}
