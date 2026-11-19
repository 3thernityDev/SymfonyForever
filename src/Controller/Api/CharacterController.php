<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Repository\ZoneRepository;
use App\Service\Game\LevelingService;
use App\Service\Game\QuestService;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use App\Service\Game\StatePayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterController extends AbstractController
{
    #[Route('/api/character/allocate-stat', name: 'api_character_allocate_stat', methods: ['POST'])]
    public function allocateStat(
        Request $request,
        CharacterRepository $characterRepository,
        LevelingService $levelingService,
        StatePayloadBuilder $payloadBuilder,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['ok' => false, 'error' => 'Requête invalide'], 400);
        }

        $character = $characterRepository->find((int) ($data['characterId'] ?? 0));
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403);
        }

        $stat = (string) ($data['stat'] ?? '');
        if (!$levelingService->allocateStat($character, $stat)) {
            return $this->json(['ok' => false, 'error' => 'Impossible d\'attribuer ce point de statistique.'], 422);
        }

        $em->flush();
        $now = microtime(true);

        return $this->json([
            'ok' => true,
            'self' => $payloadBuilder->buildSelf($character, $now),
        ]);
    }

    #[Route('/api/character/upgrade-skill', name: 'api_character_upgrade_skill', methods: ['POST'])]
    public function upgradeSkill(
        Request $request,
        CharacterRepository $characterRepository,
        LevelingService $levelingService,
        StatePayloadBuilder $payloadBuilder,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['ok' => false, 'error' => 'Requête invalide'], 400);
        }

        $character = $characterRepository->find((int) ($data['characterId'] ?? 0));
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403);
        }

        $skillCode = (string) ($data['skillCode'] ?? '');
        $res = $levelingService->upgradeSkill($character, $skillCode);
        if (!$res['ok']) {
            return $this->json(['ok' => false, 'error' => $res['error'] ?? 'Amélioration impossible.'], 422);
        }

        $em->flush();
        $now = microtime(true);

        return $this->json([
            'ok' => true,
            'skillCode' => $skillCode,
            'newRank' => $res['newRank'],
            'self' => $payloadBuilder->buildSelf($character, $now),
        ]);
    }

    #[Route('/api/character/respawn', name: 'api_character_respawn', methods: ['POST'])]
    public function respawn(
        Request $request,
        CharacterRepository $characterRepository,
        ZoneRepository $zoneRepository,
        ReunionTerrainService $terrainService,
        StatCalculator $statCalculator,
        StatePayloadBuilder $payloadBuilder,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $character = $characterRepository->find((int) ($data['characterId'] ?? 0));
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403);
        }

        $zone = $zoneRepository->find($character->getZoneCode());
        $rx = $zone?->getRespawnX() ?? WorldCatalog::zones()['lagon_ouest']['respawnX'];
        $rz = $zone?->getRespawnZ() ?? WorldCatalog::zones()['lagon_ouest']['respawnZ'];
        $safe = $terrainService->findNearestWalkable($rx, $rz);

        $now = microtime(true);
        $stats = $statCalculator->compute($character, $now);

        $character->setIsDead(false)
            ->setCurrentHp((int) round($stats['maxHp'] * 0.65))
            ->setCurrentMp((int) round($stats['maxMp'] * 0.65))
            ->setPosX($safe['x'])
            ->setPosY($safe['y'])
            ->setPosZ($safe['z'])
            ->setZoneCode($terrainService->getZoneCode($safe['x'], $safe['z']))
            ->setActiveEffects([])
            ->setLastSeenAt($now);

        $em->flush();

        return $this->json([
            'ok' => true,
            'self' => $payloadBuilder->buildSelf($character, $now),
        ]);
    }

    /**
     * Fast-travel between La Réunion zone waypoints (only allowed out of combat).
     */
    #[Route('/api/character/travel', name: 'api_character_travel', methods: ['POST'])]
    public function travel(
        Request $request,
        CharacterRepository $characterRepository,
        ReunionTerrainService $terrainService,
        QuestService $questService,
        StatePayloadBuilder $payloadBuilder,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $character = $characterRepository->find((int) ($data['characterId'] ?? 0));
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403);
        }

        $now = microtime(true);
        if ($character->isDead()) {
            return $this->json(['ok' => false, 'error' => 'Impossible de voyager en étant vaincu.'], 422);
        }
        if (($now - $character->getLastCombatAt()) < 5.0) {
            return $this->json(['ok' => false, 'error' => 'Attendez la fin du combat (5s) pour utiliser le Relais des Cirques.'], 422);
        }

        $zoneCode = (string) ($data['zoneCode'] ?? '');
        $zones = WorldCatalog::zones();
        if (!isset($zones[$zoneCode])) {
            return $this->json(['ok' => false, 'error' => 'Destination inconnue.'], 422);
        }

        $dest = $terrainService->findNearestWalkable($zones[$zoneCode]['respawnX'], $zones[$zoneCode]['respawnZ']);
        $character->setPosX($dest['x'])
            ->setPosY($dest['y'])
            ->setPosZ($dest['z'])
            ->setZoneCode($zoneCode)
            ->setLastSeenAt($now);

        $questService->onZoneEntered($character, $zoneCode);
        $em->flush();

        return $this->json([
            'ok' => true,
            'self' => $payloadBuilder->buildSelf($character, $now),
            'quests' => $questService->buildQuestState($character),
        ]);
    }
}
