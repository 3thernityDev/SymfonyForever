<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Game\WorldCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates a high-fidelity topographic heightmap, biome/zone map, shaded minimap PNG,
 * and fast binary lookup cache based on the real geography of La Réunion.
 */
final class ReunionMapGenerator
{
    /** @var list<string> */
    public const array ZONE_INDEX = [
        'lagon_ouest',
        'salazie',
        'mafate',
        'cilaos',
        'sud_sauvage',
        'piton_neiges',
        'fournaise',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{heightmap: string, biomes: string, minimap: string, binary: string}
     */
    public function generate(): array
    {
        $res = WorldCatalog::MAP_RESOLUTION;
        $heights = new \SplFixedArray($res * $res);
        $biomes = new \SplFixedArray($res * $res);
        $zones = new \SplFixedArray($res * $res);
        $trails = new \SplFixedArray($res * $res);

        // Pass 1: Base island elevation, Piton des Neiges, 3 Cirques, Plaine des Sables, Fournaise
        for ($gz = 0; $gz < $res; ++$gz) {
            $v = $gz / ($res - 1);
            $wz = ($v - 0.5) * WorldCatalog::WORLD_SIZE;

            for ($gx = 0; $gx < $res; ++$gx) {
                $u = $gx / ($res - 1);
                $wx = ($u - 0.5) * WorldCatalog::WORLD_SIZE;
                $idx = $gz * $res + $gx;

                $h = $this->computeBaseElevation($u, $v, $wx, $wz);
                $heights[$idx] = $h;
                $trails[$idx] = 0;
            }
        }

        // Pass 2: Carve natural winding walkable trail corridors connecting all 7 zones across the island
        $baseTrails = WorldCatalog::baseTrails();

        $trailSegments = array_map(
            static fn (array $line): array => array_map(
                static fn (array $pt): array => [$pt[0] * WorldCatalog::WORLD_SCALE, $pt[1] * WorldCatalog::WORLD_SCALE],
                $line,
            ),
            $baseTrails,
        );

        $this->applyTrails($heights, $trails, $res, $trailSegments);

        // Pass 3: Mild smoothing pass to eliminate spiky artifacts while keeping dramatic remparts
        $heights = $this->smoothHeights($heights, $res, 2);

        // Pass 4: Assign biomes & zones with organic domain warping (no straight seams!)
        for ($gz = 0; $gz < $res; ++$gz) {
            $v = $gz / ($res - 1);
            $wz = ($v - 0.5) * WorldCatalog::WORLD_SIZE;

            for ($gx = 0; $gx < $res; ++$gx) {
                $u = $gx / ($res - 1);
                $wx = ($u - 0.5) * WorldCatalog::WORLD_SIZE;
                $idx = $gz * $res + $gx;
                $h = (float) $heights[$idx];

                $bx = $wx / WorldCatalog::WORLD_SCALE;
                $bz = $wz / WorldCatalog::WORLD_SCALE;
                $warpBx = $bx + $this->fbm($u * 9.0 + 3.1, $v * 9.0 + 1.7, 3) * 110.0;
                $warpBz = $bz + $this->fbm($u * 9.0 + 8.4, $v * 9.0 + 5.2, 3) * 110.0;
                $bh = $h <= WorldCatalog::SEA_LEVEL
                    ? $h
                    : WorldCatalog::SEA_LEVEL + ($h - WorldCatalog::SEA_LEVEL) / WorldCatalog::RELIEF_SCALE;

                $zoneIdx = $this->computeZoneIndex($warpBx, $warpBz, $bh);
                $zones[$idx] = $zoneIdx;
                $biomes[$idx] = $this->computeBiome($warpBx, $warpBz, $bh, $zoneIdx);
            }
        }

        // Write outputs
        $publicMapDir = $this->projectDir.'/public/assets/map';
        $varMapDir = $this->projectDir.'/var/map';
        if (!is_dir($publicMapDir)) {
            mkdir($publicMapDir, 0775, true);
        }
        if (!is_dir($varMapDir)) {
            mkdir($varMapDir, 0775, true);
        }

        $heightPath = $publicMapDir.'/reunion_heightmap.png';
        $biomePath = $publicMapDir.'/reunion_biomes.png';
        $minimapPath = $publicMapDir.'/reunion_minimap.png';
        $binaryPath = $varMapDir.'/reunion_terrain.bin';

        $this->writePngs($res, $heights, $biomes, $zones, $trails, $heightPath, $biomePath, $minimapPath);
        $this->writeBinaryCache($res, $heights, $biomes, $zones, $binaryPath);

        return [
            'heightmap' => $heightPath,
            'biomes' => $biomePath,
            'minimap' => $minimapPath,
            'binary' => $binaryPath,
        ];
    }

    private function computeBaseElevation(float $u, float $v, float $wx, float $wz): float
    {
        // Tilted oval matching La Réunion (NW-SE axis ~28 deg)
        $cx = 0.49;
        $cy = 0.51;
        $dx = $u - $cx;
        $dy = $v - $cy;
        $angle = deg2rad(-28.0);
        $rx = $dx * cos($angle) - $dy * sin($angle);
        $ry = $dx * sin($angle) + $dy * cos($angle);

        // Organic coastline wobble + real Réunion capes (Pointe des Galets NW, Sainte-Rose E, Saint-Pierre SW)
        $coastNoise = 0.045 * $this->fbm($u * 8.0, $v * 8.0, 4) + 0.015 * $this->fbm($u * 20.0, $v * 20.0, 2);
        $baySaintPaul = 0.036 * $this->gauss($u, $v, 0.21, 0.34, 0.07);
        $pointeGalets = -0.052 * $this->gauss($u, $v, 0.27, 0.23, 0.065);
        $seVolcanoBulge = -0.042 * $this->gauss($u, $v, 0.80, 0.67, 0.11);
        $swSaintPierre = -0.025 * $this->gauss($u, $v, 0.42, 0.78, 0.09);

        $ovalDist = sqrt(($rx / 0.415) ** 2 + ($ry / 0.312) ** 2)
            + $coastNoise + $baySaintPaul + $pointeGalets + $seVolcanoBulge + $swSaintPierre;

        // Underwater ocean floor
        if ($ovalDist >= 1.04) {
            return max(0.0, 2.5 - ($ovalDist - 1.04) * 10.0);
        }

        // Smooth coastal rise (gentle western lagoon vs steeper eastern/southern coast)
        $isWestLagoon = $this->gauss($u, $v, 0.19, 0.46, 0.14);
        $coastFactor = $this->smoothstep(1.04, 0.86, $ovalDist);
        $baseHeight = 2.0 + $coastFactor * (11.5 - 3.5 * $isWestLagoon);

        // Work in normalized base-island coordinates (-1024..+1024) so geography preserves 1:1 proportions
        $bx = $wx / WorldCatalog::WORLD_SCALE;
        $bz = $wz / WorldCatalog::WORLD_SCALE;

        // Flatten a comfortable coastal plain & Saint-Gilles hub shelf
        $hubShelf = $this->gauss($bx, $bz, -585.0, -95.0, 120.0);

        // Broad island dome rising inland
        $inland = $this->smoothstep(0.92, 0.18, $ovalDist);
        $baseHeight += $inland * 52.0 * (1.0 - 0.55 * $hubShelf);

        // === 1. Massif du Piton des Neiges (3070m) ===
        $pitonMain = 122.0 * $this->gauss($bx, $bz, -75.0, -65.0, 210.0);
        $pitonPeak = 44.0 * $this->gauss($bx, $bz, -75.0, -65.0, 75.0);
        // Radiating ridges (Grand Bénare W, Roche Écrite N, Bélouve E)
        $ridgeWest = 34.0 * $this->gauss($bx, $bz, -230.0, -70.0, 115.0);
        $ridgeNorth = 30.0 * $this->gauss($bx, $bz, -55.0, -290.0, 110.0);
        $ridgeBelouve = 28.0 * $this->gauss($bx, $bz, 90.0, -95.0, 125.0);

        $elevation = $baseHeight + $pitonMain + $pitonPeak + $ridgeWest + $ridgeNorth + $ridgeBelouve;

        // === 2. Les 3 Cirques (deeply carved amphitheaters around Piton des Neiges) ===
        // Cirque de Mafate (NW)
        $mafateCarve = 64.0 * $this->gauss($bx, $bz, -250.0, -240.0, 112.0);
        $mafateFloor = 12.0 * $this->fbm($u * 22.0, $v * 22.0, 3) * $this->gauss($bx, $bz, -250.0, -240.0, 100.0);
        // Cirque de Salazie (NE)
        $salazieCarve = 68.0 * $this->gauss($bx, $bz, 95.0, -290.0, 125.0);
        // Cirque de Cilaos (South)
        $cilaosCarve = 66.0 * $this->gauss($bx, $bz, -130.0, 145.0, 120.0);

        $elevation -= ($mafateCarve + $salazieCarve + $cilaosCarve);
        $elevation += $mafateFloor;

        // === 3. Plaine des Cafres & Plaine des Sables (high volcanic saddle) ===
        $plaineSaddle = 42.0 * $this->gauss($bx, $bz, 215.0, 190.0, 165.0);
        $elevation += $plaineSaddle;

        // === 4. Piton de la Fournaise (2632m) & Dolomieu Crater ===
        $fournaiseMassif = 102.0 * $this->gauss($bx, $bz, 435.0, 275.0, 185.0);
        $fournaiseCone = 40.0 * $this->gauss($bx, $bz, 440.0, 275.0, 65.0);
        // Sunken Dolomieu crater arena at summit
        $dolomieuCrater = 26.0 * $this->gauss($bx, $bz, 440.0, 275.0, 28.0);
        // Enclos Fouqué slope descending East to Grand Brûlé
        $enclosCarve = 18.0 * $this->gauss($bx, $bz, 560.0, 315.0, 95.0);

        $elevation += $fournaiseMassif + $fournaiseCone - $dolomieuCrater - $enclosCarve;

        // === 5. Sud Sauvage basalt terrace ===
        $sudShelf = 14.0 * $this->gauss($bx, $bz, 190.0, 520.0, 155.0);
        $elevation += $sudShelf;

        // === 6. Fine geological detail noise (scaled by inland height so beaches stay smooth) ===
        $detailMask = $this->smoothstep(10.0, 45.0, $elevation) * (1.0 - 0.7 * $hubShelf);
        $detail = (11.0 * $this->fbm($u * 14.0, $v * 14.0, 4) + 3.5 * $this->fbm($u * 32.0, $v * 32.0, 2)) * $detailMask;
        $elevation += $detail;

        // Ensure coastline never gets pulled below sea inside the island core, and ocean stays underwater
        if ($ovalDist < 0.80) {
            $elevation = max(WorldCatalog::SEA_LEVEL + 9.0, $elevation);
        } elseif ($ovalDist > 0.96) {
            $t = $this->smoothstep(0.96, 1.04, $ovalDist);
            $elevation = $elevation * (1.0 - $t) + 2.0 * $t;
        }

        // Scale vertical relief proportionally to the enlarged 3.5x island while keeping sea level & beaches grounded
        $elevation = max(0.0, min(185.0, $elevation));
        if ($elevation > WorldCatalog::SEA_LEVEL) {
            $excess = $elevation - WorldCatalog::SEA_LEVEL;
            $elevation = WorldCatalog::SEA_LEVEL + $excess * WorldCatalog::RELIEF_SCALE;
        }

        // Keep Saint-Gilles starter hub nicely flat and scenic (~12m above sea)
        if ($hubShelf > 0.2) {
            $blend = $this->smoothstep(0.2, 0.85, $hubShelf);
            $elevation = $elevation * (1.0 - $blend * 0.78) + 12.0 * ($blend * 0.78);
        }

        return max(0.0, min(WorldCatalog::MAX_ELEVATION, $elevation));
    }

    /**
     * @param \SplFixedArray<float> $heights
     * @param \SplFixedArray<int>   $trails
     * @param list<list<array{float, float}>> $polylines
     */
    private function applyTrails(\SplFixedArray $heights, \SplFixedArray $trails, int $res, array $polylines): void
    {
        $halfWidth = 42.0;
        $samplePoints = [];

        // Sample polylines densely and record target smoothed heights along each trail
        foreach ($polylines as $line) {
            $pts = [];
            for ($i = 0; $i < count($line) - 1; ++$i) {
                [$x0, $z0] = $line[$i];
                [$x1, $z1] = $line[$i + 1];
                $dist = hypot($x1 - $x0, $z1 - $z0);
                $steps = max(4, (int) ceil($dist / 20.0));
                for ($s = 0; $s <= $steps; ++$s) {
                    $t = $s / $steps;
                    $wx = $x0 + ($x1 - $x0) * $t;
                    $wz = $z0 + ($z1 - $z0) * $t;
                    $h = $this->sampleGrid($heights, $res, $wx, $wz);
                    $pts[] = ['x' => $wx, 'z' => $wz, 'h' => max(WorldCatalog::SEA_LEVEL + 3.5, $h)];
                }
            }

            // Smooth the trail elevation profile longitudinally so grades are always comfortably walkable!
            for ($pass = 0; $pass < 10; ++$pass) {
                $copy = $pts;
                for ($k = 1; $k < count($pts) - 1; ++$k) {
                    $copy[$k]['h'] = ($pts[$k - 1]['h'] + $pts[$k]['h'] * 2.0 + $pts[$k + 1]['h']) * 0.25;
                }
                $pts = $copy;
            }

            foreach ($pts as $pt) {
                $samplePoints[] = $pt;
            }
        }

        // Apply trail smoothing onto height grid
        foreach ($samplePoints as $pt) {
            $cx = (int) round((($pt['x'] / WorldCatalog::WORLD_SIZE) + 0.5) * ($res - 1));
            $cz = (int) round((($pt['z'] / WorldCatalog::WORLD_SIZE) + 0.5) * ($res - 1));
            $radCells = (int) ceil($halfWidth / (WorldCatalog::WORLD_SIZE / $res));

            for ($dz = -$radCells; $dz <= $radCells; ++$dz) {
                $gz = $cz + $dz;
                if ($gz < 0 || $gz >= $res) {
                    continue;
                }
                $wz = ($gz / ($res - 1) - 0.5) * WorldCatalog::WORLD_SIZE;
                for ($dx = -$radCells; $dx <= $radCells; ++$dx) {
                    $gx = $cx + $dx;
                    if ($gx < 0 || $gx >= $res) {
                        continue;
                    }
                    $wx = ($gx / ($res - 1) - 0.5) * WorldCatalog::WORLD_SIZE;
                    $d = hypot($wx - $pt['x'], $wz - $pt['z']);
                    if ($d >= $halfWidth) {
                        continue;
                    }
                    $blend = $this->smoothstep($halfWidth, 0.0, $d);
                    $idx = $gz * $res + $gx;
                    $cur = (float) $heights[$idx];
                    $heights[$idx] = $cur * (1.0 - $blend * 0.65) + $pt['h'] * ($blend * 0.65);
                    if ($d < 18.0) {
                        $trails[$idx] = max((int) $trails[$idx], (int) round($blend * 255));
                    }
                }
            }
        }
    }

    /**
     * @param \SplFixedArray<float> $heights
     * @return \SplFixedArray<float>
     */
    private function smoothHeights(\SplFixedArray $heights, int $res, int $passes): \SplFixedArray
    {
        $src = $heights;
        for ($p = 0; $p < $passes; ++$p) {
            $dst = new \SplFixedArray($res * $res);
            for ($z = 0; $z < $res; ++$z) {
                for ($x = 0; $x < $res; ++$x) {
                    $idx = $z * $res + $x;
                    if ($x === 0 || $z === 0 || $x === $res - 1 || $z === $res - 1) {
                        $dst[$idx] = $src[$idx];
                        continue;
                    }
                    $sum = (float) $src[$idx] * 4.0
                        + (float) $src[$idx - 1]
                        + (float) $src[$idx + 1]
                        + (float) $src[$idx - $res]
                        + (float) $src[$idx + $res];
                    $dst[$idx] = $sum * 0.125;
                }
            }
            $src = $dst;
        }

        return $src;
    }

    private function computeZoneIndex(float $wx, float $wz, float $h): int
    {
        // High summit Piton des Neiges takes priority near central peak
        if (hypot($wx - (-75.0), $wz - (-65.0)) < 155.0 && $h > 102.0) {
            return 5; // piton_neiges
        }

        // Nearest organic zone center (radial distances only -> zero rectangular seams!)
        $centers = [
            0 => [-560.0, -70.0, 1.00],  // lagon_ouest
            1 => [125.0, -255.0, 0.95],  // salazie
            2 => [-260.0, -245.0, 1.05], // mafate
            3 => [-135.0, 155.0, 1.00],  // cilaos
            4 => [175.0, 505.0, 0.92],   // sud_sauvage
            6 => [400.0, 260.0, 0.85],   // fournaise + Plaine des Sables
        ];

        $best = 0;
        $bestDist = INF;
        foreach ($centers as $idx => [$cx, $cz, $weight]) {
            $d = hypot($wx - $cx, $wz - $cz) * $weight;
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $idx;
            }
        }

        return $best;
    }

