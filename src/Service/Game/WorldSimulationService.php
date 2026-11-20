<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\ChatMessage;
use App\Entity\CombatEvent;
use App\Entity\MonsterInstance;
use App\Entity\MonsterTemplate;
use App\Entity\Zone;
use App\Game\CharacterClass;
use App\Game\MonsterRespawnScaler;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Repository\CombatEventRepository;
use App\Repository\MonsterInstanceRepository;
use App\Repository\WorldLootDropRepository;
use App\Repository\ZoneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Lazy Locked Zone Tick Simulation (runs at most once per second per active zone
 * directly inside HTTP sync requests — zero background daemon required!).
 */
final class WorldSimulationService
{
    public const float TICK_INTERVAL = 1.0;
    public const float ACTIVE_MONSTER_SIM_RADIUS = 220.0;
    public const float LEASH_DISTANCE = 52.0;
    public const float WORLD_BOSS_LEASH_DISTANCE = 78.0;
    public const float FLEE_SPEED_MULTIPLIER = 0.85;
    public const float HELP_ALERT_ARRIVAL_RADIUS = 8.0;
    public const float HELP_CLUSTER_RADIUS = 14.0;
    public const int MAX_ALLIES_SUMMONED_PER_CALL = 2;
    public const int WORLD_BOSS_MIN_PLAYERS_NO_REGEN = 3;
    public const float WORLD_BOSS_AOE_RADIUS = 26.0;
    public const float WORLD_BOSS_AOE_COOLDOWN_P1 = 7.0;
    public const float WORLD_BOSS_AOE_COOLDOWN_P2 = 5.5;
    public const float NIGHT_AGGRO_MULTIPLIER = 2.0;
    public const float NIGHT_MIN_AGGRO_RADIUS = 24.0;
    public const float NIGHT_MAX_AGGRO_RADIUS = 42.0;
    public const float NIGHT_LEASH_BONUS = 18.0;
    public const float NIGHT_MOVE_SPEED_MULTIPLIER = 1.15;
    public const float NIGHT_ATTACK_INTERVAL_MULTIPLIER = 0.85;
    public const float AGGRO_MAX_VERTICAL_DIFF = 18.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly ZoneRepository $zoneRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly MonsterInstanceRepository $monsterRepository,
        private readonly CombatEventRepository $combatEventRepository,
        private readonly WorldLootDropRepository $lootRepository,
        private readonly ReunionTerrainService $terrainService,
        private readonly StatCalculator $statCalculator,
        private readonly CombatService $combatService,
        private readonly WorldAtmosphereService $atmosphereService,
    ) {
    }

    public static function isMonsterAggressiveAt(MonsterTemplate $template, bool $isNight): bool
    {
        return $template->isAggressive() || $isNight;
    }

    public static function computeEffectiveAggroRadius(MonsterTemplate $template, bool $isNight): float
    {
        if (!$isNight) {
            return $template->getAggroRadius();
        }

        return min(
            self::NIGHT_MAX_AGGRO_RADIUS,
            max(self::NIGHT_MIN_AGGRO_RADIUS, round($template->getAggroRadius() * self::NIGHT_AGGRO_MULTIPLIER, 2)),
        );
    }

    public function maybeTickZone(string $zoneCode, ?float $now = null): bool
    {
        $now ??= microtime(true);

        $lock = $this->lockFactory->createLock('zone_tick_'.$zoneCode, 3.0);
        if (!$lock->acquire(false)) {
            return false;
        }

        try {
            $zone = $this->zoneRepository->find($zoneCode);
            if (!$zone instanceof Zone) {
                return false;
            }

            $dt = $now - $zone->getLastTickAt();
            if ($dt < self::TICK_INTERVAL) {
                return false;
            }

            $step = min(2.5, max(0.5, $dt));
            $zone->setLastTickAt($now);

            $players = $this->characterRepository->findOnlineInZone($zoneCode, $now);
            $playersById = [];
            foreach ($players as $p) {
                $playersById[(int) $p->getId()] = $p;
            }

            // 1. Tick player HoTs, buff expiration & out-of-combat regeneration
            foreach ($players as $player) {
                $this->tickPlayer($player, $step, $now);
            }

            // 2. Tick active monsters (respawn, DoTs, combat, fleeing, returning, and IDLE mobs near alive online players)
            $monsters = $this->monsterRepository->findByZone($zoneCode);
            $monstersById = [];
            foreach ($monsters as $m) {
                if ($m->getId() !== null) {
                    $monstersById[(int) $m->getId()] = $m;
                }
            }
            $createdEvents = [];
            $alivePlayers = array_values(array_filter(
                $players,
                static fn (Character $p): bool => !$p->isDead(),
            ));
            $activeRadiusSq = self::ACTIVE_MONSTER_SIM_RADIUS * self::ACTIVE_MONSTER_SIM_RADIUS;
            $isNight = $this->atmosphereService->getCurrentAtmosphere($now)['isNight'];

            foreach ($monsters as $monster) {
                if ($monster->getState() === MonsterInstance::STATE_IDLE && $monster->getActiveEffects() === []) {
                    $hasNearbyPlayer = false;
                    $mx = $monster->getPosX();
                    $mz = $monster->getPosZ();
                    foreach ($alivePlayers as $ap) {
                        $dx = $ap->getPosX() - $mx;
                        $dz = $ap->getPosZ() - $mz;
                        if (($dx * $dx + $dz * $dz) <= $activeRadiusSq) {
                            $hasNearbyPlayer = true;
                            break;
                        }
                    }
                    if (!$hasNearbyPlayer) {
                        continue;
                    }
                }

                $this->tickMonster($monster, $playersById, $monstersById, $step, $now, $createdEvents, $isNight);
            }

            // 3. Opportunistic cleanup of old combat events (> 35s) & expired ground loot
            if (mt_rand(1, 10) === 1) {
                $this->combatEventRepository->purgeOlderThan($now - 35.0);
                $this->lootRepository->purgeExpired($now);
            }

            $this->em->flush();

            return true;
        } finally {
            $lock->release();
        }
    }

    private function tickPlayer(Character $player, float $step, float $now): void
    {
        if ($player->isDead()) {
            return;
        }

        $stats = $this->statCalculator->compute($player, $now);
        $remainingEffects = [];

        foreach ($player->getActiveEffects() as $effect) {
            if ($effect['expiresAt'] <= $now) {
                continue;
            }

            if ($effect['type'] === 'HOT' && $effect['nextTickAt'] <= $now) {
                $heal = max(1, (int) round((float) $effect['value']));
                $player->setCurrentHp(min($stats['maxHp'], $player->getCurrentHp() + $heal));
                $effect['nextTickAt'] = $now + max(1.0, (float) $effect['tickInterval']);

                $ev = new CombatEvent(
                    $player->getZoneCode(),
                    'HOT',
                    'PLAYER',
                    (int) $effect['sourceId'],
                    '',
                    'PLAYER',
                    (int) $player->getId(),
                    $player->getName(),
                    (string) $effect['skillCode'],
                    $heal,
                    false,
                    $player->getPosX(),
                    $player->getPosY(),
                    $player->getPosZ(),
                    $now,
                );
                $this->em->persist($ev);
            }

            $remainingEffects[] = $effect;
        }

        $player->setActiveEffects($remainingEffects);

        // Regeneration: fast out of combat (6s), class-specific resource flow (Rage/Énergie/Mana)
        $inCombat = ($now - $player->getLastCombatAt()) < 6.0;
        $cls = $player->getCharacterClass();
        if (!$inCombat) {
            $hpGain = (int) round($stats['hpRegenPerSec'] * $step * 2.2);
            $player->setCurrentHp(min($stats['maxHp'], $player->getCurrentHp() + $hpGain));
        }

        if ($cls === CharacterClass::DPS) {
            $rate = $inCombat ? 10.0 : 16.0;
            $player->setCurrentMp(min($stats['maxMp'], $player->getCurrentMp() + (int) round($rate * $step)));
        } elseif ($cls === CharacterClass::TANK) {
            if ($inCombat) {
                $player->setCurrentMp(min($stats['maxMp'], $player->getCurrentMp() + (int) round(4.0 * $step)));
            } else {
                // Settle toward 30 baseline Rage out of combat so Tanks can always open with Shield Bash / Taunt
                $cur = min($stats['maxMp'], $player->getCurrentMp());
                if ($cur < 30) {
                    $player->setCurrentMp(min(30, $cur + (int) round(10.0 * $step)));
                } elseif ($cur > 30) {
                    $player->setCurrentMp(max(30, $cur - (int) round(4.0 * $step)));
                }
            }
        } else {
            $mult = $inCombat ? 0.55 : 1.8;
            $mpGain = (int) round($stats['mpRegenPerSec'] * $step * $mult);
            $player->setCurrentMp(min($stats['maxMp'], $player->getCurrentMp() + $mpGain));
        }
    }

    /**
     * @param array<int, Character>       $playersById
     * @param array<int, MonsterInstance> $monstersById
     * @param list<CombatEvent>           $createdEvents
     */
    private function tickMonster(
        MonsterInstance $monster,
        array $playersById,
        array $monstersById,
        float $step,
        float $now,
        array &$createdEvents,
        bool $isNight = false,
    ): void {
        $template = $monster->getTemplate();

        // Respawn check
        if ($monster->getState() === MonsterInstance::STATE_DEAD) {
            $effectiveRespawn = MonsterRespawnScaler::computeRespawnSeconds($template, count($playersById));
            $maxRespawnAt = $now + $effectiveRespawn;
            if ($monster->getRespawnAt() > $maxRespawnAt) {
                $monster->setRespawnAt($maxRespawnAt);
            }

            if ($monster->getRespawnAt() <= $now) {
                $monster->setState(MonsterInstance::STATE_IDLE);
                $monster->setCurrentHp($template->getMaxHp());
                $monster->setPosX($monster->getSpawnX());
                $monster->setPosY($monster->getSpawnY());
                $monster->setPosZ($monster->getSpawnZ());
                $monster->setThreatTable([]);
                $monster->setRaidContributors([]);
                $monster->setActiveEffects([]);
                $monster->setPhase(1);
                $monster->setLastSpecialAt(0.0);
                $monster->setHelpTargetMonsterId(null);
                $monster->setHasCalledHelp(false);

                if ($template->isWorldBoss()) {
                    $respawnMsg = new ChatMessage(
                        ChatMessage::CHANNEL_SYSTEM,
                        'Île de La Réunion',
                        '',
                        sprintf(
                            '🌋 [WORLD BOSS] La terre tremble ! %s s\'éveille au Cratère Dolomieu (Piton de la Fournaise). Alliez-vous jusqu\'à 10 joueurs !',
                            $template->getName(),
                        ),
                    );
                    $this->em->persist($respawnMsg);
                }
            }

            return;
        }

        // Tick DoTs on monster
        $remainingEffects = [];
        foreach ($monster->getActiveEffects() as $effect) {
            if ($effect['expiresAt'] <= $now) {
                continue;
            }
            if ($effect['type'] === 'DOT' && $effect['nextTickAt'] <= $now) {
                $dmg = max(1, (int) round((float) $effect['value']));
                $monster->setCurrentHp($monster->getCurrentHp() - $dmg);
                $effect['nextTickAt'] = $now + max(1.0, (float) $effect['tickInterval']);

                $source = $playersById[(int) $effect['sourceId']] ?? null;
                $ev = new CombatEvent(
                    $monster->getZoneCode(),
                    'DOT',
                    'PLAYER',
                    (int) $effect['sourceId'],
                    $source?->getName() ?? '',
                    'MONSTER',
                    (int) $monster->getId(),
                    $template->getName(),
                    (string) $effect['skillCode'],
                    $dmg,
                    false,
                    $monster->getPosX(),
                    $monster->getPosY(),
                    $monster->getPosZ(),
                    $now,
                );
                $this->em->persist($ev);
                $createdEvents[] = $ev;

                if ($monster->getCurrentHp() <= 0) {
                    $this->combatService->handleMonsterDeath($monster, $source, $now, $createdEvents);

                    return;
                }
            }
            $remainingEffects[] = $effect;
        }
        $monster->setActiveEffects($remainingEffects);

        // Returning to spawn
        if ($monster->getState() === MonsterInstance::STATE_RETURNING) {
            $distSpawn = hypot($monster->getSpawnX() - $monster->getPosX(), $monster->getSpawnZ() - $monster->getPosZ());
            if ($distSpawn <= 3.0) {
                $monster->setPosX($monster->getSpawnX());
                $monster->setPosY($monster->getSpawnY());
                $monster->setPosZ($monster->getSpawnZ());
                $monster->setCurrentHp($template->getMaxHp());
                $monster->setState(MonsterInstance::STATE_IDLE);
                $monster->setThreatTable([]);
                $monster->setRaidContributors([]);
                $monster->setPhase(1);
                $monster->setLastSpecialAt(0.0);
                $monster->setHelpTargetMonsterId(null);
                $monster->setHasCalledHelp(false);
            } else {
                $this->moveTowards($monster, $monster->getSpawnX(), $monster->getSpawnZ(), $template->getMoveSpeed() * 1.4 * $step);
                $monster->setCurrentHp(min($template->getMaxHp(), $monster->getCurrentHp() + (int) round($template->getMaxHp() * 0.2)));
            }

            return;
        }

        // Prune dead or offline players from threat table
        $threat = $monster->getThreatTable();
        $pruned = [];
        foreach ($threat as $charIdStr => $score) {
            $cid = (int) $charIdStr;
            if (isset($playersById[$cid]) && !$playersById[$cid]->isDead()) {
                $pruned[$charIdStr] = $score;
            }
        }
        $monster->setThreatTable($pruned);

        if ($monster->getTauntedByCharacterId() !== null) {
            $tid = $monster->getTauntedByCharacterId();
            if ($monster->getTauntExpiresAt() <= $now || !isset($playersById[$tid]) || $playersById[$tid]->isDead()) {
                $monster->setTauntedByCharacterId(null);
                $monster->setTauntExpiresAt(0.0);
            }
        }

        // Idle: check aggro radius if aggressive (boosted at night outside sanctuaries), else occasional idle wander
        if ($monster->getState() === MonsterInstance::STATE_IDLE) {
            if (self::isMonsterAggressiveAt($template, $isNight)) {
                $closest = null;
                $bestDist = self::computeEffectiveAggroRadius($template, $isNight);
                foreach ($playersById as $p) {
                    if ($p->isDead()) {
                        continue;
                    }
                    if (WorldCatalog::isInSafeSanctuary($p->getPosX(), $p->getPosZ())) {
                        continue;
                    }
                    if (abs($p->getPosY() - $monster->getPosY()) > self::AGGRO_MAX_VERTICAL_DIFF) {
                        continue;
                    }
                    $d = hypot($p->getPosX() - $monster->getPosX(), $p->getPosZ() - $monster->getPosZ());
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $closest = $p;
                    }
                }
                if ($closest !== null) {
                    $monster->addThreat((int) $closest->getId(), 15.0);
                    $monster->setState(MonsterInstance::STATE_COMBAT);
                    if ($template->isWorldBoss()) {
                        $monster->addRaidContribution((int) $closest->getId(), 10.0);
                        $pullMsg = new ChatMessage(
                            ChatMessage::CHANNEL_SYSTEM,
                            'Île de La Réunion',
                            '',
                            sprintf(
                                '🌋 [WORLD BOSS] %s a réveillé %s au Cratère Dolomieu ! Rassemblez jusqu\'à 10 aventuriers !',
                                $closest->getName(),
                                $template->getName(),
                            ),
                        );
                        $this->em->persist($pullMsg);
                    }
                }
            }

            if ($monster->getState() === MonsterInstance::STATE_IDLE) {
                // Regenerate & subtle wander around spawn
                if ($monster->getCurrentHp() < $template->getMaxHp()) {
                    $monster->setCurrentHp(min($template->getMaxHp(), $monster->getCurrentHp() + (int) ceil($template->getMaxHp() * 0.1)));
                }
                if (mt_rand(1, 5) === 1) {
                    $angle = (mt_rand(0, 359) * M_PI) / 180.0;
                    $wx = $monster->getSpawnX() + cos($angle) * mt_rand(2, 7);
                    $wz = $monster->getSpawnZ() + sin($angle) * mt_rand(2, 7);
                    if ($this->terrainService->isWalkable($wx, $wz)) {
                        $this->moveTowards($monster, $wx, $wz, min(3.5, $template->getMoveSpeed() * 0.4 * $step));
                    }
                }
            }

            return;
        }

        // COMBAT / FLEEING state
        $distFromSpawn = hypot($monster->getPosX() - $monster->getSpawnX(), $monster->getPosZ() - $monster->getSpawnZ());
        $baseLeash = self::LEASH_DISTANCE + ($isNight ? self::NIGHT_LEASH_BONUS : 0.0);
        $leash = $template->isWorldBoss()
            ? self::WORLD_BOSS_LEASH_DISTANCE
            : (($monster->getState() === MonsterInstance::STATE_FLEEING || $monster->hasCalledHelp())
                ? max($baseLeash, $template->getCallHelpRadius() + 35.0)
                : $baseLeash);
        $targetId = $monster->getTopThreatCharacterId($now);
        $target = ($targetId !== null && isset($playersById[$targetId])) ? $playersById[$targetId] : null;

        if ($target === null || $target->isDead() || $distFromSpawn > $leash) {
            $monster->setState(MonsterInstance::STATE_RETURNING);
            $monster->setThreatTable([]);
            $monster->setRaidContributors([]);
            $monster->setTauntedByCharacterId(null);
            $monster->setPhase(1);
            $monster->setLastSpecialAt(0.0);
            $monster->setHelpTargetMonsterId(null);
            $monster->setHasCalledHelp(false);

            return;
        }

        // WORLD BOSS SPECIAL MECHANICS: Anti-solo Carapace Magmatique, Phase 2 Fureur & Raid AoE Eruption!
        if ($template->isWorldBoss()) {
            $hpRatio = $monster->getCurrentHp() / max(1, $template->getMaxHp());

            // Phase 2 transition (< 50% HP)
            if ($hpRatio <= 0.50 && $monster->getPhase() < 2) {
                $monster->setPhase(2);
                $p2Msg = new ChatMessage(
                    ChatMessage::CHANNEL_SYSTEM,
                    'Île de La Réunion',
                    '',
                    sprintf(
                        '🔥 [WORLD BOSS] %s entre en Fureur Magmatique (Phase 2 — 50%% PV) ! Ses attaques et éruptions s\'intensifient !',
                        $template->getName(),
                    ),
                );
                $this->em->persist($p2Msg);
            }

            // Anti-solo Carapace Magmatique: < 3 active engaged players => regenerates 2% Max HP / sec (~560 HP/s)
            $activeEngaged = count($pruned);
            if ($activeEngaged < self::WORLD_BOSS_MIN_PLAYERS_NO_REGEN) {
                $regen = (int) ceil($template->getMaxHp() * 0.02 * $step);
                $monster->setCurrentHp(min($template->getMaxHp(), $monster->getCurrentHp() + $regen));
            }

            // Periodic Raid AoE: Éruption du Dolomieu (every 7.0s in P1, 5.5s in P2)
            $aoeCd = $monster->getPhase() >= 2 ? self::WORLD_BOSS_AOE_COOLDOWN_P2 : self::WORLD_BOSS_AOE_COOLDOWN_P1;
            if ($monster->getLastSpecialAt() <= 0.0) {
                $monster->setLastSpecialAt($now - ($aoeCd - 4.0));
            } elseif (($now - $monster->getLastSpecialAt()) >= $aoeCd) {
                $this->performWorldBossEruption($monster, $playersById, $now, $createdEvents);
            }
        }

        // Social AI: seek nearby idle same-species compatriots when HP drops below threshold
        if (
            $monster->getState() === MonsterInstance::STATE_COMBAT
            && $template->isSocial()
            && !$template->isWorldBoss()
            && !$monster->hasCalledHelp()
            && $monster->getTauntedByCharacterId() === null
            && $monster->getCurrentHp() > 0
            && ($monster->getCurrentHp() / max(1, $template->getMaxHp())) <= $template->getFleeHpRatio()
        ) {
            $ally = $this->findNearestIdleCompatriot($monster, $monstersById, $template->getCallHelpRadius());
            if ($ally !== null) {
                $monster->setState(MonsterInstance::STATE_FLEEING)
                    ->setHelpTargetMonsterId((int) $ally->getId());

                $fleeEv = new CombatEvent(
                    $monster->getZoneCode(),
                    'FLEE',
                    'MONSTER',
                    (int) $monster->getId(),
                    $template->getName(),
                    'MONSTER',
                    (int) $ally->getId(),
                    $ally->getTemplate()->getName(),
                    'seek_help',
                    0,
                    false,
                    $monster->getPosX(),
                    $monster->getPosY(),
                    $monster->getPosZ(),
                    $now,
                );
                $this->em->persist($fleeEv);
                $createdEvents[] = $fleeEv;
            }
        }

        if ($monster->getState() === MonsterInstance::STATE_FLEEING) {
            $allyId = $monster->getHelpTargetMonsterId();
            $ally = $allyId !== null ? ($monstersById[$allyId] ?? null) : null;
            if ($ally === null || $ally->getState() !== MonsterInstance::STATE_IDLE) {
                $ally = $this->findNearestIdleCompatriot($monster, $monstersById, $template->getCallHelpRadius());
            }

            if ($ally === null) {
                $monster->setState(MonsterInstance::STATE_COMBAT)
                    ->setHelpTargetMonsterId(null);
            } else {
                $monster->setHelpTargetMonsterId((int) $ally->getId());
                $distToAlly = hypot($ally->getPosX() - $monster->getPosX(), $ally->getPosZ() - $monster->getPosZ());

                if ($distToAlly > self::HELP_ALERT_ARRIVAL_RADIUS) {
                    $prevX = $monster->getPosX();
                    $prevZ = $monster->getPosZ();
                    $this->moveTowards($monster, $ally->getPosX(), $ally->getPosZ(), $template->getMoveSpeed() * self::FLEE_SPEED_MULTIPLIER * $step);
                    $moved = hypot($monster->getPosX() - $prevX, $monster->getPosZ() - $prevZ);
                    $distToAlly = hypot($ally->getPosX() - $monster->getPosX(), $ally->getPosZ() - $monster->getPosZ());

                    if ($moved < 0.05 && $distToAlly > self::HELP_ALERT_ARRIVAL_RADIUS) {
                        $monster->setState(MonsterInstance::STATE_COMBAT)
                            ->setHelpTargetMonsterId(null);
                    } elseif ($distToAlly > self::HELP_ALERT_ARRIVAL_RADIUS) {
                        return;
                    }
                }

                if ($monster->getState() === MonsterInstance::STATE_FLEEING && $distToAlly <= self::HELP_ALERT_ARRIVAL_RADIUS) {
                    $candidates = [];
                    $clusterSq = self::HELP_CLUSTER_RADIUS * self::HELP_CLUSTER_RADIUS;
                    foreach ($monstersById as $cand) {
                        if (
                            $cand->getId() === $monster->getId()
                            || $cand->getTemplate()->getId() !== $template->getId()
                            || $cand->getState() !== MonsterInstance::STATE_IDLE
                        ) {
                            continue;
                        }
                        if ($cand->getId() === $ally->getId()) {
                            $candidates[] = ['d2' => 0.0, 'm' => $cand];
                            continue;
                        }
                        $dx = $cand->getPosX() - $ally->getPosX();
                        $dz = $cand->getPosZ() - $ally->getPosZ();
                        $d2 = $dx * $dx + $dz * $dz;
                        if ($d2 <= $clusterSq) {
                            $candidates[] = ['d2' => $d2, 'm' => $cand];
                        }
                    }

                    usort($candidates, static fn (array $a, array $b): int => $a['d2'] <=> $b['d2']);
                    $recruitedAllies = array_map(
                        static fn (array $row): MonsterInstance => $row['m'],
                        array_slice($candidates, 0, self::MAX_ALLIES_SUMMONED_PER_CALL),
                    );

                    foreach ($recruitedAllies as $recruited) {
                        foreach ($monster->getThreatTable() as $cidStr => $score) {
                            $recruited->addThreat((int) $cidStr, max(15.0, $score * 0.5));
                        }
                        if (!array_key_exists((string) $target->getId(), $recruited->getThreatTable())) {
                            $recruited->addThreat((int) $target->getId(), 20.0);
                        }
                        $recruited->setState(MonsterInstance::STATE_COMBAT)
                            ->setHasCalledHelp(true)
                            ->setHelpTargetMonsterId(null);
                        $recruited->setRotY(atan2($target->getPosX() - $recruited->getPosX(), $target->getPosZ() - $recruited->getPosZ()));
                    }

                    $monster->setState(MonsterInstance::STATE_COMBAT)
                        ->setHasCalledHelp(true)
                        ->setHelpTargetMonsterId(null);

                    $helpEv = new CombatEvent(
                        $monster->getZoneCode(),
                        'CALL_HELP',
                        'MONSTER',
                        (int) $monster->getId(),
                        $template->getName(),
                        'MONSTER',
                        (int) $ally->getId(),
                        $ally->getTemplate()->getName(),
                        'call_help',
                        count($recruitedAllies),
                        false,
                        $ally->getPosX(),
                        $ally->getPosY(),
                        $ally->getPosZ(),
                        $now,
                    );
                    $this->em->persist($helpEv);
                    $createdEvents[] = $helpEv;

                    return;
                }
            }
        }

        $distTarget = hypot($target->getPosX() - $monster->getPosX(), $target->getPosZ() - $monster->getPosZ());
        if ($distTarget > $template->getAttackRange()) {
            $chaseSpeed = $template->getMoveSpeed() * ($isNight ? self::NIGHT_MOVE_SPEED_MULTIPLIER : 1.0);
            $this->moveTowards($monster, $target->getPosX(), $target->getPosZ(), $chaseSpeed * $step);
        } else {
            // Face target and attack if off cooldown
            $monster->setRotY(atan2($target->getPosX() - $monster->getPosX(), $target->getPosZ() - $monster->getPosZ()));
            $attackInterval = $template->getAttackInterval() * ($isNight ? self::NIGHT_ATTACK_INTERVAL_MULTIPLIER : 1.0);
            if (($now - $monster->getLastAttackAt()) >= $attackInterval) {
                $this->performMonsterAttack($monster, $target, $now, $createdEvents);
            }
        }
    }

    /**
     * World Boss periodic raid-wide volcanic shockwave hitting all alive players within 26m.
     *
     * @param array<int, Character> $playersById
     * @param list<CombatEvent>     $createdEvents
     */
    private function performWorldBossEruption(
        MonsterInstance $monster,
        array $playersById,
        float $now,
        array &$createdEvents,
    ): void {
        $template = $monster->getTemplate();
        $monster->setLastSpecialAt($now);
        $rawBase = $template->getAttack() * ($monster->getPhase() >= 2 ? 0.72 : 0.55);

        foreach ($playersById as $player) {
            if ($player->isDead()) {
                continue;
            }
            $dist = hypot($player->getPosX() - $monster->getPosX(), $player->getPosZ() - $monster->getPosZ());
            if ($dist > self::WORLD_BOSS_AOE_RADIUS) {
                continue;
            }

            $stats = $this->statCalculator->compute($player, $now);
            $variance = 0.90 + (mt_rand(0, 200) / 1000.0);
            $dmg = max(1, (int) round($rawBase * $variance * (1.0 - (float) $stats['damageReduction'])));

            $player->setCurrentHp($player->getCurrentHp() - $dmg);
            $player->setLastCombatAt($now);

            $ev = new CombatEvent(
                $monster->getZoneCode(),
                'DAMAGE',
                'MONSTER',
                (int) $monster->getId(),
                $template->getName(),
                'PLAYER',
                (int) $player->getId(),
                $player->getName(),
                'boss_eruption',
                $dmg,
                $monster->getPhase() >= 2,
                $player->getPosX(),
                $player->getPosY(),
                $player->getPosZ(),
                $now,
            );
            $this->em->persist($ev);
            $createdEvents[] = $ev;

            if ($player->getCurrentHp() <= 0) {
                $this->handlePlayerDeath($player, $monster, $now, $createdEvents);
            }
        }
    }

    /**
     * @param list<CombatEvent> $createdEvents
     */
    private function performMonsterAttack(
        MonsterInstance $monster,
        Character $target,
        float $now,
        array &$createdEvents,
    ): void {
        $template = $monster->getTemplate();
        $stats = $this->statCalculator->compute($target, $now);

        if ($template->isWorldBoss()) {
            $minTankLevel = max(1, $template->getLevel() - 5);
            if ($target->getCharacterClass() !== CharacterClass::TANK || $target->getLevel() < $minTankLevel) {
                // Guaranteed ONE-SHOT on non-Tank or under-leveled characters!
                $dmg = max($stats['maxHp'] + 250, (int) round($stats['maxHp'] * 1.35));
            } else {
                // Level 10+ Tank tanks at most 3 hits unbuffed/unhealed (36.5%-39% maxHp per swing)
                $unbuffedFactor = 0.365 + (mt_rand(0, 25) / 1000.0);
                $buffMult = 1.0 - (float) $stats['activeDamageReductionBuff'];
                $phaseMult = $monster->getPhase() >= 2 ? 1.15 : 1.0;
                $dmg = max(1, (int) round($stats['maxHp'] * $unbuffedFactor * $buffMult * $phaseMult));
            }
        } else {
            $variance = 0.88 + (mt_rand(0, 240) / 1000.0);
            $raw = $template->getAttack() * $variance;
            $mitigated = $raw * (1.0 - (float) $stats['damageReduction']);
            $dmg = max(1, (int) round($mitigated));
        }

        $target->setCurrentHp($target->getCurrentHp() - $dmg);
        $target->setLastCombatAt($now);
        $monster->setLastAttackAt($now);

        if ($target->getCharacterClass() === CharacterClass::TANK && $target->getCurrentHp() > 0) {
            $target->setCurrentMp(min($stats['maxMp'], $target->getCurrentMp() + 8));
        }

        $ev = new CombatEvent(
            $monster->getZoneCode(),
            'DAMAGE',
            'MONSTER',
            (int) $monster->getId(),
            $template->getName(),
            'PLAYER',
            (int) $target->getId(),
            $target->getName(),
            'monster_attack',
            $dmg,
            false,
            $target->getPosX(),
            $target->getPosY(),
            $target->getPosZ(),
            $now,
        );
        $this->em->persist($ev);
        $createdEvents[] = $ev;

        if ($target->getCurrentHp() <= 0) {
            $this->handlePlayerDeath($target, $monster, $now, $createdEvents);
        }
    }

    /**
     * Applies player defeat state, records DEATH combat event, and enforces permadeath
     * ungrouping for AI player-bots.
     *
     * @param list<CombatEvent> $createdEvents
     */
    private function handlePlayerDeath(
        Character $victim,
        MonsterInstance $killer,
        float $now,
        array &$createdEvents,
    ): void {
        $victim->setCurrentHp(0)
            ->setIsDead(true)
            ->setActiveMountCode(null)
            ->setActiveEffects([])
            ->setPendingPartyInviteId(null)
            ->setIsMoving(false);

        $deathEv = new CombatEvent(
            $victim->getZoneCode(),
            'DEATH',
            'MONSTER',
            (int) $killer->getId(),
            $killer->getTemplate()->getName(),
            'PLAYER',
            (int) $victim->getId(),
            $victim->getName(),
            'death',
            0,
            false,
            $victim->getPosX(),
            $victim->getPosY(),
            $victim->getPosZ(),
            $now,
        );
        $this->em->persist($deathEv);
        $createdEvents[] = $deathEv;

        if (!$victim->isBot()) {
            return;
        }

        $victim->setLastSeenAt(0.0)
            ->setTargetType(null)
            ->setTargetId(null);

        $party = $victim->getParty();
        if ($party !== null) {
            $partyMsg = new ChatMessage(
                ChatMessage::CHANNEL_PARTY,
                'Système',
                '',
                sprintf('💀 %s (Niv. %d) est tombé au combat — mort définitive, il quitte le groupe.', $victim->getName(), $victim->getLevel()),
                null,
                $party->getId(),
            );
            $this->em->persist($partyMsg);

            $wasLeader = $party->getLeaderCharacterId() === $victim->getId();
            $victim->setParty(null);

            if ($party->getMembers()->count() === 0) {
                $this->em->remove($party);
            } elseif ($wasLeader) {
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
    }

    private function moveTowards(MonsterInstance $monster, float $tx, float $tz, float $maxStep): void
    {
        $dx = $tx - $monster->getPosX();
        $dz = $tz - $monster->getPosZ();
        $dist = hypot($dx, $dz);
        if ($dist < 0.05) {
            return;
        }

        $step = min($dist, $maxStep);
        $nx = $monster->getPosX() + ($dx / $dist) * $step;
        $nz = $monster->getPosZ() + ($dz / $dist) * $step;

        if ($this->terrainService->isWalkable($nx, $nz)) {
            $monster->setPosX($nx);
            $monster->setPosZ($nz);
            $monster->setPosY($this->terrainService->getHeight($nx, $nz));
        }

        $monster->setRotY(atan2($dx, $dz));
    }

    /**
     * @param array<int, MonsterInstance> $monstersById
     */
    private function findNearestIdleCompatriot(MonsterInstance $caller, array $monstersById, float $radius): ?MonsterInstance
    {
        if ($radius <= 0.0) {
            return null;
        }

        $tplId = $caller->getTemplate()->getId();
        $callerId = $caller->getId();
        $best = null;
        $bestDistSq = $radius * $radius;

        foreach ($monstersById as $cand) {
            if (
                $cand->getId() === $callerId
                || $cand->getTemplate()->getId() !== $tplId
                || $cand->getState() !== MonsterInstance::STATE_IDLE
            ) {
                continue;
            }

            $dx = $cand->getPosX() - $caller->getPosX();
            $dz = $cand->getPosZ() - $caller->getPosZ();
            $d2 = $dx * $dx + $dz * $dz;
            if ($d2 <= $bestDistSq) {
                $bestDistSq = $d2;
                $best = $cand;
            }
        }

        return $best;
    }
}
