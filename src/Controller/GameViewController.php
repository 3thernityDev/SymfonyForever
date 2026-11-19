<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\ZoneRepository;
use App\Service\Game\WorldSeederService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameViewController extends AbstractController
{
    #[Route('/play/{id}', name: 'app_play', methods: ['GET'])]
    public function play(
        int $id,
        CharacterRepository $characterRepository,
        ZoneRepository $zoneRepository,
        WorldSeederService $seeder,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $character = $characterRepository->find($id);

        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Personnage introuvable ou non autorisé.');
        }

        // Auto-seed world on first launch so the island is always populated!
        if ($zoneRepository->count([]) === 0) {
            $seeder->seed();
        }

        return $this->render('game/play.html.twig', [
            'character' => $character,
        ]);
    }
}