    private function computeBiome(float $warpBx, float $warpBz, float $bh, int $zoneIdx): int
    {
        if ($bh < WorldCatalog::SEA_LEVEL - 0.8) {
            return WorldCatalog::BIOME_OCEAN;
        }

        // Fournaise volcano slopes & crater (organic radial distance in base coordinates)
        $distCrater = hypot($warpBx - 440.0, $warpBz - 275.0);
        if ($distCrater < 135.0) {
            return WorldCatalog::BIOME_VOLCANO_LAVA;
        }
        // Plaine des Sables ochre lunar plateau
        if (hypot($warpBx - 275.0, $warpBz - 220.0) < 125.0 && $bh > 48.0) {
            return WorldCatalog::BIOME_PLAINE_SABLES;
        }
        if ($zoneIdx === 6 && $bh > 65.0) {
            return WorldCatalog::BIOME_VOLCANO_LAVA;
        }
        if ($zoneIdx === 6 && $bh > 35.0) {
            return WorldCatalog::BIOME_PLAINE_SABLES;
        }

        // Sud Sauvage dark basalt flows & rocky coastline
        if ($zoneIdx === 4 && $bh < 76.0) {
            return WorldCatalog::BIOME_SUD_SAUVAGE;
        }

        // High altitude Piton des Neiges alpine rock
        if ($bh > 116.0) {
            return WorldCatalog::BIOME_HIGHLANDS;
        }

        // Western Lagoon golden beaches (smooth organic transition along West coast)
        $westShoreWeight = $this->gauss($warpBx, $warpBz, -600.0, -20.0, 320.0);
        if ($bh < (8.5 + 5.5 * $westShoreWeight) && $warpBx < -180.0) {
            return WorldCatalog::BIOME_LAGOON_BEACH;
        }

        if ($zoneIdx === 0) {
            return WorldCatalog::BIOME_WEST_COAST;
        }
        if ($zoneIdx === 1) {
            return WorldCatalog::BIOME_TROPICAL_FOREST;
        }

        return WorldCatalog::BIOME_CIRQUE_REMPART;
    }

