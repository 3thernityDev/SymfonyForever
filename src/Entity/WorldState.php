<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorldStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorldStateRepository::class)]
#[ORM\Table(name: 'world_state')]
class WorldState
{
    public const string GLOBAL_ID = 'global';

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id = self::GLOBAL_ID;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $weatherOverride = null;

    #[ORM\Column]
    private float $timeOffsetHours = 0.0;

    #[ORM\Column(nullable: true)]
    private ?float $fixedTimeOfDay = null;

    #[ORM\Column]
    private float $updatedAt = 0.0;

    public function __construct(string $id = self::GLOBAL_ID)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getWeatherOverride(): ?string
    {
        return $this->weatherOverride;
    }

    public function setWeatherOverride(?string $weatherOverride): self
    {
        $this->weatherOverride = $weatherOverride;

        return $this;
    }

    public function getTimeOffsetHours(): float
    {
        return $this->timeOffsetHours;
    }

    public function setTimeOffsetHours(float $timeOffsetHours): self
    {
        $this->timeOffsetHours = $timeOffsetHours;

        return $this;
    }

    public function getFixedTimeOfDay(): ?float
    {
        return $this->fixedTimeOfDay;
    }

    public function setFixedTimeOfDay(?float $fixedTimeOfDay): self
    {
        $this->fixedTimeOfDay = $fixedTimeOfDay;

        return $this;
    }

    public function getUpdatedAt(): float
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(float $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function resetToAuto(float $now): self
    {
        $this->weatherOverride = null;
        $this->timeOffsetHours = 0.0;
        $this->fixedTimeOfDay = null;
        $this->updatedAt = $now;

        return $this;
    }
}
