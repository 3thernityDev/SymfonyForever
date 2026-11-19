<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\Game\InventoryService;
use App\Service\Game\StatePayloadBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InventoryController extends AbstractController
{
    #[Route('/api/loot/pickup', name: 'api_loot_pickup', methods: ['POST'])]
    public function pickup(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $now = microtime(true);
        $res = $inventoryService->pickupLoot($character, (int) ($data['lootId'] ?? 0), $now);

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, $now),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/inventory/equip', name: 'api_inventory_equip', methods: ['POST'])]
    public function equip(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $res = $inventoryService->equipItem($character, (int) ($data['characterItemId'] ?? 0));

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, microtime(true)),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/inventory/unequip', name: 'api_inventory_unequip', methods: ['POST'])]
    public function unequip(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $res = $inventoryService->unequipSlot($character, (string) ($data['slot'] ?? ''));

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, microtime(true)),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/inventory/use', name: 'api_inventory_use', methods: ['POST'])]
    public function useItem(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $now = microtime(true);
        $res = $inventoryService->useItem($character, (int) ($data['characterItemId'] ?? 0), $now);

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, $now),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/inventory/drop', name: 'api_inventory_drop', methods: ['POST'])]
    public function dropItem(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $res = $inventoryService->dropItem($character, (int) ($data['characterItemId'] ?? 0));

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, microtime(true)),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/vendor/sell', name: 'api_vendor_sell', methods: ['POST'])]
    public function sell(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $res = $inventoryService->sellItem(
            $character,
            (int) ($data['characterItemId'] ?? 0),
            (string) ($data['npcCode'] ?? ''),
        );

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, microtime(true)),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/vendor/sell-junk', name: 'api_vendor_sell_junk', methods: ['POST'])]
    public function sellJunk(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $res = $inventoryService->sellAllJunk($character, (string) ($data['npcCode'] ?? ''));

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, microtime(true)),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    #[Route('/api/vendor/buy', name: 'api_vendor_buy', methods: ['POST'])]
    public function buy(
        Request $request,
        CharacterRepository $characterRepository,
        InventoryService $inventoryService,
        StatePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        [$character, $data, $err] = $this->resolveOwnedCharacter($request, $characterRepository);
        if ($err !== null || !$character instanceof Character) {
            return $err ?? $this->json(['ok' => false, 'error' => 'Erreur'], 400);
        }

        $res = $inventoryService->buyItem(
            $character,
            (string) ($data['itemCode'] ?? ''),
            (string) ($data['npcCode'] ?? ''),
        );

        return $this->json([
            ...$res,
            'self' => $payloadBuilder->buildSelf($character, microtime(true)),
            'inventory' => $inventoryService->buildInventoryState($character),
        ], $res['ok'] ? 200 : 422);
    }

    /**
     * @return array{?Character, array<string, mixed>, ?JsonResponse}
     */
    private function resolveOwnedCharacter(Request $request, CharacterRepository $repo): array
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return [null, [], $this->json(['ok' => false, 'error' => 'Requête JSON invalide'], 400)];
        }

        $character = $repo->find((int) ($data['characterId'] ?? 0));
        if (!$character instanceof Character || $character->getOwner()->getId() !== $user->getId()) {
            return [null, [], $this->json(['ok' => false, 'error' => 'Personnage non autorisé'], 403)];
        }

        return [$character, $data, null];
    }
}
