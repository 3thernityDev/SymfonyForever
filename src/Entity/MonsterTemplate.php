<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonsterTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonsterTemplateRepository::class)]
#[ORM\Table(name: 'monster_template')]
class MonsterTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 48, unique: true)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column]
    private int $level = 1;

    #[ORM\Column]
    private int $maxHp = 80;

    #[ORM\Column]
    private int $attack = 12;

    #[ORM\Column]
    private int $defense = 6;

    #[ORM\Column]
    private int $xpReward = 35;

    #[ORM\Column]
    private float $aggroRadius = 11.0;

    #[ORM\Column]
    private float $attackRange = 5.5;

    #[ORM\Column]
    private float $moveSpeed = 9.0;

    #[ORM\Column]
    private float $attackInterval = 2.0;

    #[ORM\Column]
    private int $respawnSeconds = 22;

    #[ORM\Column]
    private bool $aggressive = false;

    #[ORM\Column]
    private bool $isBoss = false;

    #[ORM\Column(length: 32)]
    private string $meshKey = 'crab';

    #[ORM\Column(length: 16)]
    private string $primaryColor = '#d9534f';

    #[ORM\Column]
    private float $scale = 1.0;

    public function __construct(string $code, string $name)
    {
        $this->code = $code;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;

        return $this;
    }

    public function getMaxHp(): int
    {
        return $this->maxHp;
    }

    public function setMaxHp(int $maxHp): self
    {
        $this->maxHp = $maxHp;

        return $this;
    }

    public function getAttack(): int
    {
        return $this->attack;
    }

    public function setAttack(int $attack): self
    {
        $this->attack = $attack;

        return $this;
    }

    public function getDefense(): int
    {
        return $this->defense;
    }

    public function setDefense(int $defense): self
    {
        $this->defense = $defense;

        return $this;
    }

    public function getXpReward(): int
    {
        return $this->xpReward;
    }

    public function setXpReward(int $xpReward): self
    {
        $this->xpReward = $xpReward;

        return $this;
    }

    public function getAggroRadius(): float
    {
        return $this->aggroRadius;
    }

    public function setAggroRadius(float $aggroRadius): self
    {
        $this->aggroRadius = $aggroRadius;

        return $this;
    }

    public function getAttackRange(): float
    {
        return $this->attackRange;
    }

    public function setAttackRange(float $attackRange): self
    {
        $this->attackRange = $attackRange;

        return $this;
    }

    public function getMoveSpeed(): float
    {
        return $this->moveSpeed;
    }

    public function setMoveSpeed(float $moveSpeed): self
    {
        $this->moveSpeed = $moveSpeed;

        return $this;
    }

    public function getAttackInterval(): float
    {
        return $this->attackInterval;
    }

    public function setAttackInterval(float $attackInterval): self
    {
        $this->attackInterval = $attackInterval;

        return $this;
    }

    public function getRespawnSeconds(): int
    {
        return $this->respawnSeconds;
    }

    public function setRespawnSeconds(int $respawnSeconds): self
    {
        $this->respawnSeconds = $respawnSeconds;

        return $this;
    }

    public function isAggressive(): bool
    {
        return $this->aggressive;
    }

    public function setAggressive(bool $aggressive): self
    {
        $this->aggressive = $aggressive;

        return $this;
    }

    public function isBoss(): bool
    {
        return $this->isBoss;
    }

    public function setIsBoss(bool $isBoss): self
    {
        $this->isBoss = $isBoss;

        return $this;
    }

    public function getMeshKey(): string
    {
        return $this->meshKey;
    }

    public function setMeshKey(string $meshKey): self
    {
        $this->meshKey = $meshKey;

        return $this;
    }

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(string $primaryColor): self
    {
        $this->primaryColor = $primaryColor;

        return $this;
    }

    public function getScale(): float
    {
        return $this->scale;
    }

    public function setScale(float $scale): self
    {
        $this->scale = $scale;

        return $this;
    }
}
