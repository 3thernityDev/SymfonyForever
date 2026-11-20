<?php

declare(strict_types=1);

namespace App\Game;

use App\Service\Game\ReunionTerrainService;

final class NpcRegistry
{
    /** @var array<string, NpcDefinition> */
    private array $npcs = [];

    public function __construct()
    {
        // 1. Primary zone storyline NPCs (slot #1 of the 7 zone checkpoint villages)
        $primaryByVillage = [
            'saint_paul' => new NpcDefinition(
                code: 'gramoun_tijean',
                name: 'Gramoun Ti-Jean',
                title: 'Ancien du Lagon',
                greeting: 'Oté l\'aventurier ! Bienvenue à Saint-Gilles. Les créatures de l\'île s\'agitent depuis que le Volcan gronde... Tu es prêt à faire tes preuves ?',
                zoneCode: 'lagon_ouest',
                x: -2040.5,
                z: -325.5,
                rotY: -0.9,
                coatColor: '#0d9488',
                accentColor: '#fde047',
                villageCode: 'saint_paul',
            ),
            'salazie' => new NpcDefinition(
                code: 'soeur_rosalie',
                name: 'Sœur Rosalie',
                title: 'Gardienne des Cascades',
                greeting: 'Bienvenue dans le Cirque de Salazie. La brume émeraude cache bien des dangers : sangliers et papangues bloquent nos sentiers.',
                zoneCode: 'salazie',
                x: 329.5,
                z: -1007.0,
                rotY: 0.4,
                coatColor: '#15803d',
                accentColor: '#a7f3d0',
                villageCode: 'salazie',
            ),
            'mafate' => new NpcDefinition(
                code: 'ticoq_guide',
                name: 'Ti-Coq le Guide',
                title: 'Éclaireur de Mafate',
                greeting: 'Peu d\'aventuriers arrivent jusqu\'ici à pied ! Les remparts de Mafate s\'éveillent : des golems de schiste menacent nos îlets.',
                zoneCode: 'mafate',
                x: -869.0,
                z: -834.0,
                rotY: 0.8,
                coatColor: '#475569',
                accentColor: '#38bdf8',
                villageCode: 'mafate',
            ),
            'cilaos' => new NpcDefinition(
                code: 'maitre_thermalis',
                name: 'Maître Thermalis',
                title: 'Sage de Cilaos',
                greeting: 'L\'air pur de Cilaos fortifie l\'esprit, mais les cerfs de Java et les sentinelles de pierre sont devenus fous furieux.',
                zoneCode: 'cilaos',
                x: -449.0,
                z: 504.5,
                rotY: -0.6,
                coatColor: '#1d4ed8',
                accentColor: '#bae6fd',
                villageCode: 'cilaos',
            ),
            'saint_philippe' => new NpcDefinition(
                code: 'capitaine_basalte',
                name: 'Capitaine Basalte',
                title: 'Vigie du Sud Sauvage',
                greeting: 'Ici la roche noire rencontre l\'Océan Indien. Des lézards d\'obsidienne remontent les anciennes coulées du Grand Brûlé.',
                zoneCode: 'sud_sauvage',
                x: 574.5,
                z: 1674.0,
                rotY: 0.2,
                coatColor: '#334155',
                accentColor: '#fb923c',
                villageCode: 'saint_philippe',
            ),
            'caverne_dufour' => new NpcDefinition(
                code: 'eclaireuse_maido',
                name: 'Éclaireuse Maïdo',
                title: 'Alpiniste des Hauts',
                greeting: 'Le Piton des Neiges culmine à 3 070 mètres ! Un Colosse des Nuages garde le sommet. Seuls les héros aguerris y survivent.',
                zoneCode: 'piton_neiges',
                x: -357.0,
                z: 63.0,
                rotY: 2.8,
                coatColor: '#6366f1',
                accentColor: '#e0e7ff',
                villageCode: 'caverne_dufour',
            ),
            'plaine_palmistes' => new NpcDefinition(
                code: 'vulcanologue_piton',
                name: 'Vulcanologue Piton',
                title: 'Veilleur de la Fournaise',
                greeting: 'Te voici enfin à la Plaine des Sables ! Le Cratère Dolomieu bout de rage : prépare ton groupe pour affronter le Seigneur du Dolomieu.',
                zoneCode: 'fournaise',
                x: 899.5,
                z: 733.0,
                rotY: 1.3,
                coatColor: '#b91c1c',
                accentColor: '#facc15',
                villageCode: 'plaine_palmistes',
            ),
        ];

        foreach ($primaryByVillage as $npc) {
            $this->npcs[$npc->code] = $npc;
        }

        // Safe walkable plaza slots around each village center (avoiding campfire +6.5,+6.5, signpost -5.2,+5.2, beacon +7.5,-7.5)
        $plazaSlots = [
            [9.2, 0.15],
            [9.6, 1.52],
            [9.8, -1.65],
            [10.4, 3.05],
            [10.8, -2.45],
        ];

        $creoleNames = [
            'Tantine Zézette', 'Gramoun Firmin', 'Ti-Paul le Pêcheur', 'Mémé Honorine', 'Compère Léon',
            'Sœur Clarisse', 'Capitaine Zitte', 'Tonton Aristide', 'Doudou Maloya', 'Maître Tiburce',
            'Brigadier Payet', 'Madame Hoarau', 'Pisteur Grondin', 'Herboriste Técher', 'Veilleur Boyer',
            'Artisan Rivière', 'Éclaireur Dijoux', 'Sentinelle Maillot', 'Chasseur Fontaine', 'Guide Lebon',
            'Pêcheur Mussard', 'Tisaneur Nativel', 'Forgeron Turpin', 'Guetteur Bègue', 'Ancien Robert',
        ];

        $zoneArchetypes = [
            'lagon_ouest' => [
                ['Gardien du Récif', '#0284c7', '#fde047'],
                ['Pêcheur des Filaos', '#0d9488', '#bae6fd'],
                ['Vigie de la Côte Ouest', '#0369a1', '#fed7aa'],
                ['Tisaneur du Lagon', '#14b8a6', '#fef08a'],
                ['Milicien des Plages', '#1e40af', '#fde68a'],
            ],
            'salazie' => [
                ['Forestier des Cascades', '#15803d', '#bbf7d0'],
                ['Cueilleur de Chouchous', '#166534', '#fde047'],
                ['Vigie de la Brume', '#047857', '#a7f3d0'],
                ['Pisteur de Bélouve', '#14532d', '#86efac'],
                ['Ancien du Cirque Vert', '#065f46', '#fef08a'],
            ],
            'mafate' => [
                ['Facteur des Îlets', '#475569', '#38bdf8'],
                ['Gardien des Remparts', '#334155', '#fde047'],
                ['Sentinelle du Maïdo', '#1e293b', '#7dd3fc'],
                ['Pisteur des Orangers', '#64748b', '#facc15'],
                ['Intendant du Nord', '#0f172a', '#fb923c'],
            ],
            'cilaos' => [
                ['Vigneron des Hauts', '#1d4ed8', '#fde68a'],
                ['Gardien des Thermes', '#2563eb', '#bae6fd'],
                ['Pisteur du Dimitile', '#1e3a8a', '#fbbf24'],
                ['Artisan des Lentilles', '#0369a1', '#e0f2fe'],
                ['Veilleur des Plateaux', '#3730a3', '#fcd34d'],
            ],
            'sud_sauvage' => [
                ['Guetteur du Cap Méchant', '#334155', '#fb923c'],
                ['Coupeur de Vacoa', '#1e293b', '#4ade80'],
                ['Chasseur du Grand Brûlé', '#7c2d12', '#fdba74'],
                ['Vigie de Basalte', '#0f172a', '#f87171'],
                ['Marin du Grand Sud', '#0f766e', '#fde047'],
            ],
            'piton_neiges' => [
                ['Guide de Haute Montagne', '#4f46e5', '#e0e7ff'],
            ],
            'fournaise' => [
                ['Observateur Volcanique', '#991b1b', '#fde047'],
                ['Garde de l\'Enclos', '#7c2d12', '#fdba74'],
            ],
        ];

        // 2. Ensure every village has at least 1 NPC per group of 5 houses (ceil(houses / 5))
        $nameCursor = 0;
        foreach (WorldCatalog::villages() as $vIdx => $village) {
            $vCode = $village['code'];
            $zCode = $village['zoneCode'];
            $requiredCount = $village['npcCount'];
            $hasPrimary = isset($primaryByVillage[$vCode]);
            $startIndex = $hasPrimary ? 1 : 0;

            $styles = $zoneArchetypes[$zCode] ?? $zoneArchetypes['lagon_ouest'];

            for ($slot = $startIndex; $slot < $requiredCount; ++$slot) {
                [$rad, $ang] = $plazaSlots[$slot % count($plazaSlots)];
                $nx = round($village['x'] + cos($ang) * $rad, 1);
                $nz = round($village['z'] + sin($ang) * $rad, 1);
                $rotY = round(atan2($village['x'] - $nx, $village['z'] - $nz), 2);

                $baseName = $creoleNames[$nameCursor % count($creoleNames)];
                ++$nameCursor;
                [$roleTitle, $coatColor, $accentColor] = $styles[($slot + $vIdx) % count($styles)];

                $npcCode = sprintf('npc_%s_%d', $vCode, $slot + 1);
                $npcName = sprintf('%s (%s)', $baseName, $village['name']);
                $greeting = sprintf(
                    'Oté ! Bienvenue à %s (%d maisons). Notre région de %s est menacée par les créatures sauvages : peux-tu nous prêter main-forte ?',
                    $village['name'],
                    $village['houses'],
                    WorldCatalog::zones()[$zCode]['name'] ?? $zCode,
                );

                $this->npcs[$npcCode] = new NpcDefinition(
                    code: $npcCode,
                    name: $npcName,
                    title: sprintf('%s · %s', $roleTitle, $village['name']),
                    greeting: $greeting,
                    zoneCode: $zCode,
                    x: $nx,
                    z: $nz,
                    rotY: $rotY,
                    coatColor: $coatColor,
                    accentColor: $accentColor,
                    villageCode: $vCode,
                );
            }
        }
    }

