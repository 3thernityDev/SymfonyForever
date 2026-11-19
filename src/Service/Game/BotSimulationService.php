<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\ChatMessage;
use App\Entity\MonsterInstance;
use App\Entity\Party;
use App\Entity\User;
use App\Game\BotProfileCatalog;
use App\Game\CharacterClass;
use App\Game\SkillDefinition;
use App\Game\SkillRegistry;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Repository\MonsterInstanceRepository;
use App\Repository\PartyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Simulates autonomous Réunionnais AI player-bots so ~10 connected heroes populate La Réunion:
 * roaming, fighting monsters, leveling up with auto-allocated stats & skills, accepting party
 * invites, chatting in French, and suffering permanent death (ungroup + deletion + replacement).
 */
final class BotSimulationService
{
    public const int TARGET_ONLINE_PLAYERS = 10;
    public const float BOT_TICK_INTERVAL = 1.0;
    public const float BOT_MOVE_SPEED = 9.5;
    public const float SPONTANEOUS_CHAT_MIN_GAP = 32.0;
    public const string BOT_OWNER_USERNAME = '__reunion_bots__';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly UserRepository $userRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly MonsterInstanceRepository $monsterRepository,
        private readonly PartyRepository $partyRepository,
        private readonly ReunionTerrainService $terrainService,
        private readonly StatCalculator $statCalculator,
        private readonly LevelingService $levelingService,
        private readonly CombatService $combatService,
        private readonly WorldSimulationService $simulationService,
        private readonly SkillRegistry $skillRegistry,
    ) {
    }

    /**
     * Advances bot population, party invites, movement, combat AI, leveling, and spontaneous chat.
     */
    public function tick(
        string $activeZoneCode,
        ?float $now = null,
        bool $allowBackgroundZoneTicks = true,
    ): bool {
        $now ??= microtime(true);

        $lock = $this->lockFactory->createLock('bot_sim_tick', 3.0);
        if (!$lock->acquire(false)) {
            return false;
        }

        try {
            $this->purgeDeadBots();

            $aliveBots = $this->characterRepository->findAliveBots();
            $onlineHumans = $this->characterRepository->countOnlineHumans($now);
            $desiredBots = max(0, self::TARGET_ONLINE_PLAYERS - $onlineHumans);

            $latestSeen = 0.0;
            foreach ($aliveBots as $b) {
                $latestSeen = max($latestSeen, $b->getLastSeenAt());
            }

            if ($aliveBots !== [] && count($aliveBots) >= $desiredBots && ($now - $latestSeen) < self::BOT_TICK_INTERVAL) {
                return false;
            }

            $dt = $latestSeen > 0.0 ? ($now - $latestSeen) : 1.0;
            $step = min(2.0, max(0.5, $dt));

            $aliveBots = $this->ensureBotPopulation($aliveBots, $desiredBots, $now);
            if ($aliveBots === []) {
                $this->em->flush();

                return true;
            }

            // Preload monsters & online players per bot zone cleanly once per tick
            $monstersByZone = [];
            $playersByZone = [];
            $latestSpontaneousChatAt = 0.0;

            foreach ($aliveBots as $bot) {
                $z = $bot->getZoneCode();
                if (!isset($monstersByZone[$z])) {
                    $monstersByZone[$z] = $this->monsterRepository->findByZone($z);
                    $playersByZone[$z] = $this->characterRepository->findOnlineInZone($z, $now);
                }
                $state = $bot->getBotState();
                $latestSpontaneousChatAt = max($latestSpontaneousChatAt, (float) ($state['lastSpontaneousChatAt'] ?? 0.0));
            }

            $canSpontaneousChat = ($now - $latestSpontaneousChatAt) >= self::SPONTANEOUS_CHAT_MIN_GAP;

            foreach ($aliveBots as $bot) {
                if ($bot->isDead()) {
                    continue;
                }
                $this->tickSingleBot($bot, $step, $now, $monstersByZone, $playersByZone, $canSpontaneousChat);
            }

            $this->em->flush();

            // Tick up to 2 background zones inhabited by bots so fights, XP, and permadeath resolve island-wide
            if ($allowBackgroundZoneTicks) {
                $bgZones = [];
                foreach ($aliveBots as $bot) {
                    $z = $bot->getZoneCode();
                    if ($z !== $activeZoneCode) {
                        $bgZones[$z] = true;
                    }
                }
                $zoneList = array_keys($bgZones);
                if ($zoneList !== []) {
                    shuffle($zoneList);
                    foreach (array_slice($zoneList, 0, 2) as $bgZone) {
                        $this->simulationService->maybeTickZone($bgZone, $now);
                    }
                    $this->purgeDeadBots();
                }
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Permanently removes any dead bot from parties, quests, and database storage.
     */
    public function purgeDeadBots(): int
    {
        $deadBots = $this->characterRepository->findDeadBots();
        if ($deadBots === []) {
            return 0;
        }

        $purged = 0;
        foreach ($deadBots as $deadBot) {
            $this->detachFromParty($deadBot);
            $this->em->createQuery('DELETE FROM App\Entity\CharacterQuest cq WHERE cq.character = :bot')
                ->setParameter('bot', $deadBot)
                ->execute();
            $this->em->remove($deadBot);
            ++$purged;
        }

        $this->em->flush();

        return $purged;
    }

    /**
     * Records the timestamp when a bot receives a party invitation so it accepts naturally.
     */
    public function onPartyInviteReceived(Character $target, ?float $now = null): void
    {
        if (!$target->isBot() || $target->isDead()) {
            return;
        }

        $state = $target->getBotState();
        $state['pendingInviteAt'] = $now ?? microtime(true);
        $target->setBotState($state);
    }

    /**
     * Generates a contextual French reply from an online bot when a human player writes in chat.
     */
    public function onPlayerChatMessage(Character $sender, ChatMessage $message, ?float $now = null): ?ChatMessage
    {
        $now ??= microtime(true);

        if ($sender->isBot() || $message->getChannel() === ChatMessage::CHANNEL_SYSTEM) {
            return null;
        }

        $channel = $message->getChannel();
        $candidates = [];

        if ($channel === ChatMessage::CHANNEL_PARTY) {
            $party = $sender->getParty();
            if ($party === null) {
                return null;
            }
            foreach ($party->getMembers() as $member) {
                if ($member->isBot() && !$member->isDead()) {
                    $candidates[] = $member;
                }
            }
        } elseif ($channel === ChatMessage::CHANNEL_ZONE) {
            foreach ($this->characterRepository->findAliveBots() as $bot) {
                if ($bot->getZoneCode() === $sender->getZoneCode()) {
                    $candidates[] = $bot;
                }
            }
        } else {
            $candidates = $this->characterRepository->findAliveBots();
        }

        if ($candidates === []) {
            return null;
        }

        $content = $message->getContent();
        $chosenBot = null;
        $directlyAddressed = false;

        foreach ($candidates as $bot) {
            if (stripos($content, $bot->getName()) !== false) {
                $chosenBot = $bot;
                $directlyAddressed = true;
                break;
            }
        }

        if ($chosenBot === null) {
            usort($candidates, static function (Character $a, Character $b): int {
                $ra = (float) ($a->getBotState()['lastReplyAt'] ?? 0.0);
                $rb = (float) ($b->getBotState()['lastReplyAt'] ?? 0.0);

                return $ra <=> $rb;
            });

            // Among bots that haven't replied recently, pick one of the least-recent responders for variety
            $oldestTs = (float) ($candidates[0]->getBotState()['lastReplyAt'] ?? 0.0);
            $tied = array_values(array_filter(
                $candidates,
                static fn (Character $c): bool => abs(((float) ($c->getBotState()['lastReplyAt'] ?? 0.0)) - $oldestTs) < 1.0,
            ));
            $chosenBot = $tied[array_rand($tied)];
        }

        $replyText = BotProfileCatalog::generateReply(
            $content,
            $sender->getName(),
            $channel,
            $sender->getZoneCode(),
            $chosenBot->getCharacterClass(),
            $directlyAddressed,
        );

        if ($replyText === null) {
            return null;
        }

        $replyMsg = new ChatMessage(
            $channel,
            $chosenBot->getName(),
            $chosenBot->getClassCode(),
            $replyText,
            $channel === ChatMessage::CHANNEL_ZONE ? $sender->getZoneCode() : null,
            $channel === ChatMessage::CHANNEL_PARTY ? $sender->getParty()?->getId() : null,
        );

        $state = $chosenBot->getBotState();
        $state['lastReplyAt'] = $now;
        $state['nextChatAt'] = max((float) ($state['nextChatAt'] ?? 0.0), $now + 35.0);
        $chosenBot->setBotState($state);

        $this->em->persist($replyMsg);
        $this->em->flush();

        return $replyMsg;
    }

    /**
     * Spawns initial or replacement bots until total alive online players hits TARGET_ONLINE_PLAYERS.
     *
     * @param list<Character> $aliveBots
     * @return list<Character>
     */
    private function ensureBotPopulation(array $aliveBots, int $desiredBots, float $now): array
    {
        $missing = $desiredBots - count($aliveBots);
        if ($missing <= 0) {
            return $aliveBots;
        }

        $botOwner = $this->getOrCreateBotOwner();
        $isBootstrap = $aliveBots === [];
        $toSpawn = $isBootstrap ? $missing : min($missing, 2);

        $usedNames = [];
        $zoneCounts = [];
        foreach ($aliveBots as $existing) {
            $usedNames[strtolower($existing->getName())] = true;
            $z = $existing->getZoneCode();
            $zoneCounts[$z] = ($zoneCounts[$z] ?? 0) + 1;
        }

        $slots = BotProfileCatalog::rosterSlots();

        for ($i = 0; $i < $toSpawn; ++$i) {
            $slot = $isBootstrap
                ? $slots[$i % count($slots)]
                : $this->pickLeastPopulatedSlot($slots, $zoneCounts);

            $name = $this->pickUniquePseudonym($usedNames);
            $usedNames[strtolower($name)] = true;
            $zoneCode = $slot['zone'];
            $zoneCounts[$zoneCode] = ($zoneCounts[$zoneCode] ?? 0) + 1;

            $bot = $this->createBotFromSlot($botOwner, $name, $slot, $now);
            $this->em->persist($bot);
            $aliveBots[] = $bot;
        }

        $this->em->flush();

        return $aliveBots;
    }

    /**
     * @param list<array{zone: string, minLevel: int, maxLevel: int, class: CharacterClass}> $slots
     * @param array<string, int> $zoneCounts
     * @return array{zone: string, minLevel: int, maxLevel: int, class: CharacterClass}
     */
    private function pickLeastPopulatedSlot(array $slots, array $zoneCounts): array
    {
        $targetByZone = [];
        foreach ($slots as $s) {
            $targetByZone[$s['zone']] = ($targetByZone[$s['zone']] ?? 0) + 1;
        }

        $candidates = [];
        $bestDeficit = PHP_INT_MIN;
        foreach ($slots as $s) {
            $z = $s['zone'];
            $deficit = $targetByZone[$z] - ($zoneCounts[$z] ?? 0);
            if ($deficit > $bestDeficit) {
                $bestDeficit = $deficit;
                $candidates = [$s];
            } elseif ($deficit === $bestDeficit) {
                $candidates[] = $s;
            }
        }

        return $candidates[array_rand($candidates)];
    }

    /**
     * @param array<string, bool> $usedNames
     */
    private function pickUniquePseudonym(array $usedNames): string
    {
        $pool = BotProfileCatalog::pseudonyms();
        shuffle($pool);

        foreach ($pool as $candidate) {
            $key = strtolower($candidate);
            if (isset($usedNames[$key])) {
                continue;
            }
            if ($this->characterRepository->findOneByNameInsensitive($candidate) === null) {
                return $candidate;
            }
        }

        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $candidate = $pool[array_rand($pool)].mt_rand(10, 99);
            $key = strtolower($candidate);
            if (!isset($usedNames[$key]) && $this->characterRepository->findOneByNameInsensitive($candidate) === null) {
                return substr($candidate, 0, 32);
            }
        }

        return 'Kreol'.mt_rand(1000, 9999);
    }

    /**
     * @param array{zone: string, minLevel: int, maxLevel: int, class: CharacterClass} $slot
     */
    private function createBotFromSlot(User $owner, string $name, array $slot, float $now): Character
    {
        $cls = $slot['class'];
        $level = mt_rand($slot['minLevel'], $slot['maxLevel']);
        $zoneCode = $slot['zone'];

        $bot = new Character($owner, $name, $cls);
        $bot->setIsBot(true);
        $bot->setLevel($level);
        $bot->setZoneCode($zoneCode);
        $bot->setLastSeenAt($now);

        if ($level > 1) {
            $growth = $cls->levelGrowth();
            $gained = $level - 1;
            $bot->setVitality($bot->getVitality() + $growth['vitality'] * $gained)
                ->setStrength($bot->getStrength() + $growth['strength'] * $gained)
                ->setIntelligence($bot->getIntelligence() + $growth['intelligence'] * $gained)
                ->setDefense($bot->getDefense() + $growth['defense'] * $gained)
                ->setSpirit($bot->getSpirit() + $growth['spirit'] * $gained)
                ->setStatPoints($gained * LevelingService::FREE_STAT_POINTS_PER_LEVEL)
                ->setSkillPoints(intdiv($level, LevelingService::SKILL_POINT_INTERVAL));
            $this->autoInvestPoints($bot);
        }

        $stats = $this->statCalculator->compute($bot, $now);
        $bot->setCurrentHp($stats['maxHp']);
        $bot->setCurrentMp($cls === CharacterClass::TANK ? 30 : $stats['maxMp']);

        $spawnPos = $this->pickSafeSpawnInZone($zoneCode);
        $bot->setPosX($spawnPos['x'])
            ->setPosY($spawnPos['y'])
            ->setPosZ($spawnPos['z'])
            ->setRotY((mt_rand(0, 628) / 100.0) - M_PI);

        $bot->setBotState([
            'homeZone' => $zoneCode,
            'pauseUntil' => $now + 2.5,
            'nextChatAt' => $now + mt_rand(28, 85),
            'waypointX' => null,
            'waypointZ' => null,
            'pendingInviteAt' => null,
            'lastLowHpCalloutAt' => 0.0,
            'lastSpontaneousChatAt' => 0.0,
            'lastReplyAt' => 0.0,
            'joinedAt' => $now,
        ]);

        return $bot;
    }

    /**
     * @return array{x: float, y: float, z: float}
     */
    private function pickSafeSpawnInZone(string $zoneCode): array
    {
        $zones = WorldCatalog::zones();
        $zMeta = $zones[$zoneCode] ?? $zones['lagon_ouest'];
        $baseX = $zMeta['respawnX'];
        $baseZ = $zMeta['respawnZ'];
        $worldBosses = $this->monsterRepository->findWorldBosses();

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $angle = (mt_rand(0, 359) * M_PI) / 180.0;
            $dist = (float) mt_rand(14, 55);
            $safe = $this->terrainService->findNearestWalkable(
                $baseX + cos($angle) * $dist,
                $baseZ + sin($angle) * $dist,
            );

            $tooCloseToBoss = false;
            foreach ($worldBosses as $wb) {
                if (hypot($safe['x'] - $wb->getPosX(), $safe['z'] - $wb->getPosZ()) < 75.0) {
                    $tooCloseToBoss = true;
                    break;
                }
            }
            if (!$tooCloseToBoss) {
                return $safe;
            }
        }

        return $this->terrainService->findNearestWalkable($baseX + 14.0, $baseZ + 14.0);
    }

    /**
     * @param array<string, list<MonsterInstance>> $monstersByZone
     * @param array<string, list<Character>>       $playersByZone
     */
    private function tickSingleBot(
        Character $bot,
        float $step,
        float $now,
        array &$monstersByZone,
        array &$playersByZone,
        bool &$canSpontaneousChat,
    ): void {
        $bot->setLastSeenAt($now);
        $this->autoInvestPoints($bot);

        $state = $bot->getBotState();
        $zoneCode = $bot->getZoneCode();

        // 1. Accept pending party invitations after a short natural delay
        $pendingPartyId = $bot->getPendingPartyInviteId();
        if ($pendingPartyId !== null) {
            $invitedAt = (float) ($state['pendingInviteAt'] ?? ($now - 2.0));
            if (($now - $invitedAt) >= 0.8) {
                $party = $this->partyRepository->find($pendingPartyId);
                $bot->setPendingPartyInviteId(null);
                unset($state['pendingInviteAt']);

                if ($party instanceof Party && $party->getMembers()->count() < Party::MAX_MEMBERS) {
                    $bot->setParty($party);
                    $greetings = BotProfileCatalog::partyJoinGreetings();
                    $greetMsg = new ChatMessage(
                        ChatMessage::CHANNEL_PARTY,
                        $bot->getName(),
                        $bot->getClassCode(),
                        $greetings[array_rand($greetings)],
                        null,
                        $party->getId(),
                    );
                    $this->em->persist($greetMsg);

                    $leader = $this->resolvePartyLeader($party, $bot);
                    if ($leader !== null && $leader->getZoneCode() !== $bot->getZoneCode()) {
                        $this->relocateBotNear($bot, $leader, $now);
                        $zoneCode = $bot->getZoneCode();
                        if (!isset($monstersByZone[$zoneCode])) {
                            $monstersByZone[$zoneCode] = $this->monsterRepository->findByZone($zoneCode);
                            $playersByZone[$zoneCode] = $this->characterRepository->findOnlineInZone($zoneCode, $now);
                        }
                    }
                }
                $bot->setBotState($state);
            }
        }

        $zoneMonsters = $monstersByZone[$zoneCode] ?? [];
        $engagedMonsters = $this->findMonstersEngagedWith($bot, $zoneMonsters);

        $party = $bot->getParty();
        $alivePartyMembers = [];
        if ($party !== null) {
            foreach ($party->getMembers() as $m) {
                if (!$m->isDead()) {
                    $alivePartyMembers[] = $m;
                }
            }
        }
        $inActiveGroup = $party !== null && count($alivePartyMembers) > 1;

        // Respect spawn/arrival warmup pause unless directly under attack or grouped in active combat
        if (((float) ($state['pauseUntil'] ?? 0.0)) > $now && $engagedMonsters === [] && !$inActiveGroup) {
            $bot->setIsMoving(false);

            return;
        }

        $stats = $this->statCalculator->compute($bot, $now);
        $hpRatio = $bot->getCurrentHp() / max(1, (int) $stats['maxHp']);
        $cls = $bot->getCharacterClass();

        // Low HP callout in Party chat
        if ($inActiveGroup && $engagedMonsters !== [] && $hpRatio < 0.35 && ($now - ((float) ($state['lastLowHpCalloutAt'] ?? 0.0))) > 25.0) {
            $callouts = BotProfileCatalog::lowHpCallouts();
            $this->em->persist(new ChatMessage(
                ChatMessage::CHANNEL_PARTY,
                $bot->getName(),
                $bot->getClassCode(),
                $callouts[array_rand($callouts)],
                null,
                $party->getId(),
            ));
            $state['lastLowHpCalloutAt'] = $now;
            $bot->setBotState($state);
        }

        if ($inActiveGroup) {
            $this->runGroupedBotBehavior($bot, $party, $alivePartyMembers, $engagedMonsters, $zoneMonsters, $step, $now, $monstersByZone, $playersByZone);
        } else {
            $this->runSoloBotBehavior($bot, $cls, $hpRatio, $engagedMonsters, $zoneMonsters, $step, $now, $monstersByZone, $playersByZone);
        }

        // Occasional spontaneous French chat in Global or Zone
        $nextChatAt = (float) ($state['nextChatAt'] ?? ($now + 60.0));
        if ($canSpontaneousChat && $now >= $nextChatAt) {
            $isGlobal = mt_rand(1, 100) <= 65;
            if ($isGlobal) {
                $msgs = BotProfileCatalog::spontaneousGlobalMessages();
                $chatMsg = new ChatMessage(
                    ChatMessage::CHANNEL_GLOBAL,
                    $bot->getName(),
                    $bot->getClassCode(),
                    $msgs[array_rand($msgs)],
                );
            } else {
                $msgs = BotProfileCatalog::spontaneousZoneMessages($bot->getZoneCode());
                $chatMsg = new ChatMessage(
                    ChatMessage::CHANNEL_ZONE,
                    $bot->getName(),
                    $bot->getClassCode(),
                    $msgs[array_rand($msgs)],
                    $bot->getZoneCode(),
                );
            }
            $this->em->persist($chatMsg);

            $state = $bot->getBotState();
            $state['lastSpontaneousChatAt'] = $now;
            $state['nextChatAt'] = $now + mt_rand(55, 120);
            $bot->setBotState($state);
            $canSpontaneousChat = false;
        }
    }

    /**
     * @param list<Character>                      $alivePartyMembers
     * @param list<MonsterInstance>                $engagedMonsters
     * @param list<MonsterInstance>                $zoneMonsters
     * @param array<string, list<MonsterInstance>> $monstersByZone
     * @param array<string, list<Character>>       $playersByZone
     */
    private function runGroupedBotBehavior(
        Character $bot,
        Party $party,
        array $alivePartyMembers,
        array $engagedMonsters,
        array $zoneMonsters,
        float $step,
        float $now,
        array &$monstersByZone,
        array &$playersByZone,
    ): void {
        $leader = $this->resolvePartyLeader($party, $bot);
        if ($leader !== null && $leader->getZoneCode() !== $bot->getZoneCode() && ($now - $bot->getLastCombatAt()) >= 5.0) {
            $this->relocateBotNear($bot, $leader, $now);
            $zoneCode = $bot->getZoneCode();
            if (!isset($monstersByZone[$zoneCode])) {
                $monstersByZone[$zoneCode] = $this->monsterRepository->findByZone($zoneCode);
                $playersByZone[$zoneCode] = $this->characterRepository->findOnlineInZone($zoneCode, $now);
            }
            $zoneMonsters = $monstersByZone[$zoneCode];
        }

        // Healers prioritize injured party members first
        if ($bot->getCharacterClass() === CharacterClass::HEAL && $this->tryHealInjuredAllies($bot, $alivePartyMembers, $step, $now)) {
            return;
        }

        $target = $this->findPartyCombatTarget($bot, $leader, $alivePartyMembers, $engagedMonsters, $zoneMonsters);
        if ($target !== null) {
            $this->executeBotCombatAgainst($bot, $target, $step, $now, $zoneMonsters);

            return;
        }

        // Follow party leader calmly
        if ($leader !== null && $leader->getZoneCode() === $bot->getZoneCode()) {
            $distLeader = hypot($leader->getPosX() - $bot->getPosX(), $leader->getPosZ() - $bot->getPosZ());
            if ($distLeader > 14.0) {
                $offsetAngle = (($bot->getId() ?? 1) * 1.37);
                $fx = $leader->getPosX() + cos($offsetAngle) * 5.0;
                $fz = $leader->getPosZ() + sin($offsetAngle) * 5.0;
                $this->moveBotTowards($bot, $fx, $fz, self::BOT_MOVE_SPEED * $step);

                return;
            }
        }

        $bot->setIsMoving(false);
        $bot->setTargetType(null)->setTargetId(null);
    }

    /**
     * @param list<MonsterInstance>                $engagedMonsters
     * @param list<MonsterInstance>                $zoneMonsters
     * @param array<string, list<MonsterInstance>> $monstersByZone
     * @param array<string, list<Character>>       $playersByZone
     */
    private function runSoloBotBehavior(
        Character $bot,
        CharacterClass $cls,
        float $hpRatio,
        array $engagedMonsters,
        array $zoneMonsters,
        float $step,
        float $now,
        array &$monstersByZone,
        array &$playersByZone,
    ): void {
        // Defend self first if already aggroed by monsters
        if ($engagedMonsters !== []) {
            if ($cls === CharacterClass::HEAL && $hpRatio < 0.60) {
                $this->trySelfHeal($bot, $now);
            }
            $this->executeBotCombatAgainst($bot, $engagedMonsters[0], $step, $now, $zoneMonsters);

            return;
        }

        // Out of combat: heal / rest if injured
        if ($cls === CharacterClass::HEAL && $hpRatio < 0.70) {
            $this->trySelfHeal($bot, $now);
        }
        if ($hpRatio < 0.55) {
            $bot->setIsMoving(false);

            return;
        }

        // Graduate to the next zone when overleveled & out of combat
        $zones = WorldCatalog::zones();
        $currentMeta = $zones[$bot->getZoneCode()] ?? null;
        if ($currentMeta !== null && $bot->getLevel() > ($currentMeta['maxLevel'] + 1) && ($now - $bot->getLastCombatAt()) >= 5.0) {
            $order = BotProfileCatalog::zoneProgressionOrder();
            $idx = array_search($bot->getZoneCode(), $order, true);
            if ($idx !== false && isset($order[$idx + 1])) {
                $nextZone = $order[$idx + 1];
                $pos = $this->pickSafeSpawnInZone($nextZone);
                $bot->setZoneCode($nextZone)
                    ->setPosX($pos['x'])
                    ->setPosY($pos['y'])
                    ->setPosZ($pos['z'])
                    ->setIsMoving(false);
                $state = $bot->getBotState();
                $state['homeZone'] = $nextZone;
                $state['waypointX'] = null;
                $state['waypointZ'] = null;
                $bot->setBotState($state);

                if (!isset($monstersByZone[$nextZone])) {
                    $monstersByZone[$nextZone] = $this->monsterRepository->findByZone($nextZone);
                    $playersByZone[$nextZone] = $this->characterRepository->findOnlineInZone($nextZone, $now);
                }

                return;
            }
        }

        // Hunt nearby suitable non-World-Boss monster if healthy
        if ($hpRatio >= 0.70) {
            $huntTarget = $this->pickSoloHuntTarget($bot, $zoneMonsters);
            if ($huntTarget !== null) {
                $this->executeBotCombatAgainst($bot, $huntTarget, $step, $now, $zoneMonsters);

                return;
            }
        }

        // Casual roaming around zone hub waypoints
        $this->roamInZone($bot, $step, $now);
    }

    /**
     * @param list<MonsterInstance> $zoneMonsters
     */
    private function executeBotCombatAgainst(
        Character $bot,
        MonsterInstance $target,
        float $step,
        float $now,
        array $zoneMonsters,
    ): void {
        if ($target->getState() === MonsterInstance::STATE_DEAD) {
            $bot->setTargetType(null)->setTargetId(null)->setIsMoving(false);

            return;
        }

        $cls = $bot->getCharacterClass();
        $bot->setTargetType('MONSTER')->setTargetId($target->getId());

        $dx = $target->getPosX() - $bot->getPosX();
        $dz = $target->getPosZ() - $bot->getPosZ();
        $dist = hypot($dx, $dz);
        $desiredRange = $cls === CharacterClass::HEAL ? 16.5 : 6.2;

        if ($dist > $desiredRange) {
            $this->moveBotTowards($bot, $target->getPosX(), $target->getPosZ(), self::BOT_MOVE_SPEED * $step);

            return;
        }

        $bot->setIsMoving(false);
        if ($dist > 0.25) {
            $bot->setRotY(atan2($dx, $dz));
        }

        // Try best available class skill first, else auto-attack
        $skillCode = $this->chooseOffensiveSkillCode($bot, $target, $zoneMonsters, $now);
        if ($skillCode !== null) {
            $res = $this->combatService->castSkill($bot, $skillCode, 'MONSTER', $target->getId(), $now);
            if ($res['ok']) {
                $this->handlePostCombatResult($bot, $res, $now);

                return;
            }
        }

        $aaRes = $this->combatService->performAutoAttack($bot, 'MONSTER', $target->getId(), $now);
        if ($aaRes['ok']) {
            $this->handlePostCombatResult($bot, $aaRes, $now);
        }
    }

    /**
     * @param array{ok: bool, xpGained: int, leveledUp: bool} $res
     */
    private function handlePostCombatResult(Character $bot, array $res, float $now): void
    {
        if (!$res['leveledUp']) {
            return;
        }

        $this->autoInvestPoints($bot);
        if (mt_rand(1, 100) <= 60) {
            $msgs = BotProfileCatalog::levelUpMessages($bot->getLevel());
            $party = $bot->getParty();
            if ($party !== null) {
                $this->em->persist(new ChatMessage(
                    ChatMessage::CHANNEL_PARTY,
                    $bot->getName(),
                    $bot->getClassCode(),
                    $msgs[array_rand($msgs)],
                    null,
                    $party->getId(),
                ));
            } else {
                $this->em->persist(new ChatMessage(
                    ChatMessage::CHANNEL_ZONE,
                    $bot->getName(),
                    $bot->getClassCode(),
                    $msgs[array_rand($msgs)],
                    $bot->getZoneCode(),
                ));
            }
        }
    }

    /**
     * @param list<MonsterInstance> $zoneMonsters
     */
    private function chooseOffensiveSkillCode(
        Character $bot,
        MonsterInstance $target,
        array $zoneMonsters,
        float $now,
    ): ?string {
        $cls = $bot->getCharacterClass();
        $stats = $this->statCalculator->compute($bot, $now);
        $hpRatio = $bot->getCurrentHp() / max(1, (int) $stats['maxHp']);
        $targetHpRatio = $target->getCurrentHp() / max(1, $target->getTemplate()->getMaxHp());

        $nearbyMobs = 0;
        foreach ($zoneMonsters as $m) {
            if ($m->getState() !== MonsterInstance::STATE_DEAD && hypot($m->getPosX() - $target->getPosX(), $m->getPosZ() - $target->getPosZ()) <= 9.0) {
                ++$nearbyMobs;
            }
        }

        if ($cls === CharacterClass::TANK) {
            if (($hpRatio < 0.72 || $target->getTemplate()->isWorldBoss()) && $this->canCastSkill($bot, 'basalt_skin', $now)) {
                return 'basalt_skin';
            }
            $topThreat = $target->getTopThreatCharacterId($now);
            if ($topThreat !== null && $topThreat !== $bot->getId() && $this->canCastSkill($bot, 'piton_taunt', $now)) {
                return 'piton_taunt';
            }
            if ($nearbyMobs >= 2 && $this->canCastSkill($bot, 'seismic_slam', $now)) {
                return 'seismic_slam';
            }
            if ($this->canCastSkill($bot, 'shield_bash', $now)) {
                return 'shield_bash';
            }

            return null;
        }

        if ($cls === CharacterClass::DPS) {
            if ($targetHpRatio <= 0.35 && $this->canCastSkill($bot, 'volcanic_fury', $now)) {
                return 'volcanic_fury';
            }
            if (!$this->hasDotFromCaster($target, (int) $bot->getId(), 'burning_brand', $now) && $this->canCastSkill($bot, 'burning_brand', $now)) {
                return 'burning_brand';
            }
            if ($nearbyMobs >= 2 && $this->canCastSkill($bot, 'scoria_rain', $now)) {
                return 'scoria_rain';
            }
            if ($this->canCastSkill($bot, 'ember_strike', $now)) {
                return 'ember_strike';
            }

            return null;
        }

        // HEAL offensive fallback when party is safe
        if ($this->canCastSkill($bot, 'solar_flare', $now)) {
            return 'solar_flare';
        }

        return null;
    }

    /**
     * @param list<Character> $aliveMembers
     */
    private function tryHealInjuredAllies(Character $bot, array $aliveMembers, float $step, float $now): bool
    {
        $lowAllies = [];
        $lowestAlly = null;
        $lowestRatio = 1.0;

        foreach ($aliveMembers as $member) {
            if ($member->getZoneCode() !== $bot->getZoneCode()) {
                continue;
            }
            $dist = hypot($member->getPosX() - $bot->getPosX(), $member->getPosZ() - $bot->getPosZ());
            if ($dist > 26.0) {
                continue;
            }
            $mStats = $this->statCalculator->compute($member, $now);
            $ratio = $member->getCurrentHp() / max(1, (int) $mStats['maxHp']);
            if ($ratio < 0.82) {
                $lowAllies[] = $member;
            }
            if ($ratio < $lowestRatio) {
                $lowestRatio = $ratio;
                $lowestAlly = $member;
            }
        }

        if (count($lowAllies) >= 2 && $this->canCastSkill($bot, 'lagoon_blessing', $now)) {
            $res = $this->combatService->castSkill($bot, 'lagoon_blessing', null, null, $now);

            return $res['ok'];
        }

        if ($lowestAlly !== null && $lowestRatio < 0.78) {
            $dist = hypot($lowestAlly->getPosX() - $bot->getPosX(), $lowestAlly->getPosZ() - $bot->getPosZ());
            if ($dist > 20.0) {
                $this->moveBotTowards($bot, $lowestAlly->getPosX(), $lowestAlly->getPosZ(), self::BOT_MOVE_SPEED * $step);

                return true;
            }

            if ($lowestRatio < 0.75 && !$this->hasHotActive($lowestAlly, 'salazie_mist', $now) && $this->canCastSkill($bot, 'salazie_mist', $now)) {
                $res = $this->combatService->castSkill($bot, 'salazie_mist', 'PLAYER', $lowestAlly->getId(), $now);
                if ($res['ok']) {
                    return true;
                }
            }

            if ($this->canCastSkill($bot, 'faham_infusion', $now)) {
                $res = $this->combatService->castSkill($bot, 'faham_infusion', 'PLAYER', $lowestAlly->getId(), $now);
                if ($res['ok']) {
                    return true;
                }
            }
        }

        return false;
    }

    private function trySelfHeal(Character $bot, float $now): void
    {
        if (!$this->hasHotActive($bot, 'salazie_mist', $now) && $this->canCastSkill($bot, 'salazie_mist', $now)) {
            $this->combatService->castSkill($bot, 'salazie_mist', 'PLAYER', $bot->getId(), $now);

            return;
        }
        if ($this->canCastSkill($bot, 'faham_infusion', $now)) {
            $this->combatService->castSkill($bot, 'faham_infusion', 'PLAYER', $bot->getId(), $now);
        }
    }

    private function canCastSkill(Character $bot, string $skillCode, float $now): bool
    {
        $skill = $this->skillRegistry->get($skillCode);
        if (!$skill instanceof SkillDefinition || $skill->characterClass !== $bot->getCharacterClass()) {
            return false;
        }
        if ($bot->getLevel() < $skill->unlockLevel) {
            return false;
        }
        if ($bot->getGcdEndsAt() > $now + 0.05) {
            return false;
        }
        if (($bot->getCooldowns()[$skillCode] ?? 0.0) > $now + 0.05) {
            return false;
        }

        return $bot->getCurrentMp() >= $skill->manaCost;
    }

    private function hasDotFromCaster(MonsterInstance $monster, int $casterId, string $skillCode, float $now): bool
    {
        foreach ($monster->getActiveEffects() as $ef) {
            if ($ef['skillCode'] === $skillCode && (int) $ef['sourceId'] === $casterId && $ef['expiresAt'] > $now) {
                return true;
            }
        }

        return false;
    }

    private function hasHotActive(Character $ally, string $skillCode, float $now): bool
    {
        foreach ($ally->getActiveEffects() as $ef) {
            if ($ef['skillCode'] === $skillCode && $ef['expiresAt'] > $now) {
                return true;
            }
        }

        return false;
    }

    private function autoInvestPoints(Character $bot): void
    {
        $cls = $bot->getCharacterClass();
        $statCycle = match ($cls) {
            CharacterClass::TANK => ['vitality', 'defense', 'vitality', 'strength', 'defense'],
            CharacterClass::DPS => ['strength', 'vitality', 'strength', 'intelligence'],
            CharacterClass::HEAL => ['intelligence', 'spirit', 'vitality', 'intelligence'],
        };

        $step = 0;
        while ($bot->getStatPoints() > 0 && $step < 60) {
            $stat = $statCycle[$step % count($statCycle)];
            if (!$this->levelingService->allocateStat($bot, $stat)) {
                break;
            }
            ++$step;
        }

        if ($bot->getSkillPoints() > 0) {
            $skillOrder = match ($cls) {
                CharacterClass::TANK => ['shield_bash', 'basalt_skin', 'seismic_slam', 'piton_taunt'],
                CharacterClass::DPS => ['ember_strike', 'volcanic_fury', 'burning_brand', 'scoria_rain'],
                CharacterClass::HEAL => ['faham_infusion', 'salazie_mist', 'lagoon_blessing', 'solar_flare'],
            };

            $guard = 0;
            while ($bot->getSkillPoints() > 0 && $guard < 12) {
                ++$guard;
                $upgraded = false;
                foreach ($skillOrder as $code) {
                    $res = $this->levelingService->upgradeSkill($bot, $code);
                    if ($res['ok']) {
                        $upgraded = true;
                        break;
                    }
                }
                if (!$upgraded) {
                    break;
                }
            }
        }
    }

    /**
     * @param list<MonsterInstance> $zoneMonsters
     * @return list<MonsterInstance>
     */
    private function findMonstersEngagedWith(Character $bot, array $zoneMonsters): array
    {
        $botIdKey = (string) $bot->getId();
        $engaged = [];
        foreach ($zoneMonsters as $m) {
            if ($m->getState() === MonsterInstance::STATE_DEAD) {
                continue;
            }
            if (array_key_exists($botIdKey, $m->getThreatTable())) {
                $engaged[] = $m;
            }
        }

        return $engaged;
    }

    /**
     * @param list<Character>       $aliveMembers
     * @param list<MonsterInstance> $engagedMonsters
     * @param list<MonsterInstance> $zoneMonsters
     */
    private function findPartyCombatTarget(
        Character $bot,
        ?Character $leader,
        array $aliveMembers,
        array $engagedMonsters,
        array $zoneMonsters,
    ): ?MonsterInstance {
        if ($engagedMonsters !== []) {
            return $engagedMonsters[0];
        }

        $memberIds = [];
        foreach ($aliveMembers as $m) {
            $memberIds[(string) $m->getId()] = true;
        }

        // Assist any party member currently fighting a monster in the zone
        foreach ($zoneMonsters as $monster) {
            if ($monster->getState() === MonsterInstance::STATE_DEAD) {
                continue;
            }
            foreach (array_keys($monster->getThreatTable()) as $cidStr) {
                if (isset($memberIds[$cidStr])) {
                    return $monster;
                }
            }
        }

        // Or engage whatever monster the leader is explicitly targeting within 42m
        if ($leader !== null && $leader->getTargetType() === 'MONSTER' && $leader->getTargetId() !== null && $leader->getZoneCode() === $bot->getZoneCode()) {
            foreach ($zoneMonsters as $monster) {
                if ($monster->getId() === $leader->getTargetId() && $monster->getState() !== MonsterInstance::STATE_DEAD) {
                    if (hypot($monster->getPosX() - $leader->getPosX(), $monster->getPosZ() - $leader->getPosZ()) <= 42.0) {
                        return $monster;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<MonsterInstance> $zoneMonsters
     */
    private function pickSoloHuntTarget(Character $bot, array $zoneMonsters): ?MonsterInstance
    {
        $best = null;
        $bestDist = 160.0;

        foreach ($zoneMonsters as $m) {
            $tpl = $m->getTemplate();
            // Never solo-pull the World Boss
            if ($tpl->isWorldBoss() || $m->getState() === MonsterInstance::STATE_DEAD) {
                continue;
            }
            if ($tpl->getLevel() > ($bot->getLevel() + 2)) {
                continue;
            }
            // Do not steal monsters already engaged with another character unless it's already us
            $threat = $m->getThreatTable();
            if ($threat !== [] && !array_key_exists((string) $bot->getId(), $threat)) {
                continue;
            }

            $dist = hypot($m->getPosX() - $bot->getPosX(), $m->getPosZ() - $bot->getPosZ());
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $m;
            }
        }

        return $best;
    }

    private function roamInZone(Character $bot, float $step, float $now): void
    {
        $state = $bot->getBotState();
        $wx = isset($state['waypointX']) ? (float) $state['waypointX'] : null;
        $wz = isset($state['waypointZ']) ? (float) $state['waypointZ'] : null;

        if ($wx !== null && $wz !== null) {
            $dist = hypot($wx - $bot->getPosX(), $wz - $bot->getPosZ());
            if ($dist <= 2.5) {
                $state['waypointX'] = null;
                $state['waypointZ'] = null;
                $state['pauseUntil'] = $now + mt_rand(2, 5);
                $bot->setBotState($state);
                $bot->setIsMoving(false);

                return;
            }
            $this->moveBotTowards($bot, $wx, $wz, self::BOT_MOVE_SPEED * 0.72 * $step);

            return;
        }

        $zones = WorldCatalog::zones();
        $zMeta = $zones[$bot->getZoneCode()] ?? $zones['lagon_ouest'];
        $angle = (mt_rand(0, 359) * M_PI) / 180.0;
        $radius = (float) mt_rand(18, 85);
        $cand = $this->terrainService->findNearestWalkable(
            $zMeta['respawnX'] + cos($angle) * $radius,
            $zMeta['respawnZ'] + sin($angle) * $radius,
        );

        $state['waypointX'] = $cand['x'];
        $state['waypointZ'] = $cand['z'];
        $bot->setBotState($state);
        $this->moveBotTowards($bot, $cand['x'], $cand['z'], self::BOT_MOVE_SPEED * 0.72 * $step);
    }

    private function moveBotTowards(Character $bot, float $tx, float $tz, float $maxStep): void
    {
        $dx = $tx - $bot->getPosX();
        $dz = $tz - $bot->getPosZ();
        $dist = hypot($dx, $dz);
        if ($dist < 0.2) {
            $bot->setIsMoving(false);

            return;
        }

        $step = min($dist, $maxStep);
        $nx = $bot->getPosX() + ($dx / $dist) * $step;
        $nz = $bot->getPosZ() + ($dz / $dist) * $step;

        if (!$this->terrainService->isWalkable($nx, $nz)) {
            $alt = $this->terrainService->findNearestWalkable($nx, $nz);
            $nx = $alt['x'];
            $nz = $alt['z'];
        }

        $bot->setPosX($nx)
            ->setPosZ($nz)
            ->setPosY($this->terrainService->getHeight($nx, $nz))
            ->setZoneCode($this->terrainService->getZoneCode($nx, $nz))
            ->setRotY(atan2($dx, $dz))
            ->setIsMoving(true);
    }

    private function resolvePartyLeader(Party $party, Character $exclude): ?Character
    {
        $firstFallback = null;
        foreach ($party->getMembers() as $member) {
            if ($member->isDead() || $member->getId() === $exclude->getId()) {
                continue;
            }
            if (!$member->isBot()) {
                return $member;
            }
            if ($member->getId() === $party->getLeaderCharacterId()) {
                return $member;
            }
            $firstFallback ??= $member;
        }

        return $firstFallback;
    }

    private function relocateBotNear(Character $bot, Character $target, float $now): void
    {
        $angle = (mt_rand(0, 359) * M_PI) / 180.0;
        $pos = $this->terrainService->findNearestWalkable(
            $target->getPosX() + cos($angle) * 5.5,
            $target->getPosZ() + sin($angle) * 5.5,
        );
        $bot->setPosX($pos['x'])
            ->setPosY($pos['y'])
            ->setPosZ($pos['z'])
            ->setZoneCode($target->getZoneCode())
            ->setIsMoving(false)
            ->setLastSeenAt($now);
    }

    private function detachFromParty(Character $bot): void
    {
        $party = $bot->getParty();
        if ($party === null) {
            return;
        }

        $wasLeader = $party->getLeaderCharacterId() === $bot->getId();
        $bot->setParty(null);

        if ($party->getMembers()->count() === 0) {
            $this->em->remove($party);

            return;
        }

        if ($wasLeader) {
            $newLeader = null;
            foreach ($party->getMembers() as $member) {
                if (!$member->isBot()) {
                    $newLeader = $member;
                    break;
                }
                $newLeader ??= $member;
            }
            if ($newLeader instanceof Character) {
                $party->setLeaderCharacterId((int) $newLeader->getId());
            }
        }
    }

    private function getOrCreateBotOwner(): User
    {
        $existing = $this->userRepository->findOneBy(['username' => self::BOT_OWNER_USERNAME]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setUsername(self::BOT_OWNER_USERNAME)
            ->setEmail('bots@symfonyforever.re')
            ->setRoles(['ROLE_BOT'])
            ->setPassword('!bot_locked!');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
