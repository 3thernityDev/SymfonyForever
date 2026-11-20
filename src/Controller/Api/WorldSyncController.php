<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Game\ItemRegistry;
use App\Game\MonsterRespawnScaler;
use App\Game\WorldAtmosphere;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\CombatEventRepository;
use App\Repository\MonsterInstanceRepository;
use App\Repository\WorldLootDropRepository;
use App\Service\Game\BotSimulationService;
use App\Service\Game\InventoryService;
use App\Service\Game\QuestService;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatePayloadBuilder;
use App\Service\Game\WorldSimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WorldSyncController extends AbstractController
{
    public const float MAX_PLAYER_SPEED = 24.0; // world units/sec (+ tolerance for HTTP jitter)
    public const float INTEREST_RADIUS = 200.0;

    #[Route('/api/world/sync', name: 'api_world_sync', methods: ['POST'])]
    public function sync(
        Request $request,
        EntityManagerInterface $em,
        CharacterRepository $characterRepository,
        MonsterInstanceRepository $monsterRepository,
        CombatEventRepository $combatEventRepository,
        ChatMessageRepository $chatRepository,
        WorldLootDropRepository $lootRepository,
        ReunionTerrainService $terrainService,
        WorldSimulationService $simulationService,
        BotSimulationService $botSimulationService,
        QuestService $questService,
        InventoryService $inventoryService,
        ItemRegistry $itemRegistry,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Payload JSON invalide'], 400);
        }

        $characterId = (int) ($data['characterId'] ?? 0);
        $character = $characterRepository->find($characterId);
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Personnage non autorisé'], 403);
        }

        $now = microtime(true);
        $prevSeen = $character->getLastSeenAt();
        $dt = $prevSeen > 0.0 ? min(3.0, max(0.05, $now - $prevSeen)) : 1.0;

        $activeMountCode = $character->getActiveMountCode();
        $mountDef = $activeMountCode !== null ? $itemRegistry->get($activeMountCode) : null;
        $mountBonus = $mountDef?->mountSpeedBonus ?? 0.0;
        $isFlying = $mountDef?->mountCanFly ?? false;
        $maxSpeed = self::MAX_PLAYER_SPEED * (1.0 + $mountBonus);

        // Authoritative movement & walkability / flight validation (when alive & position sent)
        $correctedPosition = false;
        if (!$character->isDead() && isset($data['x'], $data['z'])) {
            $reqX = (float) $data['x'];
            $reqZ = (float) $data['z'];
            $reqRotY = (float) ($data['rotY'] ?? $character->getRotY());
            $isMoving = (bool) ($data['isMoving'] ?? false);

            $dist = hypot($reqX - $character->getPosX(), $reqZ - $character->getPosZ());
            $maxAllowed = $maxSpeed * $dt + 10.0;

            if ($isFlying) {
                $halfLimit = WorldCatalog::HALF_WORLD - 32.0;
                $inBounds = abs($reqX) <= $halfLimit && abs($reqZ) <= $halfLimit;
                $groundY = $terrainService->getHeight($reqX, $reqZ);
                $minFlyY = max($groundY, WorldCatalog::SEA_LEVEL + 1.2);
                $maxFlyY = WorldCatalog::MAX_ELEVATION + 220.0;
                $rawReqY = isset($data['y']) ? (float) $data['y'] : max($groundY, $character->getPosY());
                $reqY = max($minFlyY, min($maxFlyY, $rawReqY));
                $dy = abs($reqY - $character->getPosY());
                $maxAllowedY = $maxSpeed * $dt + 16.0;

                if ($dist <= $maxAllowed && $dy <= $maxAllowedY && $inBounds) {
                    $character->setPosX($reqX);
                    $character->setPosZ($reqZ);
                    $character->setPosY($reqY);
                    $character->setZoneCode($terrainService->getZoneCode($reqX, $reqZ));
                } else {
                    $correctedPosition = true;
                }
            } elseif ($dist <= $maxAllowed && $terrainService->isWalkable($reqX, $reqZ)) {
                $character->setPosX($reqX);
                $character->setPosZ($reqZ);
                $character->setPosY($terrainService->getHeight($reqX, $reqZ));
                $character->setZoneCode($terrainService->getZoneCode($reqX, $reqZ));
            } elseif ($dist > $maxAllowed) {
                $correctedPosition = true;
            }

            $character->setRotY($reqRotY);
            $character->setIsMoving($isMoving);
        } else {
            // Ensure Y always matches heightmap when grounded or dead
            if (!$isFlying || $character->isDead()) {
                $character->setPosY($terrainService->getHeight($character->getPosX(), $character->getPosZ()));
            }
            $character->setIsMoving(false);
        }

        // Update optional target selection
        if (array_key_exists('targetType', $data)) {
            $tType = $data['targetType'];
            $character->setTargetType(in_array($tType, ['MONSTER', 'PLAYER'], true) ? $tType : null);
            $character->setTargetId(isset($data['targetId']) ? (int) $data['targetId'] : null);
        }

        $character->setLastSeenAt($now);
        $questService->onZoneEntered($character, $character->getZoneCode());
        $em->flush();

        // Trigger autonomous player-bot simulation, then lazy 1 Hz zone simulation tick under Symfony Lock
        $zoneCode = $character->getZoneCode();
        $botSimulationService->tick($zoneCode, $now);
        $simulationService->maybeTickZone($zoneCode, $now);
        $botSimulationService->purgeDeadBots();

        // Fetch spatial delta (nearby online players, nearby monsters, recent combat events & chat)
        $nearbyPlayers = $characterRepository->findOnlineNearby(
            $character->getPosX(),
            $character->getPosZ(),
            self::INTEREST_RADIUS,
            $now,
            $character->getId(),
        );

        $nearbyMonsters = $monsterRepository->findNearby(
            $character->getPosX(),
            $character->getPosZ(),
            self::INTEREST_RADIUS,
        );

        $nearbyLoot = array_values(array_filter(
            $lootRepository->findActiveNearby(
                $character->getPosX(),
                $character->getPosZ(),
                self::INTEREST_RADIUS,
                $now,
            ),
            static fn ($drop): bool => !$drop->isPersonalChest() || $drop->canBeLootedBy($character, $now),
        ));

        $worldBosses = $monsterRepository->findWorldBosses();
        $worldBossSummary = $worldBosses !== []
            ? $payloadBuilder->buildWorldBossSummary($worldBosses[0], $now)
            : null;

        $lastEventId = (int) ($data['lastEventId'] ?? 0);
        $lastChatId = (int) ($data['lastChatId'] ?? 0);

        $eventZones = array_values(array_unique(array_merge([$zoneCode], array_map(
            static fn ($m): string => $m->getZoneCode(),
            $nearbyMonsters,
        ))));
        $events = $combatEventRepository->findSinceInZones($eventZones, $lastEventId, $now);
        $chatMessages = $chatRepository->findVisibleSince($zoneCode, $character->getParty()?->getId(), $lastChatId);

        $pendingInvitePartyName = null;
        if ($character->getPendingPartyInviteId() !== null) {
            $pendingInvitePartyName = 'Groupe #'.$character->getPendingPartyInviteId();
        }

        $zonePlayerCount = max(1, $characterRepository->countOnlineInZone($zoneCode, $now));
        $respawnMultiplier = MonsterRespawnScaler::computeSpeedMultiplier($zonePlayerCount);

        return $this->json([
            'serverTime' => round($now, 3),
            'correctedPosition' => $correctedPosition,
            'onlineCount' => $characterRepository->countOnline($now),
            'zonePlayerCount' => $zonePlayerCount,
            'respawnMultiplier' => $respawnMultiplier,
            'atmosphere' => WorldAtmosphere::compute($zoneCode, $now),
            'self' => $payloadBuilder->buildSelf($character, $now),
            'zone' => WorldCatalog::zones()[$zoneCode] ?? WorldCatalog::zones()['lagon_ouest'],
            'players' => array_map(
                static fn (Character $p): array => $payloadBuilder->buildPublicPlayer($p, $now),
                $nearbyPlayers,
            ),
            'monsters' => array_map(
                static fn ($m): array => $payloadBuilder->buildMonster($m, $now),
                $nearbyMonsters,
            ),
            'events' => array_map(static fn ($e): array => $e->toArray(), $events),
            'chat' => array_map(static fn ($m): array => $m->toArray(), $chatMessages),
            'party' => $payloadBuilder->buildParty($character->getParty(), $now),
            'quests' => $questService->buildQuestState($character),
            'inventory' => $inventoryService->buildInventoryState($character),
            'lootDrops' => array_map(
                static fn ($drop): array => $drop->toArray($character, $now),
                $nearbyLoot,
            ),
            'worldBoss' => $worldBossSummary,
            'pendingInvite' => $character->getPendingPartyInviteId() !== null ? [
                'partyId' => $character->getPendingPartyInviteId(),
                'label' => $pendingInvitePartyName,
            ] : null,
        ]);
    }
}
