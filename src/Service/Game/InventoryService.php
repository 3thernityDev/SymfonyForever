<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Entity\CharacterItem;
use App\Entity\CombatEvent;
use App\Entity\MonsterInstance;
use App\Entity\WorldLootDrop;
use App\Game\ItemDefinition;
use App\Game\ItemRegistry;
use App\Game\NpcRegistry;
use App\Repository\CharacterItemRepository;
use App\Repository\WorldLootDropRepository;
use Doctrine\ORM\EntityManagerInterface;

final class InventoryService
{
    public const int MAX_BAG_SLOTS = 24;
    public const float LOOT_PICKUP_RANGE = 16.0;
    public const float VENDOR_RANGE = 20.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItemRegistry $itemRegistry,
        private readonly NpcRegistry $npcRegistry,
        private readonly CharacterItemRepository $itemRepository,
        private readonly WorldLootDropRepository $lootRepository,
        private readonly ReunionTerrainService $terrainService,
        private readonly StatCalculator $statCalculator,
    ) {
    }

    /**
     * Generates a ground loot drop at the dead monster's position.
     * Note: caller flushes EntityManager.
     */
    public function spawnLootForMonster(MonsterInstance $monster, ?Character $killer, float $now): WorldLootDrop
    {
        $tpl = $monster->getTemplate();
        $lvl = $tpl->getLevel();

        $baseGold = $tpl->isBoss()
            ? mt_rand(55, 95) + $lvl * 10
            : mt_rand(4, 9) + $lvl * 3;

        $droppedItems = [];
        $highestRarity = ItemDefinition::RARITY_COMMON;

        foreach ($this->itemRegistry->lootTableForMonster($tpl->getCode()) as [$itemCode, $chance, $minQty, $maxQty]) {
            $roll = mt_rand(1, 1000) / 1000.0;
            if ($roll > $chance) {
                continue;
            }
            $def = $this->itemRegistry->get($itemCode);
            if ($def === null) {
                continue;
            }
            $qty = mt_rand($minQty, $maxQty);
            $droppedItems[] = ['itemCode' => $itemCode, 'quantity' => $qty];
            if (ItemDefinition::rarityRank($def->rarity) > ItemDefinition::rarityRank($highestRarity)) {
                $highestRarity = $def->rarity;
            }
        }

        // Guarantee at least 1 item on bosses or if RNG was unlucky
        if ($droppedItems === []) {
            $table = $this->itemRegistry->lootTableForMonster($tpl->getCode());
            if ($table !== []) {
                $firstCode = $table[0][0];
                $droppedItems[] = ['itemCode' => $firstCode, 'quantity' => 1];
            }
        }

        $drop = new WorldLootDrop(
            $monster->getZoneCode(),
            $tpl->getName(),
            $monster->getPosX(),
            $monster->getPosY(),
            $monster->getPosZ(),
            $baseGold,
            $droppedItems,
            $highestRarity,
            $killer?->getId(),
            $killer?->getParty()?->getId(),
            $now,
        );

        $this->em->persist($drop);

        return $drop;
    }

    /**
     * Spawns one personal 100% GUARANTEED LEGENDARY loot chest for EVERY player who
     * participated in defeating a World Boss (even across multiple parties / solo allies!).
     *
     * @param list<Character> $participants
     * @return list<WorldLootDrop>
     */
    public function spawnWorldBossLootForParticipants(MonsterInstance $monster, array $participants, float $now): array
    {
        $tpl = $monster->getTemplate();
        $drops = [];
        $count = max(1, count($participants));

        foreach ($participants as $idx => $participant) {
            $legendaryCode = $this->itemRegistry->guaranteedLegendaryForWorldBoss($participant->getCharacterClass());
            $droppedItems = [
                ['itemCode' => $legendaryCode, 'quantity' => 1],
                ['itemCode' => 'cendre_dolomieu', 'quantity' => 2],
                ['itemCode' => 'elixir_volcan', 'quantity' => 2],
            ];
            $gold = mt_rand(180, 260);

            // Arrange personal chests neatly around the fallen boss corpse on walkable ground
            $angle = ($idx / $count) * M_PI * 2.0;
            $radius = $count === 1 ? 1.5 : 4.2;
            $cx = $monster->getPosX() + cos($angle) * $radius;
            $cz = $monster->getPosZ() + sin($angle) * $radius;
            $cy = $this->terrainService->getHeight($cx, $cz);

            $drop = new WorldLootDrop(
                $monster->getZoneCode(),
                sprintf('Butin Légendaire (%s) — %s', $participant->getName(), $tpl->getName()),
                $cx,
                $cy,
                $cz,
                $gold,
                $droppedItems,
                ItemDefinition::RARITY_LEGENDARY,
                $participant->getId(),
                null, // strictly personal chest so every allied raider gets their own guaranteed Legendary!
                $now,
                240.0,
                300.0,
            );
            $this->em->persist($drop);
            $drops[] = $drop;
        }

        return $drops;
    }

    /**
     * Grants starter consumables to newly created characters.
     */
    public function grantStarterItems(Character $character): void
    {
        $this->addItemToBag($character, 'tisane_soin', 2);
        $this->addItemToBag($character, 'eau_cilaos', 1);
    }

    /**
     * Adds an item (stacking if stackable) into the character's bag. Returns false if bag is full.
     */
    public function addItemToBag(Character $character, string $itemCode, int $quantity = 1): bool
    {
        $def = $this->itemRegistry->get($itemCode);
        if ($def === null || $quantity <= 0) {
            return false;
        }

        if ($def->stackable) {
            $existing = $this->findStackableInMemoryOrDb($character, $itemCode);
            if ($existing !== null) {
                $existing->setQuantity($existing->getQuantity() + $quantity);

                return true;
            }
        }

        $bagCount = $this->countBagSlots($character);
        $slotsNeeded = $def->stackable ? 1 : $quantity;
        if ($bagCount + $slotsNeeded > self::MAX_BAG_SLOTS) {
            return false;
        }

        if ($def->stackable) {
            $ci = new CharacterItem($character, $itemCode, $quantity);
            $character->addItem($ci);
            $this->em->persist($ci);
        } else {
            for ($i = 0; $i < $quantity; ++$i) {
                $ci = new CharacterItem($character, $itemCode, 1);
                $character->addItem($ci);
                $this->em->persist($ci);
            }
        }

        return true;
    }

    /**
     * Picks up gold & items from a WorldLootDrop near the player.
     *
     * @return array{ok: bool, error?: string, goldGained?: int, itemsGained?: list<array{itemCode: string, name: string, quantity: int}>, remaining?: bool}
     */
    public function pickupLoot(Character $character, int $lootId, ?float $now = null): array
    {
        $now ??= microtime(true);

        if ($character->isDead()) {
            return ['ok' => false, 'error' => 'Vous ne pouvez pas ramasser de butin en étant vaincu.'];
        }

        $drop = $this->lootRepository->find($lootId);
        if (!$drop instanceof WorldLootDrop) {
            return ['ok' => false, 'error' => 'Ce butin a déjà été ramassé ou a disparu.'];
        }

        if (!$drop->canBeLootedBy($character, $now)) {
            return ['ok' => false, 'error' => 'Ce butin est réservé au joueur/groupe qui a vaincu le monstre.'];
        }

        if (hypot($character->getPosX() - $drop->getPosX(), $character->getPosZ() - $drop->getPosZ()) > self::LOOT_PICKUP_RANGE) {
            return ['ok' => false, 'error' => 'Approchez-vous du coffre de butin pour le ramasser.'];
        }

        $goldGained = $drop->getGold();
        if ($goldGained > 0) {
            $character->setGold($character->getGold() + $goldGained);
            $drop->setGold(0);
        }

        $itemsGained = [];
        $remainingItems = [];

        foreach ($drop->getItems() as $entry) {
            $code = (string) $entry['itemCode'];
            $qty = (int) $entry['quantity'];
            $def = $this->itemRegistry->get($code);
            if ($def === null) {
                continue;
            }

            if ($this->addItemToBag($character, $code, $qty)) {
                $itemsGained[] = ['itemCode' => $code, 'name' => $def->name, 'quantity' => $qty];
            } else {
                $remainingItems[] = $entry;
            }
        }

        $drop->setItems($remainingItems);

        if ($drop->isEmpty()) {
            $this->em->remove($drop);
        }

        $this->em->flush();

        if ($goldGained === 0 && $itemsGained === [] && $remainingItems !== []) {
            return ['ok' => false, 'error' => 'Votre sac est plein (24/24) ! Vendez ou jetez des objets.'];
        }

        return [
            'ok' => true,
            'goldGained' => $goldGained,
            'itemsGained' => $itemsGained,
            'remaining' => $remainingItems !== [],
        ];
    }

    /**
     * Equips an EQUIPMENT item from the bag into its slot (swapping any previously equipped item back to bag).
     *
     * @return array{ok: bool, error?: string}
     */
    public function equipItem(Character $character, int $characterItemId): array
    {
        if ($character->isDead()) {
            return ['ok' => false, 'error' => 'Impossible de changer d\'équipement en étant vaincu.'];
        }

        $item = $this->itemRepository->find($characterItemId);
        if (!$item instanceof CharacterItem || $item->getCharacter()->getId() !== $character->getId()) {
            return ['ok' => false, 'error' => 'Objet introuvable dans votre sac.'];
        }

        if ($item->isEquipped()) {
            return ['ok' => false, 'error' => 'Cet objet est déjà équipé.'];
        }

        $def = $this->itemRegistry->get($item->getItemCode());
        if ($def === null || $def->type !== ItemDefinition::TYPE_EQUIPMENT || $def->slot === null) {
            return ['ok' => false, 'error' => 'Cet objet ne peut pas être équipé.'];
        }

        if ($character->getLevel() < $def->minLevel) {
            return ['ok' => false, 'error' => sprintf('Niveau %d requis pour équiper %s.', $def->minLevel, $def->name)];
        }

        $current = $this->itemRepository->findEquippedInSlot($character, $def->slot);
        if ($current instanceof CharacterItem) {
            $current->setEquippedSlot(null);
        }

        $item->setEquippedSlot($def->slot);
        $this->clampVitalsToMax($character);
        $this->em->flush();

        return ['ok' => true];
    }

    /**
     * Unequips an item back into the bag.
     *
     * @return array{ok: bool, error?: string}
     */
    public function unequipSlot(Character $character, string $slot): array
    {
        if (!in_array($slot, ItemDefinition::EQUIPMENT_SLOTS, true)) {
            return ['ok' => false, 'error' => 'Emplacement d\'équipement invalide.'];
        }

        $current = $this->itemRepository->findEquippedInSlot($character, $slot);
        if (!$current instanceof CharacterItem) {
            return ['ok' => false, 'error' => 'Aucun objet équipé dans cet emplacement.'];
        }

        if ($this->countBagSlots($character) >= self::MAX_BAG_SLOTS) {
            return ['ok' => false, 'error' => 'Votre sac est plein (24/24).'];
        }

        $current->setEquippedSlot(null);
        $this->clampVitalsToMax($character);
        $this->em->flush();

        return ['ok' => true];
    }

    /**
     * Uses a CONSUMABLE item from the bag (potions / tisanes).
     *
     * @return array{ok: bool, error?: string, restoredHp?: int, restoredMp?: int}
     */
    public function useItem(Character $character, int $characterItemId, ?float $now = null): array
    {
        $now ??= microtime(true);

        if ($character->isDead()) {
            return ['ok' => false, 'error' => 'Impossible d\'utiliser un consommable en étant vaincu.'];
        }

        $item = $this->itemRepository->find($characterItemId);
        if (!$item instanceof CharacterItem || $item->getCharacter()->getId() !== $character->getId() || $item->isEquipped()) {
            return ['ok' => false, 'error' => 'Objet introuvable dans votre sac.'];
        }

        $def = $this->itemRegistry->get($item->getItemCode());
        if ($def === null || $def->type !== ItemDefinition::TYPE_CONSUMABLE) {
            return ['ok' => false, 'error' => 'Cet objet n\'est pas consommable.'];
        }

        if ($character->getLevel() < $def->minLevel) {
            return ['ok' => false, 'error' => sprintf('Niveau %d requis pour utiliser %s.', $def->minLevel, $def->name)];
        }

        $stats = $this->statCalculator->compute($character, $now);
        $hpBefore = $character->getCurrentHp();
        $mpBefore = $character->getCurrentMp();

        if ($def->restoreHp > 0) {
            $character->setCurrentHp(min($stats['maxHp'], $hpBefore + $def->restoreHp));
        }
        if ($def->restoreMp > 0) {
            $character->setCurrentMp(min($stats['maxMp'], $mpBefore + $def->restoreMp));
        }

        $restoredHp = $character->getCurrentHp() - $hpBefore;
        $restoredMp = $character->getCurrentMp() - $mpBefore;

        if ($item->getQuantity() > 1) {
            $item->setQuantity($item->getQuantity() - 1);
        } else {
            $character->removeItem($item);
            $this->em->remove($item);
        }

        if ($restoredHp > 0) {
            $ev = new CombatEvent(
                $character->getZoneCode(),
                'HEAL',
                'PLAYER',
                (int) $character->getId(),
                $character->getName(),
                'PLAYER',
                (int) $character->getId(),
                $character->getName(),
                $def->code,
                $restoredHp,
                false,
                $character->getPosX(),
                $character->getPosY(),
                $character->getPosZ(),
                $now,
            );
            $this->em->persist($ev);
        }

        $this->em->flush();

        return [
            'ok' => true,
            'restoredHp' => $restoredHp,
            'restoredMp' => $restoredMp,
        ];
    }

    /**
     * Discards an unequipped item stack from the bag.
     *
     * @return array{ok: bool, error?: string}
     */
    public function dropItem(Character $character, int $characterItemId): array
    {
        $item = $this->itemRepository->find($characterItemId);
        if (!$item instanceof CharacterItem || $item->getCharacter()->getId() !== $character->getId()) {
            return ['ok' => false, 'error' => 'Objet introuvable.'];
        }
        if ($item->isEquipped()) {
            return ['ok' => false, 'error' => 'Déséquipez l\'objet avant de le jeter.'];
        }

        $character->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();

        return ['ok' => true];
    }

    /**
     * Sells a bag item (entire stack or 1 unit) to a nearby NPC.
     *
     * @return array{ok: bool, error?: string, goldEarned?: int, itemName?: string}
     */
    public function sellItem(Character $character, int $characterItemId, string $npcCode): array
    {
        $rangeCheck = $this->assertNearNpc($character, $npcCode);
        if ($rangeCheck !== null) {
            return ['ok' => false, 'error' => $rangeCheck];
        }

        $item = $this->itemRepository->find($characterItemId);
        if (!$item instanceof CharacterItem || $item->getCharacter()->getId() !== $character->getId() || $item->isEquipped()) {
            return ['ok' => false, 'error' => 'Objet introuvable dans votre sac.'];
        }

        $def = $this->itemRegistry->get($item->getItemCode());
        if ($def === null || $def->sellPrice <= 0) {
            return ['ok' => false, 'error' => 'Cet objet n\'a aucune valeur marchande.'];
        }

        $earned = $def->sellPrice * $item->getQuantity();
        $character->setGold($character->getGold() + $earned);
        $character->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();

        return [
            'ok' => true,
            'goldEarned' => $earned,
            'itemName' => $def->name,
        ];
    }

    /**
     * Sells all JUNK items in the character's bag to a nearby NPC in one click.
     *
     * @return array{ok: bool, error?: string, goldEarned?: int, soldCount?: int}
     */
    public function sellAllJunk(Character $character, string $npcCode): array
    {
        $rangeCheck = $this->assertNearNpc($character, $npcCode);
        if ($rangeCheck !== null) {
            return ['ok' => false, 'error' => $rangeCheck];
        }

        $bag = $this->itemRepository->findBagItemsForCharacter($character);
        $goldEarned = 0;
        $soldCount = 0;

        foreach ($bag as $item) {
            $def = $this->itemRegistry->get($item->getItemCode());
            if ($def === null || $def->type !== ItemDefinition::TYPE_JUNK || $def->sellPrice <= 0) {
                continue;
            }
            $goldEarned += $def->sellPrice * $item->getQuantity();
            $soldCount += $item->getQuantity();
            $character->removeItem($item);
            $this->em->remove($item);
        }

        if ($soldCount === 0) {
            return ['ok' => false, 'error' => 'Aucun butin à vendre dans votre sac.'];
        }

        $character->setGold($character->getGold() + $goldEarned);
        $this->em->flush();

        return [
            'ok' => true,
            'goldEarned' => $goldEarned,
            'soldCount' => $soldCount,
        ];
    }

    /**
     * Buys an item from a nearby NPC vendor stock.
     *
     * @return array{ok: bool, error?: string, itemName?: string, cost?: int}
     */
    public function buyItem(Character $character, string $itemCode, string $npcCode): array
    {
        $rangeCheck = $this->assertNearNpc($character, $npcCode);
        if ($rangeCheck !== null) {
            return ['ok' => false, 'error' => $rangeCheck];
        }

        $stock = $this->itemRegistry->vendorStockForNpc($npcCode);
        if (!in_array($itemCode, $stock, true)) {
            return ['ok' => false, 'error' => 'Ce marchand ne vend pas cet article.'];
        }

        $def = $this->itemRegistry->get($itemCode);
        if ($def === null || $def->buyPrice <= 0) {
            return ['ok' => false, 'error' => 'Article indisponible.'];
        }

        if ($character->getGold() < $def->buyPrice) {
            return ['ok' => false, 'error' => sprintf('Or insuffisant (%d requis).', $def->buyPrice)];
        }

        if (!$this->addItemToBag($character, $itemCode, 1)) {
            return ['ok' => false, 'error' => 'Votre sac est plein (24/24).'];
        }

        $character->setGold($character->getGold() - $def->buyPrice);
        $this->em->flush();

        return [
            'ok' => true,
            'itemName' => $def->name,
            'cost' => $def->buyPrice,
        ];
    }

    /**
     * Builds the compact inventory payload returned inside /api/world/sync and inventory endpoints.
     *
     * @return array<string, mixed>
     */
    public function buildInventoryState(Character $character): array
    {
        $rows = $this->itemRepository->findAllForCharacter($character);

        $equipped = [
            ItemDefinition::SLOT_WEAPON => null,
            ItemDefinition::SLOT_ARMOR => null,
            ItemDefinition::SLOT_HELM => null,
            ItemDefinition::SLOT_ACCESSORY => null,
        ];
        $bag = [];

        foreach ($rows as $row) {
            $entry = [
                'id' => $row->getId(),
                'itemCode' => $row->getItemCode(),
                'quantity' => $row->getQuantity(),
                'equippedSlot' => $row->getEquippedSlot(),
            ];

            if ($row->isEquipped()) {
                $equipped[(string) $row->getEquippedSlot()] = $entry;
            } else {
                $bag[] = $entry;
            }
        }

        return [
            'gold' => $character->getGold(),
            'maxBagSlots' => self::MAX_BAG_SLOTS,
            'bagSlotsUsed' => count($bag),
            'equipped' => $equipped,
            'bag' => $bag,
        ];
    }

    private function assertNearNpc(Character $character, string $npcCode): ?string
    {
        if ($character->isDead()) {
            return 'Ressuscitez avant de commercer avec un PNJ.';
        }
        $npc = $this->npcRegistry->get($npcCode);
        if ($npc === null) {
            return 'Marchand introuvable.';
        }
        $pos = $this->npcRegistry->resolvedPosition($npc, $this->terrainService);
        if (hypot($character->getPosX() - $pos['x'], $character->getPosZ() - $pos['z']) > self::VENDOR_RANGE) {
            return sprintf('Approchez-vous de %s pour commercer.', $npc->name);
        }

        return null;
    }

    private function findStackableInMemoryOrDb(Character $character, string $itemCode): ?CharacterItem
    {
        foreach ($character->getItems() as $ci) {
            if (!$ci->isEquipped() && $ci->getItemCode() === $itemCode) {
                return $ci;
            }
        }

        return $this->itemRepository->findStackableInBag($character, $itemCode);
    }

    private function countBagSlots(Character $character): int
    {
        $count = 0;
        foreach ($character->getItems() as $ci) {
            if (!$ci->isEquipped()) {
                ++$count;
            }
        }

        return $count;
    }

    private function clampVitalsToMax(Character $character): void
    {
        $stats = $this->statCalculator->compute($character);
        if ($character->getCurrentHp() > $stats['maxHp']) {
            $character->setCurrentHp($stats['maxHp']);
        }
        if ($character->getCurrentMp() > $stats['maxMp']) {
            $character->setCurrentMp($stats['maxMp']);
        }
    }
}
