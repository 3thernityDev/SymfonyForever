<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\Party;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\PartyRepository;
use App\Service\Game\BotSimulationService;
use App\Service\Game\StatePayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PartyController extends AbstractController
{
    #[Route('/api/party/invite', name: 'api_party_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        CharacterRepository $characterRepository,
        StatePayloadBuilder $payloadBuilder,
        BotSimulationService $botSimulationService,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $caster = $characterRepository->find((int) ($data['characterId'] ?? 0));
        if (!$caster instanceof Character || $caster->getOwner()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403);
        }

        $targetName = trim((string) ($data['targetName'] ?? ''));
        $target = isset($data['targetCharacterId'])
            ? $characterRepository->find((int) $data['targetCharacterId'])
            : ($targetName !== '' ? $characterRepository->findOneByNameInsensitive($targetName) : null);

        if (!$target instanceof Character || $target->getId() === $caster->getId()) {
            return $this->json(['ok' => false, 'error' => 'Joueur introuvable.'], 404);
        }

        if ($target->isDead()) {
            return $this->json(['ok' => false, 'error' => 'Ce joueur est vaincu.'], 422);
        }

        if ($target->getParty() !== null) {
            return $this->json(['ok' => false, 'error' => 'Ce joueur fait déjà partie d\'un groupe.'], 422);
        }

        $party = $caster->getParty();
        if ($party === null) {
            $party = (new Party())
                ->setName('Groupe de '.$caster->getName())
                ->setLeaderCharacterId((int) $caster->getId());
            $em->persist($party);
            $caster->setParty($party);
            $em->flush();
        }

        if ($party->getMembers()->count() >= Party::MAX_MEMBERS) {
            return $this->json(['ok' => false, 'error' => 'Le groupe/raid est complet (10 joueurs max).'], 422);
        }

        $target->setPendingPartyInviteId($party->getId());
        $botSimulationService->onPartyInviteReceived($target, microtime(true));
        $em->flush();

        return $this->json([
            'ok' => true,
            'message' => sprintf('Invitation envoyée à %s.', $target->getName()),
            'party' => $payloadBuilder->buildParty($party, microtime(true)),
        ]);
    }

    #[Route('/api/party/accept', name: 'api_party_accept', methods: ['POST'])]
    public function accept(
        Request $request,
        CharacterRepository $characterRepository,
        PartyRepository $partyRepository,
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

        $partyId = $character->getPendingPartyInviteId();
        if ($partyId === null) {
            return $this->json(['ok' => false, 'error' => 'Aucune invitation en attente.'], 422);
        }

        $party = $partyRepository->find($partyId);
        $character->setPendingPartyInviteId(null);

        if (!$party instanceof Party) {
            $em->flush();

            return $this->json(['ok' => false, 'error' => 'Ce groupe n\'existe plus.'], 404);
        }

        if ($party->getMembers()->count() >= Party::MAX_MEMBERS) {
            $em->flush();

            return $this->json(['ok' => false, 'error' => 'Le groupe est désormais complet.'], 422);
        }

        $character->setParty($party);
        $em->flush();
        $em->refresh($party);

        return $this->json([
            'ok' => true,
            'party' => $payloadBuilder->buildParty($party, microtime(true)),
        ]);
    }

    #[Route('/api/party/decline', name: 'api_party_decline', methods: ['POST'])]
    public function decline(
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $character = $characterRepository->find((int) ($data['characterId'] ?? 0));
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403);
        }

        $character->setPendingPartyInviteId(null);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/api/party/leave', name: 'api_party_leave', methods: ['POST'])]
    public function leave(
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $character = $characterRepository->find((int) ($data['characterId'] ?? 0));
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403);
        }

        $party = $character->getParty();
        if ($party !== null) {
            $character->setParty(null);
            $em->flush();
            $em->refresh($party);

            if ($party->getMembers()->count() === 0) {
                $em->remove($party);
                $em->flush();
            } elseif ($party->getLeaderCharacterId() === $character->getId()) {
                $first = $party->getMembers()->first();
                if ($first instanceof Character) {
                    $party->setLeaderCharacterId((int) $first->getId());
                    $em->flush();
                }
            }
        }

        return $this->json(['ok' => true]);
    }
}