    /**
     * @param \SplFixedArray<float> $heights
     * @param \SplFixedArray<int>   $biomes
     * @param \SplFixedArray<int>   $zones
     * @param \SplFixedArray<int>   $trails
     */
    private function writePngs(
        int $res,
        \SplFixedArray $heights,
        \SplFixedArray $biomes,
        \SplFixedArray $zones,
        \SplFixedArray $trails,
        string $heightPath,
        string $biomePath,
        string $minimapPath,
    ): void {
        $imgH = imagecreatetruecolor($res, $res);
        $imgB = imagecreatetruecolor($res, $res);
        $imgM = imagecreatetruecolor($res, $res);

        $biomePalette = [
            WorldCatalog::BIOME_OCEAN => [14, 72, 128],
            WorldCatalog::BIOME_LAGOON_BEACH => [235, 212, 152],
            WorldCatalog::BIOME_WEST_COAST => [124, 173, 86],
            WorldCatalog::BIOME_TROPICAL_FOREST => [43, 128, 72],
            WorldCatalog::BIOME_CIRQUE_REMPART => [78, 120, 78],
            WorldCatalog::BIOME_HIGHLANDS => [146, 154, 166],
            WorldCatalog::BIOME_PLAINE_SABLES => [194, 118, 66],
            WorldCatalog::BIOME_VOLCANO_LAVA => [110, 44, 34],
            WorldCatalog::BIOME_SUD_SAUVAGE => [52, 62, 68],
        ];

        for ($z = 0; $z < $res; ++$z) {
            for ($x = 0; $x < $res; ++$x) {
                $idx = $z * $res + $x;
                $h = (float) $heights[$idx];
                $norm = max(0.0, min(1.0, $h / WorldCatalog::MAX_ELEVATION));
                $h16 = (int) round($norm * 65535.0);
                $hi = ($h16 >> 8) & 0xFF;
                $lo = $h16 & 0xFF;

                // RG16 heightmap (R=high byte, G=low byte, B=preview grayscale)
                imagesetpixel($imgH, $x, $z, ($hi << 16) | ($lo << 8) | $hi);

                // Biome/Zone/Trail map
                $b = (int) $biomes[$idx];
                $zn = (int) $zones[$idx];
                $tr = (int) $trails[$idx];
                imagesetpixel($imgB, $x, $z, ($b << 16) | ($zn << 8) | $tr);

                // Top-down shaded relief minimap
                [$r, $g, $bl] = $biomePalette[$b] ?? [80, 120, 80];
                if ($b === WorldCatalog::BIOME_OCEAN) {
                    // Turquoise shallow lagoon halo around West coast
                    $shallow = $this->smoothstep(1.5, WorldCatalog::SEA_LEVEL - 0.5, $h);
                    $r = (int) round($r + $shallow * 24);
                    $g = (int) round($g + $shallow * 112);
                    $bl = (int) round($bl + $shallow * 88);
                } else {
                    // Directional hillshading from NW sun
                    $hL = $x > 0 ? (float) $heights[$idx - 1] : $h;
                    $hR = $x < $res - 1 ? (float) $heights[$idx + 1] : $h;
                    $hU = $z > 0 ? (float) $heights[$idx - $res] : $h;
                    $hD = $z < $res - 1 ? (float) $heights[$idx + $res] : $h;
                    $shade = 1.0 + max(-0.45, min(0.45, (($hL - $hR) + ($hU - $hD)) * 0.07));

                    // Crater glow accent (in base island coordinates)
                    $bx = (($x / ($res - 1) - 0.5) * WorldCatalog::WORLD_SIZE) / WorldCatalog::WORLD_SCALE;
                    $bz = (($z / ($res - 1) - 0.5) * WorldCatalog::WORLD_SIZE) / WorldCatalog::WORLD_SCALE;
                    if (hypot($bx - 440.0, $bz - 275.0) < 26.0) {
                        $r = 255;
                        $g = 96;
                        $bl = 28;
                        $shade = 1.0;
                    } elseif ($tr > 90) {
                        // Subtle footpath tint on minimap
                        $r = (int) round($r * 0.78 + 205 * 0.22);
                        $g = (int) round($g * 0.78 + 186 * 0.22);
                        $bl = (int) round($bl * 0.78 + 145 * 0.22);
                    }

                    $r = max(0, min(255, (int) round($r * $shade)));
                    $g = max(0, min(255, (int) round($g * $shade)));
                    $bl = max(0, min(255, (int) round($bl * $shade)));
                }
                imagesetpixel($imgM, $x, $z, ($r << 16) | ($g << 8) | $bl);
            }
        }

        imagepng($imgH, $heightPath, 6);
        imagepng($imgB, $biomePath, 6);
        imagepng($imgM, $minimapPath, 6);
        imagedestroy($imgH);
        imagedestroy($imgB);
        imagedestroy($imgM);
    }

