<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Game\CharacterClass;
use App\Game\ItemRegistry;
use App\Game\NpcRegistry;
use App\Game\QuestRegistry;
use App\Game\SkillRegistry;
use App\Game\WorldCatalog;
use App\Service\Game\ReunionTerrainService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ManifestController extends AbstractController
{
    #[Route('/api/game/manifest', name: 'api_game_manifest', methods: ['GET'])]
    public function manifest(
        SkillRegistry $skillRegistry,
        NpcRegistry $npcRegistry,
        QuestRegistry $questRegistry,
        ItemRegistry $itemRegistry,
        ReunionTerrainService $terrainService,
    ): JsonResponse {
        $classes = [];
        foreach (CharacterClass::cases() as $cls) {
            $classes[$cls->value] = $cls->toArray();
        }

        $skills = [];
        foreach ($skillRegistry->all() as $skill) {
            $skills[$skill->code] = $skill->toArray();
        }

        $vendorStocks = [];
        foreach ($npcRegistry->all() as $npc) {
            $vendorStocks[$npc->code] = $itemRegistry->vendorStockForNpc($npc->code);
        }

        return $this->json([
            'gameTitle' => 'SymfonyForever',
            'world' => [
                'mapResolution' => WorldCatalog::MAP_RESOLUTION,
                'worldSize' => WorldCatalog::WORLD_SIZE,
                'maxElevation' => WorldCatalog::MAX_ELEVATION,
                'seaLevel' => WorldCatalog::SEA_LEVEL,
                'walkableMinY' => WorldCatalog::WALKABLE_MIN_Y,
                'maxWalkableSlope' => WorldCatalog::MAX_WALKABLE_SLOPE,
                'heightmapUrl' => '/assets/map/reunion_heightmap.png',
                'biomesUrl' => '/assets/map/reunion_biomes.png',
                'minimapUrl' => '/assets/map/reunion_minimap.png',
            ],
            'zones' => WorldCatalog::zones(),
            'landmarks' => WorldCatalog::landmarks(),
            'npcs' => $npcRegistry->toManifestArray($terrainService),
            'quests' => $questRegistry->toManifestArray(),
            'items' => $itemRegistry->toManifestArray(),
            'vendorStocks' => $vendorStocks,
            'classes' => $classes,
            'skills' => $skills,
            'gcd' => SkillRegistry::GLOBAL_COOLDOWN,
        ]);
    }
}
