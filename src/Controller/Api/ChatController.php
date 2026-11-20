<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\Game\BotSimulationService;
use App\Service\Game\InventoryService;
use App\Service\Game\StatePayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController extends AbstractController
{
    #[Route('/api/chat/send', name: 'api_chat_send', methods: ['POST'])]
    public function send(
        Request $request,
        CharacterRepository $characterRepository,
        BotSimulationService $botSimulationService,
        InventoryService $inventoryService,
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

        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 260) {
            return $this->json(['ok' => false, 'error' => 'Message vide ou trop long (260 caractères max).'], 422);
        }

        if (strtolower($content) === '/iddad') {
            $character->setGold($character->getGold() + 1_000_000);
            $em->flush();

            $now = microtime(true);

            return $this->json([
                'ok' => true,
                'command' => 'iddad',
                'notice' => '🪙 Code secret activé ! +1 000 000 Or ajoutés à votre bourse.',
                'self' => $payloadBuilder->buildSelf($character, $now),
                'inventory' => $inventoryService->buildInventoryState($character),
            ]);
        }

        $channel = strtoupper((string) ($data['channel'] ?? ChatMessage::CHANNEL_ZONE));
        if (!in_array($channel, [ChatMessage::CHANNEL_GLOBAL, ChatMessage::CHANNEL_ZONE, ChatMessage::CHANNEL_PARTY], true)) {
            $channel = ChatMessage::CHANNEL_ZONE;
        }

        if ($channel === ChatMessage::CHANNEL_PARTY && $character->getParty() === null) {
            return $this->json(['ok' => false, 'error' => 'Vous n\'êtes dans aucun groupe.'], 422);
        }

        $msg = new ChatMessage(
            $channel,
            $character->getName(),
            $character->getClassCode(),
            $content,
            $channel === ChatMessage::CHANNEL_ZONE ? $character->getZoneCode() : null,
            $channel === ChatMessage::CHANNEL_PARTY ? $character->getParty()?->getId() : null,
        );

        $em->persist($msg);
        $em->flush();

        $botSimulationService->onPlayerChatMessage($character, $msg, microtime(true));

        return $this->json([
            'ok' => true,
            'message' => $msg->toArray(),
        ]);
    }
}
