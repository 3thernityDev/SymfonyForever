<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonsterInstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonsterInstanceRepository::class)]
#[ORM\Table(name: 'monster_instance')]
#[ORM\Index(name: 'idx_monster_zone_state', columns: ['zone_code', 'state'])]
#[ORM\Index(name: 'idx_monster_pos', columns: ['pos_x', 'pos_z'])]
class MonsterInstance
{
    public const string STATE_IDLE = 'IDLE';
    public const string STATE_COMBAT = 'COMBAT';
    public const string STATE_RETURNING = 'RETURNING';
    public const string STATE_DEAD = 'DEAD';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MonsterTemplate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MonsterTemplate $template;

    #[ORM\Column(length: 40)]
    private string $zoneCode;

    #[ORM\Column]
    private float $spawnX;

    #[ORM\Column]
    private float $spawnY;

    #[ORM\Column]
    private float $spawnZ;

    #[ORM\Column]
    private float $posX;

    #[ORM\Column]
    private float $posY;

    #[ORM\Column]
    private float $posZ;

    #[ORM\Column]
    private float $rotY = 0.0;

    #[ORM\Column]
    private int $currentHp;

    #[ORM\Column(length: 16)]
    private string $state = self::STATE_IDLE;

    /** @var array<string, float> characterId (as string key) => threat score */
    #[ORM\Column(type: 'json')]
    private array $threatTable = [];

    /**
     * Persistent raid participation tracker for World Bosses (kept even if a participant dies mid-fight;
     * cleared only when the boss resets to IDLE or dies & drops personal Legendary chests).
     *
     * @var array<string, float> characterId (as string key) => cumulative contribution
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $raidContributors = [];

    #[ORM\Column(nullable: true)]
    private ?int $tauntedByCharacterId = null;

    #[ORM\Column]
    private float $tauntExpiresAt = 0.0;

    /**
     * @var list<array{
     *   type: string,
     *   skillCode: string,
     *   sourceId: int,
     *   value: float,
     *   expiresAt: float,
     *   nextTickAt: float,
     *   tickInterval: float
     * }>
     */
    #[ORM\Column(type: 'json')]
    private array $activeEffects = [];

    #[ORM\Column]
    private float $lastAttackAt = 0.0;

    #[ORM\Column(options: ['default' => 0.0])]
    private float $lastSpecialAt = 0.0;

    #[ORM\Column(options: ['default' => 1])]
    private int $phase = 1;

    #[ORM\Column]
    private float $respawnAt = 0.0;

    public function __construct(MonsterTemplate $template, string $zoneCode, float $x, float $y, float $z)
    {
        $this->template = $template;
        $this->zoneCode = $zoneCode;
        $this->spawnX = $x;
        $this->spawnY = $y;
        $this->spawnZ = $z;
        $this->posX = $x;
        $this->posY = $y;
        $this->posZ = $z;
        $this->currentHp = $template->getMaxHp();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): MonsterTemplate
    {
        return $this->template;
    }

    public function getZoneCode(): string
    {
        return $this->zoneCode;
    }

    public function getSpawnX(): float
    {
        return $this->spawnX;
    }

    public function getSpawnY(): float
    {
        return $this->spawnY;
    }

    public function getSpawnZ(): float
    {
        return $this->spawnZ;
    }

    public function getPosX(): float
    {
        return $this->posX;
    }

    public function setPosX(float $posX): self
    {
        $this->posX = $posX;

        return $this;
    }

    public function getPosY(): float
    {
        return $this->posY;
    }

    public function setPosY(float $posY): self
    {
        $this->posY = $posY;

        return $this;
    }

    public function getPosZ(): float
    {
        return $this->posZ;
    }

    public function setPosZ(float $posZ): self
    {
        $this->posZ = $posZ;

        return $this;
    }

    public function getRotY(): float
    {
        return $this->rotY;
    }

    public function setRotY(float $rotY): self
    {
        $this->rotY = $rotY;

        return $this;
    }

    public function getCurrentHp(): int
    {
        return $this->currentHp;
    }

