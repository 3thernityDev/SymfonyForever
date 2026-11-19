<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Geographic constants and zone definitions for La Réunion (2048 x 2048 world units).
 * Coordinate convention (Three.js):
 *   -X = West (Lagon / Saint-Gilles), +X = East (Fournaise / Sainte-Rose)
 *   -Z = North (Saint-Denis / Salazie), +Z = South (Saint-Pierre / Sud Sauvage)
 */
final class WorldCatalog
{
    public const int MAP_RESOLUTION = 512;
    public const float WORLD_SIZE = 2048.0;
    public const float HALF_WORLD = 1024.0;
    public const float MAX_ELEVATION = 185.0; // Scales 3070m Piton des Neiges nicely in 3D
    public const float SEA_LEVEL = 6.0;
    public const float WALKABLE_MIN_Y = 4.2; // Allow wading into shallow turquoise lagoon!
    public const float MAX_WALKABLE_SLOPE = 1.35; // tan(~53 deg) — impassable steep remparts

    public const int BIOME_OCEAN = 0;
    public const int BIOME_LAGOON_BEACH = 1;
    public const int BIOME_WEST_COAST = 2;
    public const int BIOME_TROPICAL_FOREST = 3; // Salazie & East
    public const int BIOME_CIRQUE_REMPART = 4;  // Mafate / Cilaos rocky valleys
    public const int BIOME_HIGHLANDS = 5;       // Piton des Neiges alpine rock
    public const int BIOME_PLAINE_SABLES = 6;   // Ochre volcanic desert
    public const int BIOME_VOLCANO_LAVA = 7;    // Fournaise & Dolomieu crater
    public const int BIOME_SUD_SAUVAGE = 8;     // Black basalt southern coast

    /**
     * Zone metadata keyed by zone code.
     *
     * @return array<string, array{
     *   code: string,
     *   name: string,
     *   subtitle: string,
     *   minLevel: int,
     *   maxLevel: int,
     *   centerX: float,
     *   centerZ: float,
     *   respawnX: float,
     *   respawnZ: float,
     *   fogColor: string,
     *   skyColor: string,
     *   ambientColor: string,
     *   isSafeHub?: bool
     * }>
     */
    public static function zones(): array
    {
        return [
            'lagon_ouest' => [
                'code' => 'lagon_ouest',
                'name' => 'Lagon de l\'Ouest',
                'subtitle' => 'Saint-Gilles & Ermitage',
                'minLevel' => 1,
                'maxLevel' => 3,
                'centerX' => -560.0,
                'centerZ' => -80.0,
                'respawnX' => -585.0,
                'respawnZ' => -95.0,
                'fogColor' => '#a5dff9',
                'skyColor' => '#4da6e8',
                'ambientColor' => '#ffe8c2',
                'isSafeHub' => true,
            ],
            'salazie' => [
                'code' => 'salazie',
                'name' => 'Cirque de Salazie',
                'subtitle' => 'Cascades & Forêts Émeraude',
                'minLevel' => 3,
                'maxLevel' => 5,
                'centerX' => 95.0,
                'centerZ' => -290.0,
                'respawnX' => 95.0,
                'respawnZ' => -290.0,
                'fogColor' => '#9ecfb8',
                'skyColor' => '#5fa897',
                'ambientColor' => '#d2f5e3',
            ],
            'mafate' => [
                'code' => 'mafate',
                'name' => 'Cirque de Mafate',
                'subtitle' => 'Îlets & Remparts Escarpés',
                'minLevel' => 4,
                'maxLevel' => 6,
                'centerX' => -250.0,
                'centerZ' => -240.0,
                'respawnX' => -250.0,
                'respawnZ' => -240.0,
                'fogColor' => '#b9c9d6',
                'skyColor' => '#5b89b8',
                'ambientColor' => '#e2ecf5',
            ],
            'cilaos' => [
                'code' => 'cilaos',
                'name' => 'Cirque de Cilaos',
                'subtitle' => 'Thermes & Hauts Plateaux',
                'minLevel' => 5,
                'maxLevel' => 7,
                'centerX' => -130.0,
                'centerZ' => 145.0,
                'respawnX' => -130.0,
                'respawnZ' => 145.0,
                'fogColor' => '#c7d8e8',
                'skyColor' => '#3b82c4',
                'ambientColor' => '#fff2d6',
            ],
            'sud_sauvage' => [
                'code' => 'sud_sauvage',
                'name' => 'Sud Sauvage',
                'subtitle' => 'Falaises de Basalte & Grand Brûlé',
                'minLevel' => 6,
                'maxLevel' => 8,
                'centerX' => 190.0,
                'centerZ' => 520.0,
                'respawnX' => 165.0,
                'respawnZ' => 480.0,
                'fogColor' => '#7d8a96',
                'skyColor' => '#3d5873',
                'ambientColor' => '#c9d4de',
            ],
            'piton_neiges' => [
                'code' => 'piton_neiges',
                'name' => 'Piton des Neiges',
                'subtitle' => 'Toit de l\'Océan Indien (3 070 m)',
                'minLevel' => 7,
                'maxLevel' => 9,
                'centerX' => -75.0,
                'centerZ' => -65.0,
                'respawnX' => -130.0,
                'respawnZ' => 145.0,
                'fogColor' => '#d7e3f0',
                'skyColor' => '#2b5d9e',
                'ambientColor' => '#dbeafe',
            ],
            'fournaise' => [
                'code' => 'fournaise',
                'name' => 'Piton de la Fournaise',
                'subtitle' => 'Plaine des Sables & Cratère Dolomieu',
                'minLevel' => 8,
                'maxLevel' => 10,
                'centerX' => 430.0,
                'centerZ' => 270.0,
                'respawnX' => 255.0,
                'respawnZ' => 210.0,
                'fogColor' => '#6e3a2c',
                'skyColor' => '#471e1a',
                'ambientColor' => '#ff9b6a',
            ],
        ];
    }

    /**
     * Landmark beacons displayed on minimap & 3D horizon.
     *
     * @return list<array{name: string, x: float, z: float, type: string}>
     */
    public static function landmarks(): array
    {
        return [
            ['name' => 'Camp de Saint-Gilles (Hub)', 'x' => -585.0, 'z' => -95.0, 'type' => 'hub'],
            ['name' => 'Cirque de Mafate', 'x' => -250.0, 'z' => -240.0, 'type' => 'cirque'],
            ['name' => 'Cirque de Salazie', 'x' => 95.0, 'z' => -290.0, 'type' => 'cirque'],
            ['name' => 'Cirque de Cilaos', 'x' => -130.0, 'z' => 145.0, 'type' => 'cirque'],
            ['name' => 'Piton des Neiges (3070m)', 'x' => -75.0, 'z' => -65.0, 'type' => 'peak'],
            ['name' => 'Plaine des Sables', 'x' => 270.0, 'z' => 215.0, 'type' => 'zone'],
            ['name' => 'Cratère Dolomieu (Boss)', 'x' => 440.0, 'z' => 275.0, 'type' => 'boss'],
            ['name' => 'Sud Sauvage', 'x' => 190.0, 'z' => 520.0, 'type' => 'zone'],
        ];
    }
}
