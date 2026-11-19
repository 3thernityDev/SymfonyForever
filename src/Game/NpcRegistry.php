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
        $list = [
            new NpcDefinition(
                code: 'gramoun_tijean',
                name: 'Gramoun Ti-Jean',
                title: 'Ancien du Lagon',
                greeting: 'Oté l\'aventurier ! Bienvenue à Saint-Gilles. Les créatures de l\'île s\'agitent depuis que le Volcan gronde... Tu es prêt à faire tes preuves ?',
                zoneCode: 'lagon_ouest',
                x: -578.0,
                z: -88.0,
                rotY: -0.9,
                coatColor: '#0d9488',
                accentColor: '#fde047',
            ),
            new NpcDefinition(
                code: 'soeur_rosalie',
                name: 'Sœur Rosalie',
                title: 'Gardienne des Cascades',
                greeting: 'Bienvenue dans le Cirque de Salazie. La brume émeraude cache bien des dangers : sangliers et papangues bloquent nos sentiers.',
                zoneCode: 'salazie',
                x: 92.0,
                z: -282.0,
                rotY: 0.4,
                coatColor: '#15803d',
                accentColor: '#a7f3d0',
            ),
            new NpcDefinition(
                code: 'ticoq_guide',
                name: 'Ti-Coq le Guide',
                title: 'Éclaireur de Mafate',
                greeting: 'Peu d\'aventuriers arrivent jusqu\'ici à pied ! Les remparts de Mafate s\'éveillent : des golems de schiste menacent nos îlets.',
                zoneCode: 'mafate',
                x: -244.0,
                z: -234.0,
                rotY: 0.8,
                coatColor: '#475569',
                accentColor: '#38bdf8',
            ),
            new NpcDefinition(
                code: 'maitre_thermalis',
                name: 'Maître Thermalis',
                title: 'Sage de Cilaos',
                greeting: 'L\'air pur de Cilaos fortifie l\'esprit, mais les cerfs de Java et les sentinelles de pierre sont devenus fous furieux.',
                zoneCode: 'cilaos',
                x: -124.0,
                z: 142.0,
                rotY: -0.6,
                coatColor: '#1d4ed8',
                accentColor: '#bae6fd',
            ),
            new NpcDefinition(
                code: 'capitaine_basalte',
                name: 'Capitaine Basalte',
                title: 'Vigie du Sud Sauvage',
                greeting: 'Ici la roche noire rencontre l\'Océan Indien. Des lézards d\'obsidienne remontent les anciennes coulées du Grand Brûlé.',
                zoneCode: 'sud_sauvage',
                x: 162.0,
                z: 474.0,
                rotY: 0.2,
                coatColor: '#334155',
                accentColor: '#fb923c',
            ),
            new NpcDefinition(
                code: 'eclaireuse_maido',
                name: 'Éclaireuse Maïdo',
                title: 'Alpiniste des Hauts',
                greeting: 'Le Piton des Neiges culmine à 3 070 mètres ! Un Colosse des Nuages garde le sommet. Seuls les héros aguerris y survivent.',
                zoneCode: 'piton_neiges',
                x: -102.0,
                z: 18.0,
                rotY: 2.8,
                coatColor: '#6366f1',
                accentColor: '#e0e7ff',
            ),
            new NpcDefinition(
                code: 'vulcanologue_piton',
                name: 'Vulcanologue Piton',
                title: 'Veilleur de la Fournaise',
                greeting: 'Te voici enfin à la Plaine des Sables ! Le Cratère Dolomieu bout de rage : prépare ton groupe pour affronter le Seigneur du Dolomieu.',
                zoneCode: 'fournaise',
                x: 262.0,
                z: 208.0,
                rotY: 1.3,
                coatColor: '#b91c1c',
                accentColor: '#facc15',
            ),
        ];

        foreach ($list as $npc) {
            $this->npcs[$npc->code] = $npc;
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
