<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\Game\QuestService;
use App\Service\Game\StatePayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class QuestController extends AbstractController
{
    #[Route('/api/quests/accept', name: 'api_quests_accept', methods: ['POST'])]
    public function accept(
        Request $request,
        CharacterRepository $characterRepository,
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

        $questCode = (string) ($data['questCode'] ?? '');
        $res = $questService->acceptQuest($character, $questCode);

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, microtime(true)),
            'quests' => $questService->buildQuestState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/quests/complete', name: 'api_quests_complete', methods: ['POST'])]
    public function complete(
        Request $request,
        CharacterRepository $characterRepository,
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

        $questCode = (string) ($data['questCode'] ?? '');
        $now = microtime(true);
        $res = $questService->completeQuest($character, $questCode, $now);

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, $now),
            'quests' => $questService->buildQuestState($character),
        ], $res['ok'] ? 200 : 422);
    }
}
