<?php

declare(strict_types=1);

namespace App\Game;

final readonly class NpcDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public string $title,
        public string $greeting,
        public string $zoneCode,
        public float $x,
        public float $z,
        public float $rotY,
        public string $coatColor,
        public string $accentColor,
        public string $villageCode = 'saint_paul',
    ) {
    }
}
