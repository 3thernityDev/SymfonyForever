<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ZoneRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ZoneRepository::class)]
#[ORM\Table(name: 'game_zone')]
class Zone
{
    #[ORM\Id]
    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 120)]
    private string $subtitle = '';

    #[ORM\Column]
    private int $minLevel = 1;

    #[ORM\Column]
    private int $maxLevel = 3;

    #[ORM\Column]
    private float $respawnX = -2047.5;

    #[ORM\Column]
    private float $respawnZ = -332.5;

    #[ORM\Column]
    private float $lastTickAt = 0.0;

    public function __construct(string $code, string $name)
    {
        $this->code = $code;
        $this->name = $name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSubtitle(): string
    {
        return $this->subtitle;
    }

    public function setSubtitle(string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function getMinLevel(): int
    {
        return $this->minLevel;
    }

    public function setMinLevel(int $minLevel): self
    {
        $this->minLevel = $minLevel;

        return $this;
    }

    public function getMaxLevel(): int
    {
        return $this->maxLevel;
    }

    public function setMaxLevel(int $maxLevel): self
    {
        $this->maxLevel = $maxLevel;

        return $this;
    }

    public function getRespawnX(): float
    {
        return $this->respawnX;
    }

    public function setRespawnX(float $respawnX): self
    {
        $this->respawnX = $respawnX;

        return $this;
    }

    public function getRespawnZ(): float
    {
        return $this->respawnZ;
    }

    public function setRespawnZ(float $respawnZ): self
    {
        $this->respawnZ = $respawnZ;

        return $this;
    }

    public function getLastTickAt(): float
    {
        return $this->lastTickAt;
    }

    public function setLastTickAt(float $lastTickAt): self
    {
        $this->lastTickAt = $lastTickAt;

        return $this;
    }
}
