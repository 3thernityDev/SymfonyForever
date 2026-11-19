<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\MonsterInstance;
use App\Entity\Party;

final class StatePayloadBuilder
{
    public function __construct(
        private readonly StatCalculator $statCalculator,
    ) {
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

        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'classCode' => $c->getClassCode(),
            'level' => $c->getLevel(),
            'xp' => $c->getXp(),
            'xpNext' => $this->statCalculator->xpForNextLevel($c->getLevel()),
            'statPoints' => $c->getStatPoints(),
            'attributes' => [
                'vitality' => $c->getVitality(),
                'strength' => $c->getStrength(),
                'intelligence' => $c->getIntelligence(),
                'defense' => $c->getDefense(),
                'spirit' => $c->getSpirit(),
            ],
            'derived' => $stats,
            'hp' => $c->getCurrentHp(),
            'maxHp' => $stats['maxHp'],
            'mp' => $c->getCurrentMp(),
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
            'aggressive' => $tpl->isAggressive(),
            'targetCharacterId' => $m->getTopThreatCharacterId($now),
            'effects' => $effects,
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
