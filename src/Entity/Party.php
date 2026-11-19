<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartyRepository::class)]
#[ORM\Table(name: 'game_party')]
class Party
{
    public const int MAX_MEMBERS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $name = 'Groupe d\'Aventuriers';

    #[ORM\Column]
    private int $leaderCharacterId = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Character> */
    #[ORM\OneToMany(targetEntity: Character::class, mappedBy: 'party')]
    private Collection $members;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLeaderCharacterId(): int
    {
        return $this->leaderCharacterId;
    }

    public function setLeaderCharacterId(int $leaderCharacterId): self
    {
        $this->leaderCharacterId = $leaderCharacterId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Character>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(Character $character): self
    {
        if (!$this->members->contains($character)) {
            $this->members->add($character);
        }

        return $this;
    }

    public function removeMember(Character $character): self
    {
        $this->members->removeElement($character);

        return $this;
    }
}
