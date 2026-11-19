<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\CombatEvent;
use App\Entity\MonsterInstance;
use App\Game\SkillDefinition;
use App\Game\SkillRegistry;
use App\Repository\CharacterRepository;
use App\Repository\MonsterInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CombatService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SkillRegistry $skillRegistry,
        private readonly StatCalculator $statCalculator,
        private readonly LevelingService $levelingService,
        private readonly QuestService $questService,
        private readonly CharacterRepository $characterRepository,
        private readonly MonsterInstanceRepository $monsterRepository,
    ) {
    }

    /**
     * Casts a skill authoritatively on the server.
     *
     * @return array{ok: bool, error?: string, events: list<array<string, mixed>>, xpGained: int, leveledUp: bool}
     */
    public function castSkill(
        Character $caster,
        string $skillCode,
        ?string $targetType,
        ?int $targetId,
        ?float $now = null,
    ): array {
        $now ??= microtime(true);

        if ($caster->isDead()) {
            return ['ok' => false, 'error' => 'Vous êtes vaincu. Ressuscitez au camp.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
        }

        $skill = $this->skillRegistry->get($skillCode);
        if ($skill === null) {
            return ['ok' => false, 'error' => 'Compétence inconnue.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
        }

        if ($skill->characterClass !== $caster->getCharacterClass()) {
            return ['ok' => false, 'error' => 'Cette compétence appartient à une autre classe.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
        }

        if ($caster->getLevel() < $skill->unlockLevel) {
            return ['ok' => false, 'error' => sprintf('Débloqué au niveau %d.', $skill->unlockLevel), 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
        }

        if ($caster->getGcdEndsAt() > $now + 0.05) {
            return ['ok' => false, 'error' => 'Recharge globale en cours.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
        }

        $cooldowns = $caster->getCooldowns();
        if (($cooldowns[$skillCode] ?? 0.0) > $now + 0.05) {
            $rem = round(($cooldowns[$skillCode] - $now), 1);

            return ['ok' => false, 'error' => sprintf('Compétence en recharge (%.1fs).', $rem), 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
        }

        if ($caster->getCurrentMp() < $skill->manaCost) {
            return ['ok' => false, 'error' => 'Mana insuffisant.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
        }

        $stats = $this->statCalculator->compute($caster, $now);
        $createdEvents = [];
        $xpGained = 0;
        $leveledUp = false;

        switch ($skill->targetType) {
            case 'SELF':
                $this->applySelfBuff($caster, $skill, $now, $createdEvents);
                break;

            case 'ALLY':
                $ally = $this->resolveAllyTarget($caster, $targetType, $targetId);
                if ($ally === null) {
                    return ['ok' => false, 'error' => 'Cible alliée introuvable.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
                }
                if ($ally->isDead()) {
                    return ['ok' => false, 'error' => 'Cet allié est déjà vaincu.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
                }
                if ($ally->getId() !== $caster->getId() && $this->distance2D($caster->getPosX(), $caster->getPosZ(), $ally->getPosX(), $ally->getPosZ()) > $skill->range) {
                    return ['ok' => false, 'error' => 'Allié hors de portée.', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
                }
                $this->applyHeal($caster, $ally, $skill, $stats, $now, $createdEvents);
                break;

            case 'PARTY_AOE':
                $allies = $this->resolvePartyTargetsInRadius($caster, $skill->aoeRadius);
                foreach ($allies as $ally) {
                    if (!$ally->isDead()) {
                        $this->applyHeal($caster, $ally, $skill, $stats, $now, $createdEvents);
                    }
                }
                break;

            case 'SELF_AOE':
                $enemies = $this->monsterRepository->findAliveInRadius($caster->getPosX(), $caster->getPosZ(), $skill->aoeRadius);
                if ($enemies === []) {
                    // Still allow slamming the ground for VFX feedback!
                    $ev = new CombatEvent(
                        $caster->getZoneCode(),
                        'BUFF',
                        'PLAYER',
                        (int) $caster->getId(),
                        $caster->getName(),
                        'PLAYER',
                        (int) $caster->getId(),
                        $caster->getName(),
                        $skill->code,
                        0,
                        false,
                        $caster->getPosX(),
                        $caster->getPosY(),
                        $caster->getPosZ(),
                        $now,
                    );
                    $this->em->persist($ev);
                    $createdEvents[] = $ev;
                } else {
                    foreach ($enemies as $enemy) {
                        [$xp, $lvl] = $this->applyDamageToMonster($caster, $enemy, $skill, $stats, $now, $createdEvents);
                        $xpGained += $xp;
                        $leveledUp = $leveledUp || $lvl;
                    }
                }
                break;

            case 'ENEMY':
            case 'ENEMY_AOE':
                $monster = ($targetType === 'MONSTER' && $targetId !== null)
                    ? $this->monsterRepository->find($targetId)
                    : null;

                if (!$monster instanceof MonsterInstance || $monster->getState() === MonsterInstance::STATE_DEAD) {
                    return ['ok' => false, 'error' => 'Sélectionnez un ennemi vivant (Tab ou clic).', 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
                }

                $dist = $this->distance2D($caster->getPosX(), $caster->getPosZ(), $monster->getPosX(), $monster->getPosZ());
                if ($dist > $skill->range) {
                    return ['ok' => false, 'error' => sprintf('Cible trop éloignée (portée %.0fm).', $skill->range), 'events' => [], 'xpGained' => 0, 'leveledUp' => false];
                }

                $targets = [$monster];
                if ($skill->targetType === 'ENEMY_AOE') {
                    $targets = $this->monsterRepository->findAliveInRadius($monster->getPosX(), $monster->getPosZ(), $skill->aoeRadius);
                    if ($targets === []) {
                        $targets = [$monster];
                    }
                }

                foreach ($targets as $enemy) {
                    [$xp, $lvl] = $this->applyDamageToMonster($caster, $enemy, $skill, $stats, $now, $createdEvents);
                    $xpGained += $xp;
                    $leveledUp = $leveledUp || $lvl;
                }
                break;
        }

        // Consume mana, apply GCD & individual skill cooldown
        $caster->setCurrentMp($caster->getCurrentMp() - $skill->manaCost);
        $caster->setGcdEndsAt($now + SkillRegistry::GLOBAL_COOLDOWN);
        $caster->setCooldown($skill->code, $now + $skill->cooldown);
        $caster->setLastCombatAt($now);

        $this->em->flush();

        return [
            'ok' => true,
            'events' => array_map(static fn (CombatEvent $e): array => $e->toArray(), $createdEvents),
            'xpGained' => $xpGained,
            'leveledUp' => $leveledUp,
        ];
    }

    /**
     * Handles monster death, XP distribution (solo or party with +10% bonus per ally), and level-up events.
     *
     * @param list<CombatEvent> $createdEvents
     * @return array{int, bool} [xpGrantedToKiller, killerLeveledUp]
     */
    public function handleMonsterDeath(MonsterInstance $monster, ?Character $killer, float $now, array &$createdEvents): array
    {
        $template = $monster->getTemplate();
        $monster->setCurrentHp(0);
        $monster->setState(MonsterInstance::STATE_DEAD);
        $monster->setThreatTable([]);
        $monster->setActiveEffects([]);
        $monster->setTauntedByCharacterId(null);
        $monster->setTauntExpiresAt(0.0);
        $monster->setRespawnAt($now + $template->getRespawnSeconds());

        $killEvent = new CombatEvent(
            $monster->getZoneCode(),
            'KILL',
            'PLAYER',
            (int) ($killer?->getId() ?? 0),
            $killer?->getName() ?? '',
            'MONSTER',
            (int) $monster->getId(),
            $template->getName(),
            '',
            $template->getXpReward(),
            false,
            $monster->getPosX(),
            $monster->getPosY(),
            $monster->getPosZ(),
            $now,
        );
        $this->em->persist($killEvent);
        $createdEvents[] = $killEvent;

        if ($killer === null) {
            return [0, false];
        }

        // Gather recipients: either party members in range/same zone, or killer alone
        $recipients = [$killer];
        $party = $killer->getParty();
        if ($party !== null) {
            $recipients = [];
            foreach ($party->getMembers() as $member) {
                if (!$member->isDead() && $member->getZoneCode() === $monster->getZoneCode()) {
                    $recipients[] = $member;
                }
            }
            if ($recipients === []) {
                $recipients = [$killer];
            }
        }

        $partyBonus = 1.0 + 0.10 * max(0, count($recipients) - 1);
        $xpPerMember = (int) round(($template->getXpReward() * $partyBonus) / max(1, count($recipients) === 1 ? 1 : (count($recipients) * 0.72)));

        $killerXp = 0;
        $killerLeveledUp = false;

        foreach ($recipients as $member) {
            // Advance active KILL_MONSTER quests for every eligible party member
            $this->questService->onMonsterKilled($member, $template);

            $result = $this->levelingService->grantXp($member, $xpPerMember);
            if ($member->getId() === $killer->getId()) {
                $killerXp = $xpPerMember;
                $killerLeveledUp = $result['leveledUp'];
            }
            if ($result['leveledUp']) {
                $lvlEv = new CombatEvent(
                    $member->getZoneCode(),
                    'LEVEL_UP',
                    'PLAYER',
                    (int) $member->getId(),
                    $member->getName(),
                    'PLAYER',
                    (int) $member->getId(),
                    $member->getName(),
                    'level_up',
                    $result['newLevel'],
                    false,
                    $member->getPosX(),
                    $member->getPosY(),
                    $member->getPosZ(),
                    $now,
                );
                $this->em->persist($lvlEv);
                $createdEvents[] = $lvlEv;
            }
        }

        return [$killerXp, $killerLeveledUp];
    }

    /**
     * @param array<string, mixed> $stats
     * @param list<CombatEvent>    $createdEvents
     * @return array{int, bool}
     */
    private function applyDamageToMonster(
        Character $caster,
        MonsterInstance $monster,
        SkillDefinition $skill,
        array $stats,
        float $now,
        array &$createdEvents,
    ): array {
        $scaling = match ($skill->scalingStat) {
            'INT' => (float) $stats['spellPower'],
            'STR_DEF' => ((float) $stats['attackPower'] * 0.65) + ($caster->getDefense() * 1.4),
            default => (float) $stats['attackPower'],
        };

        $raw = $skill->basePower + $scaling * $skill->powerMultiplier;

        // Execute bonus when target < 35% HP
        if ($skill->effectType === 'EXECUTE') {
            $hpRatio = $monster->getCurrentHp() / max(1, $monster->getTemplate()->getMaxHp());
            if ($hpRatio <= 0.35) {
                $raw *= (1.0 + $skill->effectValue);
            }
        }

        // Monster defense mitigation + small natural variance (92%..108%)
        $mDef = $monster->getTemplate()->getDefense();
        $mitigation = 1.0 - ($mDef / ($mDef + 85.0));
        $variance = 0.92 + (mt_rand(0, 160) / 1000.0);
        $damage = $raw * $mitigation * $variance;

        $critChance = min(0.75, (float) $stats['critChance'] + $skill->bonusCritChance);
        $isCrit = (mt_rand(1, 1000) / 1000.0) <= $critChance;
        if ($isCrit) {
            $damage *= 1.65;
        }

        $finalDamage = max(1, (int) round($damage));
        $monster->setCurrentHp($monster->getCurrentHp() - $finalDamage);
        $monster->setState(MonsterInstance::STATE_COMBAT);

        // Threat calculation (Tank passive x2.2 * skill threat multiplier)
        $threatGain = $finalDamage * $caster->getCharacterClass()->threatMultiplier() * $skill->threatMultiplier;
        $monster->addThreat((int) $caster->getId(), $threatGain);

        // Handle Taunt override
        if ($skill->effectType === 'TAUNT') {
            $table = $monster->getThreatTable();
            $highest = $table === [] ? 50.0 : max($table);
            $table[(string) $caster->getId()] = max($table[(string) $caster->getId()] ?? 0.0, $highest * $skill->effectValue + 25.0);
            $monster->setThreatTable($table);
            $monster->setTauntedByCharacterId((int) $caster->getId());
            $monster->setTauntExpiresAt($now + $skill->effectDuration);

            $tauntEv = new CombatEvent(
                $monster->getZoneCode(),
                'TAUNT',
                'PLAYER',
                (int) $caster->getId(),
                $caster->getName(),
                'MONSTER',
                (int) $monster->getId(),
                $monster->getTemplate()->getName(),
                $skill->code,
                0,
                false,
                $monster->getPosX(),
                $monster->getPosY(),
                $monster->getPosZ(),
                $now,
            );
            $this->em->persist($tauntEv);
            $createdEvents[] = $tauntEv;
        }

        // Apply DoT effect on monster
        if ($skill->effectType === 'DOT' && $monster->getCurrentHp() > 0) {
            $effects = array_values(array_filter(
                $monster->getActiveEffects(),
                static fn (array $e): bool => !($e['skillCode'] === $skill->code && $e['sourceId'] === (int) $caster->getId()),
            ));
            $tickDmg = max(4.0, 9.0 + (float) $stats['spellPower'] * $skill->effectValue);
            $effects[] = [
                'type' => 'DOT',
                'skillCode' => $skill->code,
                'sourceId' => (int) $caster->getId(),
                'value' => round($tickDmg, 2),
                'expiresAt' => $now + $skill->effectDuration,
                'nextTickAt' => $now + $skill->effectTickInterval,
                'tickInterval' => $skill->effectTickInterval,
            ];
            $monster->setActiveEffects($effects);
        }

        $dmgEv = new CombatEvent(
            $monster->getZoneCode(),
            'DAMAGE',
            'PLAYER',
            (int) $caster->getId(),
            $caster->getName(),
            'MONSTER',
            (int) $monster->getId(),
            $monster->getTemplate()->getName(),
            $skill->code,
            $finalDamage,
            $isCrit,
            $monster->getPosX(),
            $monster->getPosY(),
            $monster->getPosZ(),
            $now,
        );
        $this->em->persist($dmgEv);
        $createdEvents[] = $dmgEv;

        if ($monster->getCurrentHp() <= 0) {
            return $this->handleMonsterDeath($monster, $caster, $now, $createdEvents);
        }

        return [0, false];
    }

    /**
     * @param array<string, mixed> $stats
     * @param list<CombatEvent>    $createdEvents
     */
    private function applyHeal(
        Character $caster,
        Character $ally,
        SkillDefinition $skill,
        array $stats,
        float $now,
        array &$createdEvents,
    ): void {
        $allyStats = $this->statCalculator->compute($ally, $now);
        $rawHeal = $skill->basePower + (float) $stats['spellPower'] * $skill->powerMultiplier;
        $critChance = min(0.75, (float) $stats['critChance'] + $skill->bonusCritChance);
        $isCrit = (mt_rand(1, 1000) / 1000.0) <= $critChance;
        if ($isCrit) {
            $rawHeal *= 1.5;
        }

        $amount = max(1, (int) round($rawHeal));
        $before = $ally->getCurrentHp();
        $ally->setCurrentHp(min($allyStats['maxHp'], $before + $amount));
        $effectiveHeal = $ally->getCurrentHp() - $before;

        // Apply HoT effect if applicable
        if ($skill->effectType === 'HOT') {
            $effects = array_values(array_filter(
                $ally->getActiveEffects(),
                static fn (array $e): bool => !($e['skillCode'] === $skill->code && $e['sourceId'] === (int) $caster->getId()),
            ));
            $tickHeal = max(6.0, 12.0 + (float) $stats['spellPower'] * $skill->effectValue);
            $effects[] = [
                'type' => 'HOT',
                'skillCode' => $skill->code,
                'sourceId' => (int) $caster->getId(),
                'value' => round($tickHeal, 2),
                'expiresAt' => $now + $skill->effectDuration,
                'nextTickAt' => $now + $skill->effectTickInterval,
                'tickInterval' => $skill->effectTickInterval,
            ];
            $ally->setActiveEffects($effects);
        }

        // Healing generates moderate threat on nearby monsters currently engaged against the healed ally
        if ($effectiveHeal > 0) {
            $engaged = $this->monsterRepository->findAliveInRadius($ally->getPosX(), $ally->getPosZ(), 45.0);
            foreach ($engaged as $m) {
                if ($m->getState() === MonsterInstance::STATE_COMBAT) {
                    $m->addThreat((int) $caster->getId(), $effectiveHeal * $skill->threatMultiplier * $caster->getCharacterClass()->threatMultiplier());
                }
            }
        }

        $healEv = new CombatEvent(
            $ally->getZoneCode(),
            'HEAL',
            'PLAYER',
            (int) $caster->getId(),
            $caster->getName(),
            'PLAYER',
            (int) $ally->getId(),
            $ally->getName(),
            $skill->code,
            $amount,
            $isCrit,
            $ally->getPosX(),
            $ally->getPosY(),
            $ally->getPosZ(),
            $now,
        );
        $this->em->persist($healEv);
        $createdEvents[] = $healEv;
    }

    /**
     * @param list<CombatEvent> $createdEvents
     */
    private function applySelfBuff(Character $caster, SkillDefinition $skill, float $now, array &$createdEvents): void
    {
        $effects = array_values(array_filter(
            $caster->getActiveEffects(),
            static fn (array $e): bool => $e['skillCode'] !== $skill->code,
        ));
        $effects[] = [
            'type' => (string) $skill->effectType,
            'skillCode' => $skill->code,
            'sourceId' => (int) $caster->getId(),
            'value' => $skill->effectValue,
            'expiresAt' => $now + $skill->effectDuration,
            'nextTickAt' => $now + $skill->effectDuration,
            'tickInterval' => 0.0,
        ];
        $caster->setActiveEffects($effects);

        $ev = new CombatEvent(
            $caster->getZoneCode(),
            'BUFF',
            'PLAYER',
            (int) $caster->getId(),
            $caster->getName(),
            'PLAYER',
            (int) $caster->getId(),
            $caster->getName(),
            $skill->code,
            (int) round($skill->effectValue * 100),
            false,
            $caster->getPosX(),
            $caster->getPosY(),
            $caster->getPosZ(),
            $now,
        );
        $this->em->persist($ev);
        $createdEvents[] = $ev;
    }

    private function resolveAllyTarget(Character $caster, ?string $targetType, ?int $targetId): ?Character
    {
        if ($targetType === 'PLAYER' && $targetId !== null) {
            return $this->characterRepository->find($targetId);
        }

        // Convenient MMO UX: if Heal casts a heal while targeting a monster or nothing, heal self!
        return $caster;
    }

    /**
     * @return list<Character>
     */
    private function resolvePartyTargetsInRadius(Character $caster, float $radius): array
    {
        $party = $caster->getParty();
        if ($party === null) {
            return [$caster];
        }

        $targets = [];
        foreach ($party->getMembers() as $member) {
            if ($this->distance2D($caster->getPosX(), $caster->getPosZ(), $member->getPosX(), $member->getPosZ()) <= $radius) {
                $targets[] = $member;
            }
        }

        return $targets !== [] ? $targets : [$caster];
    }

    private function distance2D(float $x1, float $z1, float $x2, float $z2): float
    {
        return hypot($x2 - $x1, $z2 - $z1);
    }
}