    public function setCurrentHp(int $currentHp): self
    {
        $this->currentHp = max(0, min($this->template->getMaxHp(), $currentHp));

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return array<string, float>
     */
    public function getThreatTable(): array
    {
        return $this->threatTable;
    }

    /**
     * @param array<string, float> $threatTable
     */
    public function setThreatTable(array $threatTable): self
    {
        $this->threatTable = $threatTable;

        return $this;
    }

    public function addThreat(int $characterId, float $amount): void
    {
        $key = (string) $characterId;
        $this->threatTable[$key] = ($this->threatTable[$key] ?? 0.0) + max(0.0, $amount);
    }

    /**
     * @return array<string, float>
     */
    public function getRaidContributors(): array
    {
        return $this->raidContributors;
    }

    /**
     * @param array<string, float> $raidContributors
     */
    public function setRaidContributors(array $raidContributors): self
    {
        $this->raidContributors = $raidContributors;

        return $this;
    }

    public function addRaidContribution(int $characterId, float $amount): void
    {
        if ($characterId <= 0 || $amount <= 0.0) {
            return;
        }
        $key = (string) $characterId;
        $this->raidContributors[$key] = ($this->raidContributors[$key] ?? 0.0) + $amount;
    }

    /**
     * @return list<int>
     */
    public function getRaidContributorIds(): array
    {
        $ids = [];
        foreach (array_keys($this->raidContributors) as $k) {
            $id = (int) $k;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function getRaidContributorCount(): int
    {
        return count($this->raidContributors);
    }

    public function getTopThreatCharacterId(float $now): ?int
    {
        if ($this->tauntedByCharacterId !== null && $this->tauntExpiresAt > $now) {
            return $this->tauntedByCharacterId;
        }

        if ($this->threatTable === []) {
            return null;
        }

        $topId = null;
        $topThreat = -1.0;
        foreach ($this->threatTable as $charId => $threat) {
            if ($threat > $topThreat) {
                $topThreat = $threat;
                $topId = (int) $charId;
            }
        }

        return $topId;
    }

    public function getTauntedByCharacterId(): ?int
    {
        return $this->tauntedByCharacterId;
    }

    public function setTauntedByCharacterId(?int $tauntedByCharacterId): self
    {
        $this->tauntedByCharacterId = $tauntedByCharacterId;

        return $this;
    }

    public function getTauntExpiresAt(): float
    {
        return $this->tauntExpiresAt;
    }

    public function setTauntExpiresAt(float $tauntExpiresAt): self
    {
        $this->tauntExpiresAt = $tauntExpiresAt;

        return $this;
    }

    /**
     * @return list<array{
     *   type: string,
     *   skillCode: string,
     *   sourceId: int,
     *   value: float,
     *   expiresAt: float,
     *   nextTickAt: float,
     *   tickInterval: float
     * }>
     */
    public function getActiveEffects(): array
    {
        return $this->activeEffects;
    }

    /**
     * @param list<array{
     *   type: string,
     *   skillCode: string,
     *   sourceId: int,
     *   value: float,
     *   expiresAt: float,
     *   nextTickAt: float,
     *   tickInterval: float
     * }> $activeEffects
     */
    public function setActiveEffects(array $activeEffects): self
    {
        $this->activeEffects = $activeEffects;

        return $this;
    }

    public function getLastAttackAt(): float
    {
        return $this->lastAttackAt;
    }

    public function setLastAttackAt(float $lastAttackAt): self
    {
        $this->lastAttackAt = $lastAttackAt;

        return $this;
    }

    public function getLastSpecialAt(): float
    {
        return $this->lastSpecialAt;
    }

    public function setLastSpecialAt(float $lastSpecialAt): self
    {
        $this->lastSpecialAt = $lastSpecialAt;

        return $this;
    }

    public function getPhase(): int
    {
        return $this->phase;
    }

    public function setPhase(int $phase): self
    {
        $this->phase = max(1, $phase);

        return $this;
    }

    public function getRespawnAt(): float
    {
        return $this->respawnAt;
    }

    public function setRespawnAt(float $respawnAt): self
    {
        $this->respawnAt = $respawnAt;

        return $this;
    }
}
