<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Geographic constants and zone definitions for La Réunion (7168 x 7168 world units, 3.5x scale).
 * At standard 6.0 u/s walk speed, crossing the island takes >= 10 minutes on foot.
 * Coordinate convention (Three.js):
 *   -X = West (Lagon / Saint-Gilles), +X = East (Fournaise / Sainte-Rose)
 *   -Z = North (Saint-Denis / Salazie), +Z = South (Saint-Pierre / Sud Sauvage)
 */
final class WorldCatalog
{
    public const int MAP_RESOLUTION = 768;
    public const float WORLD_SCALE = 3.5;
    public const float RELIEF_SCALE = 1.85;
    public const float WORLD_SIZE = 7168.0;
    public const float HALF_WORLD = 3584.0;
    public const float MAX_ELEVATION = 340.0; // Scales 3070m Piton des Neiges majestically for the enlarged island
    public const float SEA_LEVEL = 6.0;
    public const float WALKABLE_MIN_Y = 4.2; // Allow wading into shallow turquoise lagoon!
    public const float MAX_WALKABLE_SLOPE = 1.35; // tan(~53 deg) — impassable steep remparts
    public const float PLAYER_WALK_SPEED = 6.0;
    public const float PLAYER_SPRINT_SPEED = 11.5;

    public const int BIOME_OCEAN = 0;
    public const int BIOME_LAGOON_BEACH = 1;
    public const int BIOME_WEST_COAST = 2;
    public const int BIOME_TROPICAL_FOREST = 3; // Salazie & East
    public const int BIOME_CIRQUE_REMPART = 4;  // Mafate / Cilaos rocky valleys
    public const int BIOME_HIGHLANDS = 5;       // Piton des Neiges alpine rock
    public const int BIOME_PLAINE_SABLES = 6;   // Ochre volcanic desert
    public const int BIOME_VOLCANO_LAVA = 7;    // Fournaise & Dolomieu crater
    public const int BIOME_SUD_SAUVAGE = 8;     // Black basalt southern coast

    public static function scaleCoord(float $base): float
    {
        return round($base * self::WORLD_SCALE, 1);
    }

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
                'maxLevel' => 10,
                'centerX' => self::scaleCoord(-560.0),
                'centerZ' => self::scaleCoord(-80.0),
                'respawnX' => self::scaleCoord(-585.0),
                'respawnZ' => self::scaleCoord(-95.0),
                'fogColor' => '#a5dff9',
                'skyColor' => '#4da6e8',
                'ambientColor' => '#ffe8c2',
                'isSafeHub' => true,
            ],
            'salazie' => [
                'code' => 'salazie',
                'name' => 'Cirque de Salazie',
                'subtitle' => 'Cascades & Forêts Émeraude',
                'minLevel' => 10,
                'maxLevel' => 18,
                'centerX' => self::scaleCoord(95.0),
                'centerZ' => self::scaleCoord(-290.0),
                'respawnX' => self::scaleCoord(95.0),
                'respawnZ' => self::scaleCoord(-290.0),
                'fogColor' => '#9ecfb8',
                'skyColor' => '#5fa897',
                'ambientColor' => '#d2f5e3',
            ],
            'mafate' => [
                'code' => 'mafate',
                'name' => 'Cirque de Mafate',
                'subtitle' => 'Îlets & Remparts Escarpés',
                'minLevel' => 18,
                'maxLevel' => 27,
                'centerX' => self::scaleCoord(-250.0),
                'centerZ' => self::scaleCoord(-240.0),
                'respawnX' => self::scaleCoord(-250.0),
                'respawnZ' => self::scaleCoord(-240.0),
                'fogColor' => '#b9c9d6',
                'skyColor' => '#5b89b8',
                'ambientColor' => '#e2ecf5',
            ],
            'cilaos' => [
                'code' => 'cilaos',
                'name' => 'Cirque de Cilaos',
                'subtitle' => 'Thermes & Hauts Plateaux',
                'minLevel' => 27,
                'maxLevel' => 36,
                'centerX' => self::scaleCoord(-130.0),
                'centerZ' => self::scaleCoord(145.0),
                'respawnX' => self::scaleCoord(-130.0),
                'respawnZ' => self::scaleCoord(145.0),
                'fogColor' => '#c7d8e8',
                'skyColor' => '#3b82c4',
                'ambientColor' => '#fff2d6',
            ],
            'sud_sauvage' => [
                'code' => 'sud_sauvage',
                'name' => 'Sud Sauvage',
                'subtitle' => 'Falaises de Basalte & Grand Brûlé',
                'minLevel' => 36,
                'maxLevel' => 45,
                'centerX' => self::scaleCoord(190.0),
                'centerZ' => self::scaleCoord(520.0),
                'respawnX' => self::scaleCoord(165.0),
                'respawnZ' => self::scaleCoord(480.0),
                'fogColor' => '#7d8a96',
                'skyColor' => '#3d5873',
                'ambientColor' => '#c9d4de',
            ],
            'piton_neiges' => [
                'code' => 'piton_neiges',
                'name' => 'Piton des Neiges',
                'subtitle' => 'Toit de l\'Océan Indien (3 070 m)',
                'minLevel' => 45,
                'maxLevel' => 54,
                'centerX' => self::scaleCoord(-75.0),
                'centerZ' => self::scaleCoord(-65.0),
                'respawnX' => -364.0,
                'respawnZ' => 70.0,
                'fogColor' => '#d7e3f0',
                'skyColor' => '#2b5d9e',
                'ambientColor' => '#dbeafe',
            ],
            'fournaise' => [
                'code' => 'fournaise',
                'name' => 'Piton de la Fournaise',
                'subtitle' => 'Plaine des Sables & Cratère Dolomieu',
                'minLevel' => 53,
                'maxLevel' => 60,
                'centerX' => self::scaleCoord(430.0),
                'centerZ' => self::scaleCoord(270.0),
                'respawnX' => self::scaleCoord(255.0),
                'respawnZ' => self::scaleCoord(210.0),
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
            ['name' => 'Camp de Saint-Gilles (Hub)', 'x' => self::scaleCoord(-585.0), 'z' => self::scaleCoord(-95.0), 'type' => 'hub'],
            ['name' => 'Cirque de Mafate', 'x' => self::scaleCoord(-250.0), 'z' => self::scaleCoord(-240.0), 'type' => 'cirque'],
            ['name' => 'Cirque de Salazie', 'x' => self::scaleCoord(95.0), 'z' => self::scaleCoord(-290.0), 'type' => 'cirque'],
            ['name' => 'Cirque de Cilaos', 'x' => self::scaleCoord(-130.0), 'z' => self::scaleCoord(145.0), 'type' => 'cirque'],
            ['name' => 'Piton des Neiges (3070m)', 'x' => self::scaleCoord(-75.0), 'z' => self::scaleCoord(-65.0), 'type' => 'peak'],
            ['name' => 'Plaine des Sables', 'x' => self::scaleCoord(270.0), 'z' => self::scaleCoord(215.0), 'type' => 'zone'],
            ['name' => 'Cratère Dolomieu (Boss)', 'x' => self::scaleCoord(440.0), 'z' => self::scaleCoord(275.0), 'type' => 'boss'],
            ['name' => 'Sud Sauvage', 'x' => self::scaleCoord(190.0), 'z' => self::scaleCoord(520.0), 'type' => 'zone'],
        ];
    }
}
