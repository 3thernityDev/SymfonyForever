<?php

declare(strict_types=1);

namespace App\Entity;

use App\Game\CharacterClass;
use App\Repository\CharacterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterRepository::class)]
#[ORM\Table(name: 'game_character')]
#[ORM\Index(name: 'idx_char_zone_seen', columns: ['zone_code', 'last_seen_at'])]
class Character
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'characters')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: Party::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Party $party = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $classCode;

    #[ORM\Column]
    private int $level = 1;

    #[ORM\Column]
    private int $xp = 0;

    #[ORM\Column]
    private int $statPoints = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $skillPoints = 0;

    /** @var array<string, int> skillCode => rank (1..5) */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $skillLevels = [];

    #[ORM\Column(options: ['default' => 25])]
    private int $gold = 25;

    /** @var Collection<int, CharacterItem> */
    #[ORM\OneToMany(targetEntity: CharacterItem::class, mappedBy: 'character', cascade: ['persist', 'remove'])]
    private Collection $items;

    #[ORM\Column]
    private int $vitality = 10;

    #[ORM\Column]
    private int $strength = 10;

    #[ORM\Column]
    private int $intelligence = 10;

    #[ORM\Column]
    private int $defense = 10;

    #[ORM\Column]
    private int $spirit = 10;

    #[ORM\Column]
    private int $currentHp = 150;

    #[ORM\Column]
    private int $currentMp = 100;

    #[ORM\Column]
    private bool $isDead = false;

    #[ORM\Column(length: 40)]
    private string $zoneCode = 'lagon_ouest';

    #[ORM\Column]
    private float $posX = -2047.5;

    #[ORM\Column]
    private float $posY = 12.0;

    #[ORM\Column]
    private float $posZ = -332.5;

    #[ORM\Column]
    private float $rotY = 0.0;

    #[ORM\Column]
    private bool $isMoving = false;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $targetType = null; // MONSTER | PLAYER

    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    #[ORM\Column]
    private float $gcdEndsAt = 0.0;

    #[ORM\Column]
    private float $lastCombatAt = 0.0;

    /** @var array<string, float> skillCode => unix timestamp (float) when cooldown expires */
    #[ORM\Column(type: 'json')]
    private array $cooldowns = [];

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

    #[ORM\Column(nullable: true)]
    private ?int $pendingPartyInviteId = null;

    #[ORM\Column]
    private float $lastSeenAt = 0.0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isBot = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $botState = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $name, CharacterClass $class)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->classCode = $class->value;
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();

        $stats = $class->baseStats();
        $this->vitality = $stats['vitality'];
        $this->strength = $stats['strength'];
        $this->intelligence = $stats['intelligence'];
        $this->defense = $stats['defense'];
        $this->spirit = $stats['spirit'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getParty(): ?Party
    {
        return $this->party;
    }

    public function setParty(?Party $party): self
    {
        if ($this->party === $party) {
            return $this;
        }

        if ($this->party !== null) {
            $this->party->removeMember($this);
        }

        $this->party = $party;

        if ($party !== null) {
            $party->addMember($this);
        }

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getClassCode(): string
    {
        return $this->classCode;
    }

    public function getCharacterClass(): CharacterClass
    {
        return CharacterClass::from($this->classCode);
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = max(1, $level);

        return $this;
    }

    public function getXp(): int
    {
        return $this->xp;
    }

    public function setXp(int $xp): self
    {
        $this->xp = max(0, $xp);

        return $this;
    }

    public function getStatPoints(): int
    {
        return $this->statPoints;
    }

    public function setStatPoints(int $statPoints): self
    {
        $this->statPoints = max(0, $statPoints);

        return $this;
    }

    public function getSkillPoints(): int
    {
        return $this->skillPoints;
    }

    public function setSkillPoints(int $skillPoints): self
    {
        $this->skillPoints = max(0, $skillPoints);

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getSkillLevels(): array
    {
        return $this->skillLevels;
    }

    /**
     * @param array<string, int> $skillLevels
     */
    public function setSkillLevels(array $skillLevels): self
    {
        $this->skillLevels = $skillLevels;

        return $this;
    }

    public function getSkillLevel(string $skillCode): int
    {
        return max(1, (int) ($this->skillLevels[$skillCode] ?? 1));
    }

    public function setSkillLevel(string $skillCode, int $rank): self
    {
        $this->skillLevels[$skillCode] = max(1, $rank);

        return $this;
    }

    public function getGold(): int
    {
        return $this->gold;
    }

    public function setGold(int $gold): self
    {
        $this->gold = max(0, $gold);

        return $this;
    }

    /**
     * @return Collection<int, CharacterItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(CharacterItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        return $this;
    }

    public function removeItem(CharacterItem $item): self
    {
        $this->items->removeElement($item);

        return $this;
    }

    public function getVitality(): int
    {
        return $this->vitality;
    }

    public function setVitality(int $vitality): self
    {
        $this->vitality = max(1, $vitality);

        return $this;
    }

    public function getStrength(): int
    {
        return $this->strength;
    }

    public function setStrength(int $strength): self
    {
        $this->strength = max(1, $strength);

        return $this;
    }

    public function getIntelligence(): int
    {
        return $this->intelligence;
    }

    public function setIntelligence(int $intelligence): self
    {
        $this->intelligence = max(1, $intelligence);

        return $this;
    }

    public function getDefense(): int
    {
        return $this->defense;
    }

    public function setDefense(int $defense): self
    {
        $this->defense = max(1, $defense);

        return $this;
    }

    public function getSpirit(): int
    {
        return $this->spirit;
    }

    public function setSpirit(int $spirit): self
    {
        $this->spirit = max(1, $spirit);

        return $this;
    }

    public function getCurrentHp(): int
    {
        return $this->currentHp;
    }

    public function setCurrentHp(int $currentHp): self
    {
        $this->currentHp = max(0, $currentHp);

        return $this;
    }

    public function getCurrentMp(): int
    {
        return $this->currentMp;
    }

    public function setCurrentMp(int $currentMp): self
    {
        $this->currentMp = max(0, $currentMp);

        return $this;
    }

    public function isDead(): bool
    {
        return $this->isDead;
    }

    public function setIsDead(bool $isDead): self
    {
        $this->isDead = $isDead;

        return $this;
    }

    public function getZoneCode(): string
    {
        return $this->zoneCode;
    }

    public function setZoneCode(string $zoneCode): self
    {
        $this->zoneCode = $zoneCode;

        return $this;
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

    public function isMoving(): bool
    {
        return $this->isMoving;
    }

    public function setIsMoving(bool $isMoving): self
    {
        $this->isMoving = $isMoving;

        return $this;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function setTargetType(?string $targetType): self
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function setTargetId(?int $targetId): self
    {
        $this->targetId = $targetId;

        return $this;
    }

    public function getGcdEndsAt(): float
    {
        return $this->gcdEndsAt;
    }

    public function setGcdEndsAt(float $gcdEndsAt): self
    {
        $this->gcdEndsAt = $gcdEndsAt;

        return $this;
    }

    public function getLastCombatAt(): float
    {
        return $this->lastCombatAt;
    }

    public function setLastCombatAt(float $lastCombatAt): self
    {
        $this->lastCombatAt = $lastCombatAt;

        return $this;
    }

    /**
     * @return array<string, float>
     */
    public function getCooldowns(): array
    {
        return $this->cooldowns;
    }

    /**
     * @param array<string, float> $cooldowns
     */
    public function setCooldowns(array $cooldowns): self
    {
        $this->cooldowns = $cooldowns;

        return $this;
    }

    public function setCooldown(string $skillCode, float $expiresAt): self
    {
        $this->cooldowns[$skillCode] = $expiresAt;

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

    public function getPendingPartyInviteId(): ?int
    {
        return $this->pendingPartyInviteId;
    }

    public function setPendingPartyInviteId(?int $pendingPartyInviteId): self
    {
        $this->pendingPartyInviteId = $pendingPartyInviteId;

        return $this;
    }

    public function getLastSeenAt(): float
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(float $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function setIsBot(bool $isBot): self
    {
        $this->isBot = $isBot;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBotState(): array
    {
        return $this->botState;
    }

    /**
     * @param array<string, mixed> $botState
     */
    public function setBotState(array $botState): self
    {
        $this->botState = $botState;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
