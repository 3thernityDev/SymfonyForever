<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterItemRepository::class)]
#[ORM\Table(name: 'character_item')]
#[ORM\Index(name: 'idx_char_item_char_slot', columns: ['character_id', 'equipped_slot'])]
class CharacterItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(length: 48)]
    private string $itemCode;

    #[ORM\Column]
    private int $quantity = 1;

    /** NULL = inside bag; WEAPON | ARMOR | HELM | ACCESSORY = currently equipped */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $equippedSlot = null;

    #[ORM\Column]
    private \DateTimeImmutable $acquiredAt;

    public function __construct(Character $character, string $itemCode, int $quantity = 1)
    {
        $this->character = $character;
        $this->itemCode = $itemCode;
        $this->quantity = max(1, $quantity);
        $this->acquiredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getItemCode(): string
    {
        return $this->itemCode;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = max(0, $quantity);

        return $this;
    }

    public function getEquippedSlot(): ?string
    {
        return $this->equippedSlot;
    }

    public function setEquippedSlot(?string $equippedSlot): self
    {
        $this->equippedSlot = $equippedSlot;

        return $this;
    }

    public function isEquipped(): bool
    {
        return $this->equippedSlot !== null;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }
}
