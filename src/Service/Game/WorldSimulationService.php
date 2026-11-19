<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\CombatEvent;
use App\Entity\MonsterInstance;
use App\Entity\Zone;
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
    public const float LEASH_DISTANCE = 52.0;

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
    ) {
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

            // 2. Tick monsters (respawn, DoTs, aggro, movement, melee attack, leash)
            $monsters = $this->monsterRepository->findByZone($zoneCode);
            $createdEvents = [];

            foreach ($monsters as $monster) {
                $this->tickMonster($monster, $playersById, $step, $now, $createdEvents);
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

        // Regeneration: fast out of combat (6s), modest MP regen in combat
        $inCombat = ($now - $player->getLastCombatAt()) < 6.0;
        if (!$inCombat) {
            $hpGain = (int) round($stats['hpRegenPerSec'] * $step * 2.2);
            $mpGain = (int) round($stats['mpRegenPerSec'] * $step * 1.8);
            $player->setCurrentHp(min($stats['maxHp'], $player->getCurrentHp() + $hpGain));
            $player->setCurrentMp(min($stats['maxMp'], $player->getCurrentMp() + $mpGain));
        } else {
            $mpGain = (int) round($stats['mpRegenPerSec'] * $step * 0.55);
            $player->setCurrentMp(min($stats['maxMp'], $player->getCurrentMp() + $mpGain));
        }
    }

    /**
     * @param array<int, Character> $playersById
     * @param list<CombatEvent>     $createdEvents
     */
    private function tickMonster(
        MonsterInstance $monster,
        array $playersById,
        float $step,
        float $now,
        array &$createdEvents,
    ): void {
        $template = $monster->getTemplate();

        // Respawn check
        if ($monster->getState() === MonsterInstance::STATE_DEAD) {
            if ($monster->getRespawnAt() <= $now) {
                $monster->setState(MonsterInstance::STATE_IDLE);
                $monster->setCurrentHp($template->getMaxHp());
                $monster->setPosX($monster->getSpawnX());
                $monster->setPosY($monster->getSpawnY());
                $monster->setPosZ($monster->getSpawnZ());
                $monster->setThreatTable([]);
                $monster->setActiveEffects([]);
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

        // Idle: check aggro radius if aggressive, else occasional idle wander
        if ($monster->getState() === MonsterInstance::STATE_IDLE) {
            if ($template->isAggressive()) {
                $closest = null;
                $bestDist = $template->getAggroRadius();
                foreach ($playersById as $p) {
                    if ($p->isDead()) {
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

        // COMBAT state
        $distFromSpawn = hypot($monster->getPosX() - $monster->getSpawnX(), $monster->getPosZ() - $monster->getSpawnZ());
        $targetId = $monster->getTopThreatCharacterId($now);
        $target = ($targetId !== null && isset($playersById[$targetId])) ? $playersById[$targetId] : null;

        if ($target === null || $target->isDead() || $distFromSpawn > self::LEASH_DISTANCE) {
            $monster->setState(MonsterInstance::STATE_RETURNING);
            $monster->setThreatTable([]);
            $monster->setTauntedByCharacterId(null);

            return;
        }

        $distTarget = hypot($target->getPosX() - $monster->getPosX(), $target->getPosZ() - $monster->getPosZ());
        if ($distTarget > $template->getAttackRange()) {
            $this->moveTowards($monster, $target->getPosX(), $target->getPosZ(), $template->getMoveSpeed() * $step);
        } else {
            // Face target and attack if off cooldown
            $monster->setRotY(atan2($target->getPosX() - $monster->getPosX(), $target->getPosZ() - $monster->getPosZ()));
            if (($now - $monster->getLastAttackAt()) >= $template->getAttackInterval()) {
                $this->performMonsterAttack($monster, $target, $now, $createdEvents);
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

        $variance = 0.88 + (mt_rand(0, 240) / 1000.0);
        $raw = $template->getAttack() * $variance;
        $mitigated = $raw * (1.0 - (float) $stats['damageReduction']);
        $dmg = max(1, (int) round($mitigated));

        $target->setCurrentHp($target->getCurrentHp() - $dmg);
        $target->setLastCombatAt($now);
        $monster->setLastAttackAt($now);

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
            $target->setCurrentHp(0);
            $target->setIsDead(true);
            $target->setActiveEffects([]);

            $deathEv = new CombatEvent(
                $target->getZoneCode(),
                'DEATH',
                'MONSTER',
                (int) $monster->getId(),
                $template->getName(),
                'PLAYER',
                (int) $target->getId(),
                $target->getName(),
                'death',
                0,
                false,
                $target->getPosX(),
                $target->getPosY(),
                $target->getPosZ(),
                $now,
            );
            $this->em->persist($deathEv);
            $createdEvents[] = $deathEv;
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
}