    public function get(string $code): ?NpcDefinition
    {
        return $this->npcs[$code] ?? null;
    }

    /**
     * @return list<NpcDefinition>
     */
    public function all(): array
    {
        return array_values($this->npcs);
    }

    /**
     * @return list<NpcDefinition>
     */
    public function byVillage(string $villageCode): array
    {
        return array_values(array_filter(
            $this->npcs,
            static fn (NpcDefinition $n): bool => $n->villageCode === $villageCode,
        ));
    }

    /**
     * Returns resolved NPC world coordinates clamped to walkable Réunion heightmap.
     *
     * @return array{x: float, y: float, z: float}
     */
    public function resolvedPosition(NpcDefinition $npc, ReunionTerrainService $terrain): array
    {
        return $terrain->findNearestWalkable($npc->x, $npc->z);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toManifestArray(ReunionTerrainService $terrain): array
    {
        $out = [];
        foreach ($this->npcs as $npc) {
            $pos = $this->resolvedPosition($npc, $terrain);
            $out[] = [
                'code' => $npc->code,
                'name' => $npc->name,
                'title' => $npc->title,
                'greeting' => $npc->greeting,
                'zoneCode' => $npc->zoneCode,
                'villageCode' => $npc->villageCode,
                'x' => round($pos['x'], 2),
                'y' => round($pos['y'], 2),
                'z' => round($pos['z'], 2),
                'rotY' => $npc->rotY,
                'coatColor' => $npc->coatColor,
                'accentColor' => $npc->accentColor,
            ];
        }

        return $out;
    }
}
