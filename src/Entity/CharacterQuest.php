<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterQuestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterQuestRepository::class)]
#[ORM\Table(name: 'character_quest')]
#[ORM\UniqueConstraint(name: 'uniq_char_quest', columns: ['character_id', 'quest_code'])]
#[ORM\Index(name: 'idx_char_quest_status', columns: ['character_id', 'status'])]
class CharacterQuest
{
    public const string STATUS_ACTIVE = 'ACTIVE';
    public const string STATUS_READY = 'READY';
    public const string STATUS_COMPLETED = 'COMPLETED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(length: 48)]
    private string $questCode;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private int $progress = 0;

    #[ORM\Column]
    private \DateTimeImmutable $acceptedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(Character $character, string $questCode)
    {
        $this->character = $character;
        $this->questCode = $questCode;
        $this->acceptedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getQuestCode(): string
    {
        return $this->questCode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function setProgress(int $progress): self
    {
        $this->progress = max(0, $progress);

        return $this;
    }

    public function getAcceptedAt(): \DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }
}
