<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Game\WorldCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authoritative server-side Réunion terrain sampler (reads the exact same height/biome grid
 * rendered by Three.js).
 */
final class ReunionTerrainService
{
    private bool $loaded = false;
    private int $res = WorldCatalog::MAP_RESOLUTION;
    private string $heightBytes = '';
    private string $biomeBytes = '';
    private string $zoneBytes = '';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ReunionMapGenerator $generator,
    ) {
    }

    public function getHeight(float $wx, float $wz): float
    {
        $this->ensureLoaded();

        $u = ($wx / WorldCatalog::WORLD_SIZE) + 0.5;
        $v = ($wz / WorldCatalog::WORLD_SIZE) + 0.5;
        if ($u < 0.0 || $u > 1.0 || $v < 0.0 || $v > 1.0) {
            return 0.0;
        }

        $fx = $u * ($this->res - 1);
        $fz = $v * ($this->res - 1);
        $x0 = (int) floor($fx);
        $z0 = (int) floor($fz);
        $x1 = min($this->res - 1, $x0 + 1);
        $z1 = min($this->res - 1, $z0 + 1);
        $tx = $fx - $x0;
        $tz = $fz - $z0;

        $h00 = $this->rawHeight($x0, $z0);
        $h10 = $this->rawHeight($x1, $z0);
        $h01 = $this->rawHeight($x0, $z1);
        $h11 = $this->rawHeight($x1, $z1);

        $hx0 = $h00 + ($h10 - $h00) * $tx;
        $hx1 = $h01 + ($h11 - $h01) * $tx;

        return $hx0 + ($hx1 - $hx0) * $tz;
    }

    public function getSlope(float $wx, float $wz): float
    {
        $eps = 6.0;
        $hL = $this->getHeight($wx - $eps, $wz);
        $hR = $this->getHeight($wx + $eps, $wz);
        $hD = $this->getHeight($wx, $wz - $eps);
        $hU = $this->getHeight($wx, $wz + $eps);

        $dx = ($hR - $hL) / (2.0 * $eps);
        $dz = ($hU - $hD) / (2.0 * $eps);

        return hypot($dx, $dz);
    }

    public function getBiome(float $wx, float $wz): int
    {
        $this->ensureLoaded();
        [$gx, $gz] = $this->worldToGrid($wx, $wz);

        return ord($this->biomeBytes[$gz * $this->res + $gx]);
    }

    public function getZoneCode(float $wx, float $wz): string
    {
        $this->ensureLoaded();
        [$gx, $gz] = $this->worldToGrid($wx, $wz);
        $zoneIdx = ord($this->zoneBytes[$gz * $this->res + $gx]);

        return ReunionMapGenerator::ZONE_INDEX[$zoneIdx] ?? 'lagon_ouest';
    }

    public function isWalkable(float $wx, float $wz): bool
    {
        if (abs($wx) > WorldCatalog::HALF_WORLD - 40.0 || abs($wz) > WorldCatalog::HALF_WORLD - 40.0) {
            return false;
        }

        $h = $this->getHeight($wx, $wz);
        if ($h < WorldCatalog::WALKABLE_MIN_Y) {
            return false;
        }

        return $this->getSlope($wx, $wz) <= WorldCatalog::MAX_WALKABLE_SLOPE;
    }

    /**
     * Finds the nearest walkable spot around a target coordinate (useful for seeding spawns).
     *
     * @return array{x: float, y: float, z: float}
     */
    public function findNearestWalkable(float $wx, float $wz, float $maxRadius = 180.0): array
    {
        if ($this->isWalkable($wx, $wz)) {
            return ['x' => $wx, 'y' => $this->getHeight($wx, $wz), 'z' => $wz];
        }

        for ($r = 10.0; $r <= $maxRadius; $r += 10.0) {
            for ($a = 0; $a < 16; ++$a) {
                $rad = ($a / 16.0) * M_PI * 2.0;
                $cx = $wx + cos($rad) * $r;
                $cz = $wz + sin($rad) * $r;
                if ($this->isWalkable($cx, $cz)) {
                    return ['x' => $cx, 'y' => $this->getHeight($cx, $cz), 'z' => $cz];
                }
            }
        }

        // Fallback to Saint-Gilles safe hub
        $hub = WorldCatalog::zones()['lagon_ouest'];

        return [
            'x' => $hub['respawnX'],
            'y' => $this->getHeight($hub['respawnX'], $hub['respawnZ']),
            'z' => $hub['respawnZ'],
        ];
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $binaryPath = $this->projectDir.'/var/map/reunion_terrain.bin';
        if (!is_file($binaryPath)) {
            $this->generator->generate();
        }

        $raw = file_get_contents($binaryPath);
        if ($raw === false || strlen($raw) < 4) {
            $this->generator->generate();
            $raw = file_get_contents($binaryPath);
        }

        $header = is_string($raw) && strlen($raw) >= 4 ? unpack('Vres', substr($raw, 0, 4)) : false;
        $res = (int) ($header['res'] ?? 0);
        $expectedLen = 4 + $res * $res * 6;
        if ($res !== WorldCatalog::MAP_RESOLUTION || !is_string($raw) || strlen($raw) !== $expectedLen) {
            $this->generator->generate();
            $raw = file_get_contents($binaryPath);
            if ($raw === false || strlen($raw) < 4) {
                throw new \RuntimeException('Impossible de lire le cache terrain binaire.');
            }
            $res = WorldCatalog::MAP_RESOLUTION;
        }

        $this->res = $res;
        $count = $this->res * $this->res;
        $heightLen = $count * 4;

        $this->heightBytes = substr($raw, 4, $heightLen);
        $this->biomeBytes = substr($raw, 4 + $heightLen, $count);
        $this->zoneBytes = substr($raw, 4 + $heightLen + $count, $count);
        $this->loaded = true;
    }

    private function rawHeight(int $gx, int $gz): float
    {
        $offset = ($gz * $this->res + $gx) * 4;
        $unpacked = unpack('gh', substr($this->heightBytes, $offset, 4));

        return (float) ($unpacked['h'] ?? 0.0);
    }

    /**
     * @return array{int, int}
     */
    private function worldToGrid(float $wx, float $wz): array
    {
        $u = max(0.0, min(1.0, ($wx / WorldCatalog::WORLD_SIZE) + 0.5));
        $v = max(0.0, min(1.0, ($wz / WorldCatalog::WORLD_SIZE) + 0.5));

        return [
            (int) round($u * ($this->res - 1)),
            (int) round($v * ($this->res - 1)),
        ];
    }
}