    /**
     * Binary format: uint32 resolution, then res*res float32 heights, res*res uint8 biomes, res*res uint8 zones.
     *
     * @param \SplFixedArray<float> $heights
     * @param \SplFixedArray<int>   $biomes
     * @param \SplFixedArray<int>   $zones
     */
    private function writeBinaryCache(
        int $res,
        \SplFixedArray $heights,
        \SplFixedArray $biomes,
        \SplFixedArray $zones,
        string $binaryPath,
    ): void {
        $count = $res * $res;
        $fp = fopen($binaryPath, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Impossible d\'écrire le cache terrain binaire : '.$binaryPath);
        }

        fwrite($fp, pack('V', $res));

        // Write float32 heights in chunks for low memory use
        $chunkSize = 4096;
        for ($i = 0; $i < $count; $i += $chunkSize) {
            $end = min($count, $i + $chunkSize);
            $buf = '';
            for ($j = $i; $j < $end; ++$j) {
                $buf .= pack('g', (float) $heights[$j]);
            }
            fwrite($fp, $buf);
        }

        $biomeStr = '';
        $zoneStr = '';
        for ($i = 0; $i < $count; ++$i) {
            $biomeStr .= chr((int) $biomes[$i]);
            $zoneStr .= chr((int) $zones[$i]);
        }
        fwrite($fp, $biomeStr);
        fwrite($fp, $zoneStr);
        fclose($fp);
    }

    /**
     * @param \SplFixedArray<float> $heights
     */
    private function sampleGrid(\SplFixedArray $heights, int $res, float $wx, float $wz): float
    {
        $u = max(0.0, min(1.0, ($wx / WorldCatalog::WORLD_SIZE) + 0.5));
        $v = max(0.0, min(1.0, ($wz / WorldCatalog::WORLD_SIZE) + 0.5));
        $gx = (int) round($u * ($res - 1));
        $gz = (int) round($v * ($res - 1));

        return (float) $heights[$gz * $res + $gx];
    }

    private function gauss(float $x, float $y, float $cx, float $cy, float $sigma): float
    {
        $dx = $x - $cx;
        $dy = $y - $cy;

        return exp(-($dx * $dx + $dy * $dy) / (2.0 * $sigma * $sigma));
    }

    private function smoothstep(float $edge0, float $edge1, float $x): float
    {
        if ($edge0 === $edge1) {
            return 0.0;
        }
        $t = max(0.0, min(1.0, ($x - $edge0) / ($edge1 - $edge0)));

        return $t * $t * (3.0 - 2.0 * $t);
    }

    private function fbm(float $x, float $y, int $octaves): float
    {
        $value = 0.0;
        $amp = 0.5;
        $freq = 1.0;

        for ($i = 0; $i < $octaves; ++$i) {
            $value += $amp * $this->valueNoise($x * $freq, $y * $freq);
            $freq *= 2.03;
            $amp *= 0.5;
        }

        return $value;
    }

    private function valueNoise(float $x, float $y): float
    {
        $xi = (int) floor($x);
        $yi = (int) floor($y);
        $xf = $x - $xi;
        $yf = $y - $yi;

        $u = $xf * $xf * (3.0 - 2.0 * $xf);
        $v = $yf * $yf * (3.0 - 2.0 * $yf);

        $n00 = $this->hash2d($xi, $yi);
        $n10 = $this->hash2d($xi + 1, $yi);
        $n01 = $this->hash2d($xi, $yi + 1);
        $n11 = $this->hash2d($xi + 1, $yi + 1);

        $nx0 = $n00 + ($n10 - $n00) * $u;
        $nx1 = $n01 + ($n11 - $n01) * $u;

        return ($nx0 + ($nx1 - $nx0) * $v) * 2.0 - 1.0;
    }

    private function hash2d(int $x, int $y): float
    {
        $s = sin($x * 127.1 + $y * 311.7) * 43758.5453123;

        return $s - floor($s);
    }
}
