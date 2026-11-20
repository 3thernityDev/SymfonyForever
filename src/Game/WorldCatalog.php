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
    public const float HUB_SAFE_RADIUS = 55.0;
    public const float CHECKPOINT_SAFE_RADIUS = 48.0;

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

    /**
     * Authoritative trail polylines (base map units) connecting the 7 leveling zones across La Réunion.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function baseTrails(): array
    {
        return [
            // Saint-Gilles Hub -> Col des Boeufs -> Cirque de Mafate
            [[-585.0, -95.0], [-490.0, -145.0], [-405.0, -210.0], [-320.0, -230.0], [-250.0, -240.0]],
            // Mafate -> Col de Fourche -> Cirque de Salazie
            [[-250.0, -240.0], [-140.0, -270.0], [-30.0, -305.0], [45.0, -300.0], [95.0, -290.0]],
            // Saint-Gilles Hub -> Côte Ouest -> Gorges de Cilaos -> Cirque de Cilaos
            [[-585.0, -95.0], [-545.0, 20.0], [-470.0, 140.0], [-350.0, 225.0], [-230.0, 215.0], [-130.0, 145.0]],
            // Cilaos -> Piton des Neiges summit trail
            [[-130.0, 145.0], [-130.0, 65.0], [-105.0, 0.0], [-75.0, -65.0]],
            // Salazie -> Bélouve -> Piton des Neiges
            [[95.0, -290.0], [45.0, -210.0], [-15.0, -135.0], [-75.0, -65.0]],
            // Cilaos -> Plaine des Cafres -> Plaine des Sables -> Piton de la Fournaise crater
            [[-130.0, 145.0], [-10.0, 190.0], [115.0, 205.0], [240.0, 215.0], [345.0, 240.0], [440.0, 275.0]],
            // Cilaos -> Sud Sauvage -> Fournaise flank
            [[-130.0, 145.0], [-70.0, 310.0], [40.0, 445.0], [175.0, 505.0], [285.0, 440.0], [340.0, 315.0]],
        ];
    }

    /**
     * World-scaled trail polylines connecting the 7 leveling zones across La Réunion.
     *
     * @return list<list<array{x: float, z: float}>>
     */
    public static function trails(): array
    {
        return array_map(
            static fn (array $line): array => array_map(
                static fn (array $pt): array => [
                    'x' => self::scaleCoord($pt[0]),
                    'z' => self::scaleCoord($pt[1]),
                ],
                $line,
            ),
            self::baseTrails(),
        );
    }

    /**
     * Computes the number of Creole houses in a Réunion village proportionally to its population.
     * Ranges monotonically from 3 houses (isolated cirque îlet ~800 hab.) up to 25 houses (Saint-Denis ~155,600 hab.).
     */
    public static function computeHouseCountForPopulation(int $population): int
    {
        return max(3, min(28, (int) round(1.8 + sqrt(max(0, $population) / 1000.0) * 1.85)));
    }

    /**
     * Computes the required number of Quest/Town NPCs for a village: at least 1 NPC per group of 5 houses.
     */
    public static function requiredNpcCountForHouses(int $houses): int
    {
        return max(1, (int) ceil(max(1, $houses) / 5.0));
    }

    /**
     * All 26 towns & villages of La Réunion (all 24 communes + Mafate La Nouvelle & Piton des Neiges Caverne Dufour),
     * linked to their geographic leveling zone and scaled in house count & NPC count by population.
     *
     * @return list<array{
     *   code: string,
     *   name: string,
     *   zoneCode: string,
     *   x: float,
     *   z: float,
     *   population: int,
     *   houses: int,
     *   npcCount: int,
     *   isZoneCamp: bool
     * }>
     */
    public static function villages(): array
    {
        $defs = [
            // Lagon de l'Ouest (Côte Ouest)
            ['code' => 'saint_paul',       'name' => 'Saint-Paul · Saint-Gilles',   'zoneCode' => 'lagon_ouest',  'x' => self::scaleCoord(-585.0), 'z' => self::scaleCoord(-95.0),  'population' => 106200, 'isZoneCamp' => true],
            ['code' => 'saint_leu',        'name' => 'Saint-Leu',                   'zoneCode' => 'lagon_ouest',  'x' => self::scaleCoord(-490.0), 'z' => self::scaleCoord(175.0),  'population' =>  35300, 'isZoneCamp' => false],
            ['code' => 'le_port',          'name' => 'Le Port',                     'zoneCode' => 'lagon_ouest',  'x' => self::scaleCoord(-485.0), 'z' => self::scaleCoord(-315.0), 'population' =>  33300, 'isZoneCamp' => false],
            ['code' => 'trois_bassins',    'name' => 'Trois-Bassins',               'zoneCode' => 'lagon_ouest',  'x' => self::scaleCoord(-515.0), 'z' => self::scaleCoord(55.0),   'population' =>   7000, 'isZoneCamp' => false],

            // Cirque de Salazie & Côte Nord-Est / Est
            ['code' => 'saint_andre',      'name' => 'Saint-André',                 'zoneCode' => 'salazie',      'x' => self::scaleCoord(290.0),  'z' => self::scaleCoord(-390.0), 'population' =>  57900, 'isZoneCamp' => false],
            ['code' => 'saint_benoit',     'name' => 'Saint-Benoît',                'zoneCode' => 'salazie',      'x' => self::scaleCoord(450.0),  'z' => self::scaleCoord(-150.0), 'population' =>  37900, 'isZoneCamp' => false],
            ['code' => 'sainte_marie',     'name' => 'Sainte-Marie',                'zoneCode' => 'salazie',      'x' => self::scaleCoord(25.0),   'z' => self::scaleCoord(-485.0), 'population' =>  35200, 'isZoneCamp' => false],
            ['code' => 'sainte_suzanne',   'name' => 'Sainte-Suzanne',              'zoneCode' => 'salazie',      'x' => self::scaleCoord(165.0),  'z' => self::scaleCoord(-450.0), 'population' =>  24800, 'isZoneCamp' => false],
            ['code' => 'bras_panon',       'name' => 'Bras-Panon',                  'zoneCode' => 'salazie',      'x' => self::scaleCoord(365.0),  'z' => self::scaleCoord(-275.0), 'population' =>  13200, 'isZoneCamp' => false],
            ['code' => 'salazie',          'name' => 'Salazie (Hell-Bourg)',        'zoneCode' => 'salazie',      'x' => self::scaleCoord(95.0),   'z' => self::scaleCoord(-290.0), 'population' =>   7300, 'isZoneCamp' => true],

            // Cirque de Mafate & Nord / Nord-Ouest
            ['code' => 'saint_denis',      'name' => 'Saint-Denis',                 'zoneCode' => 'mafate',       'x' => self::scaleCoord(-140.0), 'z' => self::scaleCoord(-490.0), 'population' => 155600, 'isZoneCamp' => false],
            ['code' => 'la_possession',    'name' => 'La Possession',               'zoneCode' => 'mafate',       'x' => self::scaleCoord(-385.0), 'z' => self::scaleCoord(-385.0), 'population' =>  36500, 'isZoneCamp' => false],
            ['code' => 'mafate',           'name' => 'Mafate (La Nouvelle)',        'zoneCode' => 'mafate',       'x' => self::scaleCoord(-250.0), 'z' => self::scaleCoord(-240.0), 'population' =>    800, 'isZoneCamp' => true],

            // Cirque de Cilaos & Hauts du Sud-Ouest
            ['code' => 'le_tampon',        'name' => 'Le Tampon',                   'zoneCode' => 'cilaos',       'x' => self::scaleCoord(-45.0),  'z' => self::scaleCoord(295.0),  'population' =>  81900, 'isZoneCamp' => false],
            ['code' => 'saint_louis',      'name' => 'Saint-Louis',                 'zoneCode' => 'cilaos',       'x' => self::scaleCoord(-290.0), 'z' => self::scaleCoord(355.0),  'population' =>  54400, 'isZoneCamp' => false],
            ['code' => 'etang_sale',       'name' => 'L\'Étang-Salé',               'zoneCode' => 'cilaos',       'x' => self::scaleCoord(-395.0), 'z' => self::scaleCoord(335.0),  'population' =>  14300, 'isZoneCamp' => false],
            ['code' => 'les_avirons',      'name' => 'Les Avirons',                 'zoneCode' => 'cilaos',       'x' => self::scaleCoord(-435.0), 'z' => self::scaleCoord(255.0),  'population' =>  11500, 'isZoneCamp' => false],
            ['code' => 'entre_deux',       'name' => 'Entre-Deux',                  'zoneCode' => 'cilaos',       'x' => self::scaleCoord(-145.0), 'z' => self::scaleCoord(285.0),  'population' =>   7100, 'isZoneCamp' => false],
            ['code' => 'cilaos',           'name' => 'Cilaos',                      'zoneCode' => 'cilaos',       'x' => self::scaleCoord(-130.0), 'z' => self::scaleCoord(145.0),  'population' =>   5500, 'isZoneCamp' => true],

            // Sud Sauvage & Grand Sud
            ['code' => 'saint_pierre',     'name' => 'Saint-Pierre',                'zoneCode' => 'sud_sauvage',  'x' => self::scaleCoord(-175.0), 'z' => self::scaleCoord(470.0),  'population' =>  85200, 'isZoneCamp' => false],
            ['code' => 'saint_joseph',     'name' => 'Saint-Joseph',                'zoneCode' => 'sud_sauvage',  'x' => self::scaleCoord(95.0),   'z' => self::scaleCoord(540.0),  'population' =>  39200, 'isZoneCamp' => false],
            ['code' => 'petite_ile',       'name' => 'Petite-Île',                  'zoneCode' => 'sud_sauvage',  'x' => self::scaleCoord(-35.0),  'z' => self::scaleCoord(515.0),  'population' =>  12900, 'isZoneCamp' => false],
            ['code' => 'saint_philippe',   'name' => 'Saint-Philippe',              'zoneCode' => 'sud_sauvage',  'x' => self::scaleCoord(165.0),  'z' => self::scaleCoord(480.0),  'population' =>   5100, 'isZoneCamp' => true],

            // Piton des Neiges
            ['code' => 'caverne_dufour',   'name' => 'Caverne Dufour (Piton)',      'zoneCode' => 'piton_neiges', 'x' => -364.0,                   'z' => 70.0,                    'population' =>   1200, 'isZoneCamp' => true],

            // Piton de la Fournaise & Plaine des Palmistes / Sainte-Rose
            ['code' => 'plaine_palmistes', 'name' => 'La Plaine-des-Palmistes',     'zoneCode' => 'fournaise',    'x' => self::scaleCoord(255.0),  'z' => self::scaleCoord(210.0),  'population' =>   6900, 'isZoneCamp' => true],
            ['code' => 'sainte_rose',      'name' => 'Sainte-Rose',                 'zoneCode' => 'fournaise',    'x' => self::scaleCoord(565.0),  'z' => self::scaleCoord(65.0),   'population' =>   6400, 'isZoneCamp' => false],
        ];

        return array_map(
            static function (array $v): array {
                $houses = self::computeHouseCountForPopulation($v['population']);

                return [
                    ...$v,
                    'houses' => $houses,
                    'npcCount' => self::requiredNpcCountForHouses($houses),
                ];
            },
            $defs,
        );
    }

    /**
     * Returns true when coordinates sit inside Saint-Gilles town hub, any Réunion village, or near any zone checkpoint/camp.
     */
    public static function isInSafeSanctuary(float $x, float $z): bool
    {
        foreach (self::zones() as $zone) {
            $radius = ($zone['isSafeHub'] ?? false) ? self::HUB_SAFE_RADIUS : self::CHECKPOINT_SAFE_RADIUS;
            if (hypot($x - $zone['respawnX'], $z - $zone['respawnZ']) <= $radius) {
                return true;
            }
        }

        foreach (self::villages() as $village) {
            $radius = $village['code'] === 'saint_paul' ? self::HUB_SAFE_RADIUS : self::CHECKPOINT_SAFE_RADIUS;
            if (hypot($x - $village['x'], $z - $village['z']) <= $radius) {
                return true;
            }
        }

        return false;
    }
}
