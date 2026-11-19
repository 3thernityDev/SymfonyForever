<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Character;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Game\SkillRegistry;
use App\Game\WorldCatalog;
use App\Repository\CharacterRepository;
use App\Service\Game\ReunionTerrainService;
use App\Service\Game\StatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterLobbyController extends AbstractController
{
    #[Route('/characters', name: 'app_characters', methods: ['GET'])]
    public function index(
        CharacterRepository $characterRepository,
        StatCalculator $statCalculator,
        SkillRegistry $skillRegistry,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $characters = $characterRepository->findBy(['owner' => $user], ['id' => 'ASC']);

        $enriched = [];
        foreach ($characters as $c) {
            $enriched[] = [
                'entity' => $c,
                'class' => $c->getCharacterClass(),
                'stats' => $statCalculator->compute($c),
                'zoneName' => WorldCatalog::zones()[$c->getZoneCode()]['name'] ?? $c->getZoneCode(),
            ];
        }

        $classes = [];
        foreach (CharacterClass::cases() as $cls) {
            $classes[] = [
                'meta' => $cls->toArray(),
                'skills' => array_map(static fn ($s): array => $s->toArray(), $skillRegistry->forClass($cls)),
            ];
        }

        return $this->render('lobby/characters.html.twig', [
            'characters' => $enriched,
            'classes' => $classes,
        ]);
    }

    #[Route('/characters/create', name: 'app_characters_create', methods: ['POST'])]
    public function create(
        Request $request,
        CharacterRepository $characterRepository,
        ReunionTerrainService $terrainService,
        StatCalculator $statCalculator,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('create_character', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_characters');
        }

        if ($characterRepository->count(['owner' => $user]) >= 6) {
            $this->addFlash('error', 'Vous avez atteint la limite de 6 personnages.');

            return $this->redirectToRoute('app_characters');
        }

        $name = trim((string) $request->request->get('name', ''));
        $classCode = (string) $request->request->get('class_code', '');
        $class = CharacterClass::tryFrom($classCode);

        if (!preg_match('/^[a-zA-ZÀ-ÿ0-9_-]{3,20}$/u', $name)) {
            $this->addFlash('error', 'Le nom du personnage doit contenir 3 à 20 caractères valides.');

            return $this->redirectToRoute('app_characters');
        }

        if ($class === null) {
            $this->addFlash('error', 'Classe invalide.');

            return $this->redirectToRoute('app_characters');
        }

        if ($characterRepository->findOneByNameInsensitive($name) !== null) {
            $this->addFlash('error', 'Ce nom de personnage est déjà pris sur l\'île.');

            return $this->redirectToRoute('app_characters');
        }

        $hub = WorldCatalog::zones()['lagon_ouest'];
        $spawn = $terrainService->findNearestWalkable($hub['respawnX'], $hub['respawnZ']);

        $character = new Character($user, $name, $class);
        $character->setZoneCode('lagon_ouest')
            ->setPosX($spawn['x'])
            ->setPosY($spawn['y'])
            ->setPosZ($spawn['z'])
            ->setLastSeenAt(microtime(true));

        $stats = $statCalculator->compute($character);
        $character->setCurrentHp($stats['maxHp'])
            ->setCurrentMp($stats['maxMp']);

        $em->persist($character);
        $em->flush();

        $this->addFlash('success', sprintf('%s (%s) est prêt à explorer La Réunion !', $name, $class->label()));

        return $this->redirectToRoute('app_play', ['id' => $character->getId()]);
    }

    #[Route('/characters/{id}/delete', name: 'app_characters_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('delete_char_'.$id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_characters');
        }

        $character = $characterRepository->find($id);
        if ($character instanceof Character && $character->getOwner()->getId() === $user->getId()) {
            $em->remove($character);
            $em->flush();
            $this->addFlash('success', 'Personnage supprimé.');
        }

        return $this->redirectToRoute('app_characters');
    }
}
