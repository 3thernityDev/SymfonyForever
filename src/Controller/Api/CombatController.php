<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\Game\CombatService;
use App\Service\Game\QuestService;
use App\Service\Game\StatePayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CombatController extends AbstractController
{
    #[Route('/api/combat/cast', name: 'api_combat_cast', methods: ['POST'])]
    public function cast(
        Request $request,
        CharacterRepository $characterRepository,
        CombatService $combatService,
        QuestService $questService,
        StatePayloadBuilder $payloadBuilder,
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
        $targetType = isset($data['targetType']) ? (string) $data['targetType'] : null;
        $targetId = isset($data['targetId']) ? (int) $data['targetId'] : null;

        $now = microtime(true);
        $result = $combatService->castSkill($character, $skillCode, $targetType, $targetId, $now);

        return $this->json([
            ...$result,
            'self' => $payloadBuilder->buildSelf($character, $now),
            'quests' => $questService->buildQuestState($character),
        ], $result['ok'] ? 200 : 422);
    }
}
