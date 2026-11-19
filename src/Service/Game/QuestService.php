<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\CharacterQuest;
use App\Entity\CombatEvent;
use App\Entity\MonsterTemplate;
use App\Game\NpcRegistry;
use App\Game\QuestDefinition;
use App\Game\QuestRegistry;
use App\Game\WorldCatalog;
use App\Repository\CharacterQuestRepository;
use Doctrine\ORM\EntityManagerInterface;

final class QuestService
{
    public const float NPC_INTERACT_RANGE = 20.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuestRegistry $questRegistry,
        private readonly NpcRegistry $npcRegistry,
        private readonly CharacterQuestRepository $questRepository,
        private readonly ReunionTerrainService $terrainService,
        private readonly LevelingService $levelingService,
    ) {
    }

    /**
     * Accepts a quest from its giver NPC after validating range, level, and prerequisites.
     *
     * @return array{ok: bool, error?: string, quest?: array<string, mixed>}
     */
    public function acceptQuest(Character $character, string $questCode): array
    {
        if ($character->isDead()) {
            return ['ok' => false, 'error' => 'Ressuscitez avant de parler aux habitants de l\'île.'];
        }

        $def = $this->questRegistry->get($questCode);
        if ($def === null) {
            return ['ok' => false, 'error' => 'Quête introuvable.'];
        }

        if ($this->questRepository->findOneForCharacterAndCode($character, $questCode) !== null) {
            return ['ok' => false, 'error' => 'Vous avez déjà accepté ou terminé cette quête.'];
        }

        if ($character->getLevel() < $def->minLevel) {
            return ['ok' => false, 'error' => sprintf('Niveau %d requis pour cette quête.', $def->minLevel)];
        }

        if ($def->prerequisiteQuestCode !== null) {
            $prereq = $this->questRepository->findOneForCharacterAndCode($character, $def->prerequisiteQuestCode);
            if ($prereq === null || $prereq->getStatus() !== CharacterQuest::STATUS_COMPLETED) {
                return ['ok' => false, 'error' => 'Terminez d\'abord la quête précédente.'];
            }
        }

        $npc = $this->npcRegistry->get($def->giverNpcCode);
        if ($npc === null) {
            return ['ok' => false, 'error' => 'PNJ introuvable.'];
        }
        $npcPos = $this->npcRegistry->resolvedPosition($npc, $this->terrainService);
        if (hypot($character->getPosX() - $npcPos['x'], $character->getPosZ() - $npcPos['z']) > self::NPC_INTERACT_RANGE) {
            return ['ok' => false, 'error' => sprintf('Approchez-vous de %s pour accepter cette quête.', $npc->name)];
        }

        $cq = new CharacterQuest($character, $def->code);
        // Immediate completion check for REACH_ZONE if already in destination zone
        if ($def->objectiveType === QuestDefinition::TYPE_REACH_ZONE && $character->getZoneCode() === $def->targetCode) {
            $cq->setProgress($def->targetCount)->setStatus(CharacterQuest::STATUS_READY);
        }

        $this->em->persist($cq);
        $this->em->flush();

        return [
            'ok' => true,
            'quest' => $this->serializeActiveQuest($cq, $def),
        ];
    }

    /**
     * Turns in a READY quest at its turn-in NPC and grants XP + optional stat points.
     *
     * @return array{ok: bool, error?: string, rewardXp?: int, rewardStatPoints?: int, leveledUp?: bool, newLevel?: int, completionText?: string}
     */
    public function completeQuest(Character $character, string $questCode, ?float $now = null): array
    {
        $now ??= microtime(true);

        if ($character->isDead()) {
            return ['ok' => false, 'error' => 'Ressuscitez avant de rendre une quête.'];
        }

        $def = $this->questRegistry->get($questCode);
        if ($def === null) {
            return ['ok' => false, 'error' => 'Quête introuvable.'];
        }

        $cq = $this->questRepository->findOneForCharacterAndCode($character, $questCode);
        if ($cq === null || $cq->getStatus() !== CharacterQuest::STATUS_READY) {
            return ['ok' => false, 'error' => 'Les objectifs de cette quête ne sont pas encore remplis.'];
        }

        $npc = $this->npcRegistry->get($def->turnInNpcCode);
        if ($npc === null) {
            return ['ok' => false, 'error' => 'PNJ de rendu introuvable.'];
        }
        $npcPos = $this->npcRegistry->resolvedPosition($npc, $this->terrainService);
        if (hypot($character->getPosX() - $npcPos['x'], $character->getPosZ() - $npcPos['z']) > self::NPC_INTERACT_RANGE) {
            return ['ok' => false, 'error' => sprintf('Rendez-vous auprès de %s pour valider la quête.', $npc->name)];
        }

        $cq->setStatus(CharacterQuest::STATUS_COMPLETED)
            ->setCompletedAt(new \DateTimeImmutable());

        if ($def->rewardStatPoints > 0) {
            $character->setStatPoints($character->getStatPoints() + $def->rewardStatPoints);
        }

        $lvlRes = $this->levelingService->grantXp($character, $def->rewardXp);
        if ($lvlRes['leveledUp']) {
            $lvlEv = new CombatEvent(
                $character->getZoneCode(),
                'LEVEL_UP',
                'PLAYER',
                (int) $character->getId(),
                $character->getName(),
                'PLAYER',
                (int) $character->getId(),
                $character->getName(),
                'level_up',
                $lvlRes['newLevel'],
                false,
                $character->getPosX(),
                $character->getPosY(),
                $character->getPosZ(),
                $now,
            );
            $this->em->persist($lvlEv);
        }

        $this->em->flush();

        return [
            'ok' => true,
            'rewardXp' => $def->rewardXp,
            'rewardStatPoints' => $def->rewardStatPoints,
            'leveledUp' => $lvlRes['leveledUp'],
            'newLevel' => $lvlRes['newLevel'],
            'completionText' => $def->completionText,
        ];
    }

    /**
     * Called when a monster dies; advances KILL_MONSTER quests for each eligible recipient.
     * Note: caller flushes EntityManager.
     */
    public function onMonsterKilled(Character $character, MonsterTemplate $template): void
    {
        $activeQuests = $this->questRepository->findActiveForCharacter($character);
        foreach ($activeQuests as $cq) {
            $def = $this->questRegistry->get($cq->getQuestCode());
            if ($def === null || $def->objectiveType !== QuestDefinition::TYPE_KILL_MONSTER) {
                continue;
            }
            if ($def->targetCode !== $template->getCode()) {
                continue;
            }

            $next = min($def->targetCount, $cq->getProgress() + 1);
            $cq->setProgress($next);
            if ($next >= $def->targetCount) {
                $cq->setStatus(CharacterQuest::STATUS_READY);
            }
        }
    }

    /**
     * Called on movement/zone change; completes REACH_ZONE objectives automatically.
     * Note: caller flushes EntityManager.
     */
    public function onZoneEntered(Character $character, string $zoneCode): void
    {
        $activeQuests = $this->questRepository->findActiveForCharacter($character);
        foreach ($activeQuests as $cq) {
            $def = $this->questRegistry->get($cq->getQuestCode());
            if ($def === null || $def->objectiveType !== QuestDefinition::TYPE_REACH_ZONE) {
                continue;
            }
            if ($def->targetCode === $zoneCode) {
                $cq->setProgress($def->targetCount)->setStatus(CharacterQuest::STATUS_READY);
            }
        }
    }

    /**
     * Builds the compact quest snapshot returned inside /api/world/sync and quest actions.
     *
     * @return array<string, mixed>
     */
    public function buildQuestState(Character $character): array
    {
        $rows = $this->questRepository->findAllForCharacter($character);
        $byCode = [];
        $completedCodes = [];
        $activeList = [];

        foreach ($rows as $row) {
            $byCode[$row->getQuestCode()] = $row;
            if ($row->getStatus() === CharacterQuest::STATUS_COMPLETED) {
                $completedCodes[] = $row->getQuestCode();
            } else {
                $def = $this->questRegistry->get($row->getQuestCode());
                if ($def !== null) {
                    $activeList[] = $this->serializeActiveQuest($row, $def);
                }
            }
        }

        // Compute NPC markers: TURN_IN > AVAILABLE > IN_PROGRESS > LOCKED_LEVEL > NONE
        $npcMarkers = [];
        foreach ($this->npcRegistry->all() as $npc) {
            $npcMarkers[$npc->code] = 'NONE';
        }

        $availableCodes = [];
        $nextLockedQuest = null;

        foreach ($this->questRegistry->all() as $def) {
            $existing = $byCode[$def->code] ?? null;
            if ($existing !== null) {
                if ($existing->getStatus() === CharacterQuest::STATUS_READY) {
                    $npcMarkers[$def->turnInNpcCode] = 'TURN_IN';
                } elseif ($existing->getStatus() === CharacterQuest::STATUS_ACTIVE && ($npcMarkers[$def->turnInNpcCode] ?? 'NONE') === 'NONE') {
                    $npcMarkers[$def->turnInNpcCode] = 'IN_PROGRESS';
                }
                continue;
            }

            $prereqMet = $def->prerequisiteQuestCode === null
                || in_array($def->prerequisiteQuestCode, $completedCodes, true);

            if (!$prereqMet) {
                continue;
            }

            if ($character->getLevel() >= $def->minLevel) {
                $availableCodes[] = $def->code;
                if (($npcMarkers[$def->giverNpcCode] ?? 'NONE') !== 'TURN_IN') {
                    $npcMarkers[$def->giverNpcCode] = 'AVAILABLE';
                }
            } elseif ($nextLockedQuest === null) {
                $nextLockedQuest = $def;
            }
        }

        // Build clear "next step" guidance for the quest tracker & minimap beacon
        $guidance = null;
        if ($activeList !== []) {
            $q = $activeList[0];
            $def = $this->questRegistry->get((string) $q['code']);
            if ($def !== null) {
                if ($q['status'] === CharacterQuest::STATUS_READY) {
                    $turnInNpc = $this->npcRegistry->get($def->turnInNpcCode);
                    $pos = $turnInNpc !== null
                        ? $this->npcRegistry->resolvedPosition($turnInNpc, $this->terrainService)
                        : ['x' => $def->guideX, 'z' => $def->guideZ];
                    $guidance = [
                        'mode' => 'TURN_IN',
                        'title' => $def->title,
                        'text' => sprintf('Rendez la quête à %s', $turnInNpc?->name ?? $def->turnInNpcCode),
                        'x' => round($pos['x'], 1),
                        'z' => round($pos['z'], 1),
                        'zoneCode' => $turnInNpc?->zoneCode ?? '',
                    ];
                } else {
                    $guidance = [
                        'mode' => 'OBJECTIVE',
                        'title' => $def->title,
                        'text' => sprintf('%s (%d/%d)', $def->targetLabel, $q['progress'], $def->targetCount),
                        'x' => round($def->guideX, 1),
                        'z' => round($def->guideZ, 1),
                        'zoneCode' => $def->objectiveType === QuestDefinition::TYPE_REACH_ZONE ? $def->targetCode : $this->terrainService->getZoneCode($def->guideX, $def->guideZ),
                    ];
                }
            }
        } elseif ($availableCodes !== []) {
            $firstAvail = $this->questRegistry->get($availableCodes[0]);
            if ($firstAvail !== null) {
                $npc = $this->npcRegistry->get($firstAvail->giverNpcCode);
                if ($npc !== null) {
                    $pos = $this->npcRegistry->resolvedPosition($npc, $this->terrainService);
                    $zoneName = WorldCatalog::zones()[$npc->zoneCode]['name'] ?? $npc->zoneCode;
                    $guidance = [
                        'mode' => 'TALK_GIVER',
                        'title' => $firstAvail->title,
                        'text' => sprintf('Parlez à %s (%s)', $npc->name, $zoneName),
                        'x' => round($pos['x'], 1),
                        'z' => round($pos['z'], 1),
                        'zoneCode' => $npc->zoneCode,
                    ];
                }
            }
        } elseif ($nextLockedQuest !== null) {
            $npc = $this->npcRegistry->get($nextLockedQuest->giverNpcCode);
            $guidance = [
                'mode' => 'LEVEL_UP',
                'title' => $nextLockedQuest->title,
                'text' => sprintf('Atteignez le niveau %d puis parlez à %s', $nextLockedQuest->minLevel, $npc?->name ?? 'votre guide'),
                'x' => round($character->getPosX(), 1),
                'z' => round($character->getPosZ(), 1),
                'zoneCode' => $npc?->zoneCode ?? $character->getZoneCode(),
            ];
        }

        return [
            'active' => $activeList,
            'availableCodes' => $availableCodes,
            'completedCodes' => $completedCodes,
            'npcMarkers' => $npcMarkers,
            'guidance' => $guidance,
            'totalQuests' => count($this->questRegistry->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActiveQuest(CharacterQuest $row, QuestDefinition $def): array
    {
        return [
            'code' => $def->code,
            'title' => $def->title,
            'status' => $row->getStatus(),
            'progress' => $row->getProgress(),
            'targetCount' => $def->targetCount,
            'targetLabel' => $def->targetLabel,
            'turnInNpcCode' => $def->turnInNpcCode,
            'rewardXp' => $def->rewardXp,
            'rewardStatPoints' => $def->rewardStatPoints,
        ];
    }
}
