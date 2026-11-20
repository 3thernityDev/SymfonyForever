<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\Party;
use App\Game\ItemRegistry;
use App\Game\SkillRegistry;

final class StatePayloadBuilder
{
    private readonly SkillRegistry $skillRegistry;
    private readonly ItemRegistry $itemRegistry;

    public function __construct(
        private readonly StatCalculator $statCalculator,
        ?SkillRegistry $skillRegistry = null,
        ?ItemRegistry $itemRegistry = null,
    ) {
        $this->skillRegistry = $skillRegistry ?? new SkillRegistry();
        $this->itemRegistry = $itemRegistry ?? new ItemRegistry();
    }

    /**
     * Full private payload for the playing character.
     *
     * @return array<string, mixed>
     */
    public function buildSelf(Character $c, float $now): array
    {
        $stats = $this->statCalculator->compute($c, $now);
        $activeCooldowns = [];
        foreach ($c->getCooldowns() as $code => $until) {
            if ($until > $now) {
                $activeCooldowns[$code] = round($until - $now, 2);
            }
        }

        $effects = [];
        foreach ($c->getActiveEffects() as $ef) {
            if ($ef['expiresAt'] > $now) {
                $effects[] = [
                    'type' => $ef['type'],
                    'skillCode' => $ef['skillCode'],
                    'remaining' => round($ef['expiresAt'] - $now, 1),
                ];
            }
        }

        $eq = $stats['equipmentBonuses'];
        $cls = $c->getCharacterClass();
        $aaEndsAt = (float) ($c->getCooldowns()['auto_attack'] ?? 0.0);
        $skillLevels = [];
        foreach ($this->skillRegistry->forClass($cls) as $skill) {
            $skillLevels[$skill->code] = $c->getSkillLevel($skill->code);
        }
        $activeMountCode = $c->getActiveMountCode();
        $mountDef = $activeMountCode !== null ? $this->itemRegistry->get($activeMountCode) : null;

        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'classCode' => $c->getClassCode(),
            'level' => $c->getLevel(),
            'xp' => $c->getXp(),
            'xpNext' => $this->statCalculator->xpForNextLevel($c->getLevel()),
            'statPoints' => $c->getStatPoints(),
            'skillPoints' => $c->getSkillPoints(),
            'skillLevels' => $skillLevels,
            'gold' => $c->getGold(),
            'resourceType' => $cls->resourceType(),
            'resourceLabel' => $cls->resourceLabel(),
            'resourceShort' => $cls->resourceShort(),
            'resourceColor' => $cls->resourceColor(),
            'autoAttackCooldown' => round(max(0.0, $aaEndsAt - $now), 2),
            'baseAttributes' => [
                'vitality' => $c->getVitality(),
                'strength' => $c->getStrength(),
                'intelligence' => $c->getIntelligence(),
                'defense' => $c->getDefense(),
                'spirit' => $c->getSpirit(),
            ],
            'equipmentBonuses' => $eq,
            'attributes' => [
                'vitality' => $c->getVitality() + $eq['vitality'],
                'strength' => $c->getStrength() + $eq['strength'],
                'intelligence' => $c->getIntelligence() + $eq['intelligence'],
                'defense' => $c->getDefense() + $eq['defense'],
                'spirit' => $c->getSpirit() + $eq['spirit'],
            ],
            'derived' => $stats,
            'hp' => $c->getCurrentHp(),
            'maxHp' => $stats['maxHp'],
            'mp' => min($stats['maxMp'], $c->getCurrentMp()),
            'maxMp' => $stats['maxMp'],
            'isDead' => $c->isDead(),
            'zoneCode' => $c->getZoneCode(),
            'x' => round($c->getPosX(), 2),
            'y' => round($c->getPosY(), 2),
            'z' => round($c->getPosZ(), 2),
            'rotY' => round($c->getRotY(), 3),
            'gcdRemaining' => round(max(0.0, $c->getGcdEndsAt() - $now), 2),
            'cooldowns' => $activeCooldowns,
            'effects' => $effects,
            'partyId' => $c->getParty()?->getId(),
            'pendingPartyInviteId' => $c->getPendingPartyInviteId(),
            'activeMountCode' => $mountDef?->code,
            'mountSpeedBonus' => $mountDef?->mountSpeedBonus ?? 0.0,
            'mountCanFly' => $mountDef?->mountCanFly ?? false,
        ];
    }

    /**
     * Compact public payload for other online players.
     *
     * @return array<string, mixed>
     */
    public function buildPublicPlayer(Character $c, float $now): array
    {
        $stats = $this->statCalculator->compute($c, $now);

        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'classCode' => $c->getClassCode(),
            'level' => $c->getLevel(),
            'hp' => $c->getCurrentHp(),
            'maxHp' => $stats['maxHp'],
            'isDead' => $c->isDead(),
            'isMoving' => $c->isMoving(),
            'zoneCode' => $c->getZoneCode(),
            'x' => round($c->getPosX(), 2),
            'y' => round($c->getPosY(), 2),
            'z' => round($c->getPosZ(), 2),
            'rotY' => round($c->getRotY(), 3),
            'partyId' => $c->getParty()?->getId(),
            'activeMountCode' => $c->getActiveMountCode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMonster(MonsterInstance $m, float $now): array
    {
        $tpl = $m->getTemplate();
        $effects = [];
        foreach ($m->getActiveEffects() as $ef) {
            if ($ef['expiresAt'] > $now) {
                $effects[] = [
                    'type' => $ef['type'],
                    'skillCode' => $ef['skillCode'],
                    'remaining' => round($ef['expiresAt'] - $now, 1),
                ];
            }
        }

        $activeEngaged = count($m->getThreatTable());
        $isWorldBoss = $tpl->isWorldBoss();

        return [
            'id' => $m->getId(),
            'code' => $tpl->getCode(),
            'name' => $tpl->getName(),
            'level' => $tpl->getLevel(),
            'hp' => $m->getCurrentHp(),
            'maxHp' => $tpl->getMaxHp(),
            'state' => $m->getState(),
            'zoneCode' => $m->getZoneCode(),
            'x' => round($m->getPosX(), 2),
            'y' => round($m->getPosY(), 2),
            'z' => round($m->getPosZ(), 2),
            'rotY' => round($m->getRotY(), 3),
            'meshKey' => $tpl->getMeshKey(),
            'primaryColor' => $tpl->getPrimaryColor(),
            'scale' => $tpl->getScale(),
            'isBoss' => $tpl->isBoss(),
            'isWorldBoss' => $isWorldBoss,
            'phase' => $m->getPhase(),
            'participantCount' => $isWorldBoss ? max($activeEngaged, $m->getRaidContributorCount()) : $activeEngaged,
            'carapaceActive' => $isWorldBoss && $activeEngaged < WorldSimulationService::WORLD_BOSS_MIN_PLAYERS_NO_REGEN,
            'respawnRemaining' => $m->getState() === MonsterInstance::STATE_DEAD ? round(max(0.0, $m->getRespawnAt() - $now), 1) : 0.0,
            'aggressive' => $tpl->isAggressive(),
            'social' => $tpl->isSocial(),
            'fleeHpRatio' => $tpl->getFleeHpRatio(),
            'hasCalledHelp' => $m->hasCalledHelp(),
            'targetCharacterId' => $m->getTopThreatCharacterId($now),
            'effects' => $effects,
        ];
    }

    /**
     * Global World Boss live status summary broadcast to every player across La Réunion.
     *
     * @return array<string, mixed>
     */
    public function buildWorldBossSummary(MonsterInstance $m, float $now): array
    {
        $tpl = $m->getTemplate();
        $activeEngaged = count($m->getThreatTable());

        return [
            'id' => $m->getId(),
            'code' => $tpl->getCode(),
            'name' => $tpl->getName(),
            'level' => $tpl->getLevel(),
            'zoneCode' => $m->getZoneCode(),
            'x' => round($m->getPosX(), 1),
            'z' => round($m->getPosZ(), 1),
            'hp' => $m->getCurrentHp(),
            'maxHp' => $tpl->getMaxHp(),
            'state' => $m->getState(),
            'phase' => $m->getPhase(),
            'activeEngaged' => $activeEngaged,
            'participantCount' => max($activeEngaged, $m->getRaidContributorCount()),
            'carapaceActive' => $activeEngaged < WorldSimulationService::WORLD_BOSS_MIN_PLAYERS_NO_REGEN,
            'recommendedPlayers' => 10,
            'respawnRemaining' => $m->getState() === MonsterInstance::STATE_DEAD ? round(max(0.0, $m->getRespawnAt() - $now), 1) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildParty(?Party $party, float $now): ?array
    {
        if ($party === null) {
            return null;
        }

        $members = [];
        foreach ($party->getMembers() as $m) {
            $stats = $this->statCalculator->compute($m, $now);
            $members[] = [
                'id' => $m->getId(),
                'name' => $m->getName(),
                'classCode' => $m->getClassCode(),
                'level' => $m->getLevel(),
                'hp' => $m->getCurrentHp(),
                'maxHp' => $stats['maxHp'],
                'mp' => $m->getCurrentMp(),
                'maxMp' => $stats['maxMp'],
                'isDead' => $m->isDead(),
                'zoneCode' => $m->getZoneCode(),
                'online' => ($now - $m->getLastSeenAt()) < 15.0,
            ];
        }

        return [
            'id' => $party->getId(),
            'name' => $party->getName(),
            'leaderId' => $party->getLeaderCharacterId(),
            'members' => $members,
        ];
    }
}
