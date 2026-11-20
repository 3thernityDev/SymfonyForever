<?php

declare(strict_types=1);

namespace App\Game;

use App\Service\Game\LevelingService;

/**
 * Master catalog of items, consumables, mounts, monster drops, equipment (Niv 1-55) and NPC shop stock.
 */
final class ItemRegistry
{
    /** @var array<string, ItemDefinition> */
    private array $items = [];

    public function __construct()
    {
        $defs = [
            // ================= CONSOMMABLES (Boutique + Drops) =================
            new ItemDefinition(
                code: 'tisane_soin',
                name: 'Tisane de Soin',
                description: 'Infusion chaude de plantes péi qui restaure 95 PV instantanément.',
                type: ItemDefinition::TYPE_CONSUMABLE,
                slot: null,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🧪',
                sellPrice: 4,
                buyPrice: 12,
                stackable: true,
                restoreHp: 95,
            ),
            new ItemDefinition(
                code: 'eau_cilaos',
                name: 'Eau de Source de Cilaos',
                description: 'Eau pétillante pure des hauts qui restaure 85 PM instantanément.',
                type: ItemDefinition::TYPE_CONSUMABLE,
                slot: null,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '💧',
                sellPrice: 4,
                buyPrice: 12,
                stackable: true,
                restoreMp: 85,
            ),
            new ItemDefinition(
                code: 'elixir_volcan',
                name: 'Élixir du Volcan',
                description: 'Décoction puissante aux épices volcaniques : +220 PV et +120 PM.',
                type: ItemDefinition::TYPE_CONSUMABLE,
                slot: null,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 4,
                icon: '⚗️',
                sellPrice: 12,
                buyPrice: 35,
                stackable: true,
                restoreHp: 220,
                restoreMp: 120,
            ),
            new ItemDefinition(
                code: 'nectar_piton',
                name: 'Nectar des Hauts',
                description: 'Élixir concentré des sommets de La Réunion : +1 400 PV et +600 PM.',
                type: ItemDefinition::TYPE_CONSUMABLE,
                slot: null,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 30,
                icon: '🍯',
                sellPrice: 45,
                buyPrice: 140,
                stackable: true,
                restoreHp: 1400,
                restoreMp: 600,
            ),

            // ================= BUTIN / TROPHÉES (À vendre aux PNJ) =================
            new ItemDefinition(
                code: 'pince_crabe',
                name: 'Pince de Crabe',
                description: 'Trophée du lagon très prisé par les marchands de Saint-Gilles.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🦀',
                sellPrice: 6,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'epine_tangue',
                name: 'Épine de Tangue',
                description: 'Piquant solide récupéré sur un Tangue Épineux.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🪡',
                sellPrice: 9,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'plume_paille_queue',
                name: 'Plume de Paille-en-Queue',
                description: 'Longue plume blanche emblématique des falaises côtières.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🪶',
                sellPrice: 12,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'plume_papangue',
                name: 'Plume de Papangue',
                description: 'Plume sombre et légère des rapaces des cirques.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🪶',
                sellPrice: 14,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'eclat_schiste',
                name: 'Éclat de Schiste',
                description: 'Fragment minéral vibrant arraché aux remparts de Mafate.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🪨',
                sellPrice: 20,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'ecailles_gecko',
                name: 'Écailles de Gecko Vert',
                description: 'Écailles émeraude iridescentes recherchées par les tisaneurs.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 1,
                icon: '🦎',
                sellPrice: 24,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'soie_bibe',
                name: 'Fil d\'Or de Bibe',
                description: 'Soie dorée ultra-résistante tissée dans les remparts de Mafate.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 1,
                icon: '🕸️',
                sellPrice: 34,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'ecaille_obsidienne',
                name: 'Écaille d\'Obsidienne',
                description: 'Écaille volcanique brillante du Sud Sauvage.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 1,
                icon: '🖤',
                sellPrice: 30,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'carapace_tortue',
                name: 'Éclat de Carapace Bourbonne',
                description: 'Fragment antique d\'une Tortue Géante de Bourbon.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 1,
                icon: '🐢',
                sellPrice: 48,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'cendre_dolomieu',
                name: 'Cendre Incandescente',
                description: 'Poussière magmatique précieuse récoltée près de la Fournaise.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 1,
                icon: '✨',
                sellPrice: 55,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'plume_petrel',
                name: 'Plume de Pétrel',
                description: 'Rémige rare des pétrels nichant sur les cimes du Piton des Neiges.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 1,
                icon: '🦅',
                sellPrice: 62,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'aile_papillon',
                name: 'Aile Azurée La Pâture',
                description: 'Aile chatoyante d\'un papillon endémique des hauts.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 1,
                icon: '🦋',
                sellPrice: 68,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'croc_roussette',
                name: 'Croc de Roussette Noire',
                description: 'Trophée nocturne prélevé sur une grande chauve-souris de Bourbon.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 1,
                icon: '🦇',
                sellPrice: 78,
                buyPrice: 0,
                stackable: true,
            ),
            new ItemDefinition(
                code: 'plume_solitaire',
                name: 'Plume de Solitaire',
                description: 'Relique rarissime du légendaire Solitaire de La Réunion.',
                type: ItemDefinition::TYPE_JUNK,
                slot: null,
                rarity: ItemDefinition::RARITY_EPIC,
                minLevel: 1,
                icon: '🕊️',
                sellPrice: 85,
                buyPrice: 0,
                stackable: true,
            ),

            // ================= ÉQUIPEMENTS NIV. 1–3 (Lagon de l'Ouest) =================
            new ItemDefinition(
                code: 'lame_corail',
                name: 'Lame de Corail',
                description: 'Arme tranchante taillée dans le récif de l\'Ermitage.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🗡️',
                sellPrice: 14,
                buyPrice: 38,
                stackable: false,
                bonuses: ['strength' => 3, 'vitality' => 1],
            ),
            new ItemDefinition(
                code: 'baton_filao',
                name: 'Bâton de Filao',
                description: 'Bâton léger canalisant la lumière du lagon.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '🪄',
                sellPrice: 14,
                buyPrice: 38,
                stackable: false,
                bonuses: ['intelligence' => 3, 'spirit' => 2],
            ),
            new ItemDefinition(
                code: 'tunique_lagon',
                name: 'Tunique du Lagon',
                description: 'Vêtement léger tissé pour résister aux embruns et aux griffures.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_ARMOR,
                rarity: ItemDefinition::RARITY_COMMON,
                minLevel: 1,
                icon: '👕',
                sellPrice: 15,
                buyPrice: 40,
                stackable: false,
                bonuses: ['vitality' => 3, 'defense' => 2],
            ),
            new ItemDefinition(
                code: 'chapeau_vacoua',
                name: 'Chapeau de Vacoua',
                description: 'Chapeau tressé traditionnel offrant une bonne protection contre le soleil et les coups.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_HELM,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 1,
                icon: '👒',
                sellPrice: 18,
                buyPrice: 48,
                stackable: false,
                bonuses: ['vitality' => 2, 'defense' => 2, 'spirit' => 2],
            ),
            new ItemDefinition(
                code: 'collier_coquillage',
                name: 'Collier du Récif',
                description: 'Talisman béni par les marées de Saint-Gilles.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_ACCESSORY,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 2,
                icon: '📿',
                sellPrice: 22,
                buyPrice: 60,
                stackable: false,
                bonuses: ['strength' => 2, 'intelligence' => 2, 'vitality' => 2],
            ),

            // ================= ÉQUIPEMENTS NIV. 10–35 (Cirques : Salazie, Mafate, Cilaos) =================
            new ItemDefinition(
                code: 'machette_salazie',
                name: 'Machette de Salazie',
                description: 'Lame redoutable forgée pour fendre la forêt dense et les bêtes sauvages.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 10,
                icon: '⚔️',
                sellPrice: 55,
                buyPrice: 160,
                stackable: false,
                bonuses: ['strength' => 14, 'vitality' => 5],
            ),
            new ItemDefinition(
                code: 'sceptre_cascades',
                name: 'Sceptre des Cascades',
                description: 'Bâton imprégné de la brume vivifiante du Voile de la Mariée.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 10,
                icon: '🌿',
                sellPrice: 55,
                buyPrice: 160,
                stackable: false,
                bonuses: ['intelligence' => 14, 'spirit' => 9],
            ),
            new ItemDefinition(
                code: 'cuirasse_mafate',
                name: 'Cuirasse des Remparts',
                description: 'Armure lourde renforcée de plaques de schiste de Mafate.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_ARMOR,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 18,
                icon: '🦺',
                sellPrice: 110,
                buyPrice: 310,
                stackable: false,
                bonuses: ['vitality' => 18, 'defense' => 18],
            ),
            new ItemDefinition(
                code: 'heaume_cilaos',
                name: 'Heaume des Thermes',
                description: 'Casque finement travaillé par les artisans de Cilaos.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_HELM,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 27,
                icon: '🪖',
                sellPrice: 155,
                buyPrice: 430,
                stackable: false,
                bonuses: ['vitality' => 16, 'defense' => 15, 'strength' => 10, 'intelligence' => 10],
            ),
            new ItemDefinition(
                code: 'amulette_cirques',
                name: 'Amulette des Trois Cirques',
                description: 'Relique équilibrant les forces de Mafate, Salazie et Cilaos.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_ACCESSORY,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 27,
                icon: '💠',
                sellPrice: 165,
                buyPrice: 460,
                stackable: false,
                bonuses: ['vitality' => 14, 'strength' => 14, 'intelligence' => 14, 'spirit' => 14],
            ),

            // ================= ÉQUIPEMENTS NIV. 36–55 (Sud Sauvage, Piton des Neiges, Fournaise) =================
            new ItemDefinition(
                code: 'lame_obsidienne',
                name: 'Lame d\'Obsidienne',
                description: 'Épée noire au fil volcanique forgée dans le Grand Brûlé.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 36,
                icon: '🔥',
                sellPrice: 220,
                buyPrice: 620,
                stackable: false,
                bonuses: ['strength' => 28, 'intelligence' => 14, 'vitality' => 10],
            ),
            new ItemDefinition(
                code: 'plastron_nuages',
                name: 'Plastron du Piton des Neiges',
                description: 'Armure d\'élite tombée du Colosse des Nuages à 3 070 m.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_ARMOR,
                rarity: ItemDefinition::RARITY_EPIC,
                minLevel: 48,
                icon: '🛡️',
                sellPrice: 340,
                buyPrice: 0,
                stackable: false,
                bonuses: ['vitality' => 34, 'defense' => 32, 'spirit' => 14],
            ),
            new ItemDefinition(
                code: 'couronne_cimes',
                name: 'Couronne des Cimes',
                description: 'Diadème céleste rayonnant de l\'air pur des sommets.',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_HELM,
                rarity: ItemDefinition::RARITY_EPIC,
                minLevel: 48,
                icon: '👑',
                sellPrice: 330,
                buyPrice: 0,
                stackable: false,
                bonuses: ['intelligence' => 32, 'spirit' => 26, 'vitality' => 18],
            ),
            new ItemDefinition(
                code: 'tranchant_dolomieu',
                name: 'Tranchant du Dolomieu',
                description: 'Lame légendaire imprégnée de la rage du Seigneur du Dolomieu (World Boss).',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_LEGENDARY,
                minLevel: 55,
                icon: '🌋',
                sellPrice: 680,
                buyPrice: 0,
                stackable: false,
                bonuses: ['strength' => 48, 'intelligence' => 32, 'vitality' => 26, 'defense' => 16],
            ),
            new ItemDefinition(
                code: 'coeur_magma',
                name: 'Cœur du Volcan',
                description: 'Talisman légendaire battant au rythme du Piton de la Fournaise (World Boss).',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_ACCESSORY,
                rarity: ItemDefinition::RARITY_LEGENDARY,
                minLevel: 55,
                icon: '❤️‍🔥',
                sellPrice: 650,
                buyPrice: 0,
                stackable: false,
                bonuses: ['vitality' => 36, 'strength' => 32, 'intelligence' => 32, 'defense' => 30, 'spirit' => 30],
            ),
            new ItemDefinition(
                code: 'egide_fournaise',
                name: 'Égide de la Fournaise',
                description: 'Armure lourde légendaire forgée dans les plaques du Seigneur du Dolomieu (World Boss).',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_ARMOR,
                rarity: ItemDefinition::RARITY_LEGENDARY,
                minLevel: 55,
                icon: '🛡️',
                sellPrice: 720,
                buyPrice: 0,
                stackable: false,
                bonuses: ['vitality' => 48, 'defense' => 46, 'strength' => 22, 'spirit' => 18],
            ),
            new ItemDefinition(
                code: 'marteau_basalte',
                name: 'Brise-Cratère de Basalte',
                description: 'Marteau titanesque légendaire capable d\'encaisser la fureur du volcan (World Boss).',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_LEGENDARY,
                minLevel: 55,
                icon: '🔨',
                sellPrice: 700,
                buyPrice: 0,
                stackable: false,
                bonuses: ['vitality' => 44, 'defense' => 38, 'strength' => 40, 'spirit' => 16],
            ),
            new ItemDefinition(
                code: 'sceptre_piton',
                name: 'Sceptre de l\'Aube Volcanique',
                description: 'Bâton légendaire canalisant la lumière pure au-dessus de la Fournaise (World Boss).',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_WEAPON,
                rarity: ItemDefinition::RARITY_LEGENDARY,
                minLevel: 55,
                icon: '🌟',
                sellPrice: 700,
                buyPrice: 0,
                stackable: false,
                bonuses: ['intelligence' => 50, 'spirit' => 44, 'vitality' => 28, 'defense' => 16],
            ),
            new ItemDefinition(
                code: 'couronne_dolomieu',
                name: 'Couronne du Dolomieu',
                description: 'Diadème de braise éternelle arraché au front du Seigneur du Dolomieu (World Boss).',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_HELM,
                rarity: ItemDefinition::RARITY_LEGENDARY,
                minLevel: 55,
                icon: '👑',
                sellPrice: 690,
                buyPrice: 0,
                stackable: false,
                bonuses: ['vitality' => 38, 'intelligence' => 40, 'strength' => 35, 'defense' => 26, 'spirit' => 32],
            ),

            // ================= MONTURES (Sol +220%, Sol +300%, Volante +300% Niv. 60) =================
            new ItemDefinition(
                code: 'mount_sol_60',
                name: 'Poney des Plaines (+220%)',
                description: 'Monture robuste de la Plaine des Cafres augmentant votre vitesse au sol de +220% (vitesse ×3,2). Utilisable avec [C].',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_MOUNT,
                rarity: ItemDefinition::RARITY_UNCOMMON,
                minLevel: 1,
                icon: '🐎',
                sellPrice: 50,
                buyPrice: 200,
                stackable: false,
                mountSpeedBonus: 2.20,
                mountCanFly: false,
            ),
            new ItemDefinition(
                code: 'mount_sol_100',
                name: 'Destrier du Basalte (+300%)',
                description: 'Monture d\'élite des cirques augmentant votre vitesse au sol de +300% (vitesse ×4). Utilisable avec [C].',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_MOUNT,
                rarity: ItemDefinition::RARITY_RARE,
                minLevel: 30,
                icon: '🐴',
                sellPrice: 125,
                buyPrice: 500,
                stackable: false,
                mountSpeedBonus: 3.00,
                mountCanFly: false,
            ),
            new ItemDefinition(
                code: 'mount_vol_100',
                name: 'Paille-en-Queue Royal (Vol +300%)',
                description: 'Monture aérienne mythique réservée aux champions de niveau maximum (Niv. 60) : survole toute La Réunion à +300% (vitesse ×4) avec [Espace] / [Ctrl].',
                type: ItemDefinition::TYPE_EQUIPMENT,
                slot: ItemDefinition::SLOT_MOUNT,
                rarity: ItemDefinition::RARITY_LEGENDARY,
                minLevel: LevelingService::MAX_LEVEL,
                icon: '🦅',
                sellPrice: 250,
                buyPrice: 1000,
                stackable: false,
                mountSpeedBonus: 3.00,
                mountCanFly: true,
            ),
        ];

        foreach ($defs as $item) {
            $this->items[$item->code] = $item;
        }
    }

    public function get(string $code): ?ItemDefinition
    {
        return $this->items[$code] ?? null;
    }

    /**
     * @return list<ItemDefinition>
     */
    public function all(): array
    {
        return array_values($this->items);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toManifestArray(): array
    {
        $out = [];
        foreach ($this->items as $code => $item) {
            $out[$code] = $item->toArray();
        }

        return $out;
    }

    /**
     * Shop inventory available per NPC code.
     *
     * @return list<string>
     */
    public function vendorStockForNpc(string $npcCode): array
    {
        $base = match ($npcCode) {
            'gramoun_tijean' => ['tisane_soin', 'eau_cilaos', 'lame_corail', 'baton_filao', 'tunique_lagon', 'chapeau_vacoua', 'collier_coquillage'],
            'soeur_rosalie' => ['tisane_soin', 'eau_cilaos', 'elixir_volcan', 'machette_salazie', 'sceptre_cascades'],
            'ticoq_guide' => ['tisane_soin', 'eau_cilaos', 'elixir_volcan', 'cuirasse_mafate'],
            'maitre_thermalis' => ['tisane_soin', 'eau_cilaos', 'elixir_volcan', 'nectar_piton', 'heaume_cilaos', 'amulette_cirques'],
            'capitaine_basalte', 'eclaireuse_maido', 'vulcanologue_piton' => ['tisane_soin', 'eau_cilaos', 'elixir_volcan', 'nectar_piton', 'lame_obsidienne', 'cuirasse_mafate', 'amulette_cirques'],
            default => ['tisane_soin', 'eau_cilaos'],
        };

        return [...$base, 'mount_sol_60', 'mount_sol_100', 'mount_vol_100'];
    }

    /**
     * Loot table entries per monster template code: [itemCode, dropChance (0..1), minQty, maxQty].
     *
     * @return list<array{string, float, int, int}>
     */
    public function lootTableForMonster(string $monsterCode): array
    {
        return match ($monsterCode) {
            'crabe_recif' => [
                ['pince_crabe', 0.85, 1, 2],
                ['tisane_soin', 0.30, 1, 1],
                ['lame_corail', 0.18, 1, 1],
                ['baton_filao', 0.18, 1, 1],
                ['tunique_lagon', 0.16, 1, 1],
            ],
            'tangue_sauvage' => [
                ['epine_tangue', 0.85, 1, 2],
                ['tisane_soin', 0.30, 1, 1],
                ['eau_cilaos', 0.25, 1, 1],
                ['chapeau_vacoua', 0.22, 1, 1],
                ['collier_coquillage', 0.18, 1, 1],
            ],
            'guepe_cotiere', 'endormi_bourbon' => [
                ['epine_tangue', 0.70, 1, 2],
                ['eau_cilaos', 0.35, 1, 1],
                ['collier_coquillage', 0.25, 1, 1],
                ['machette_salazie', 0.15, 1, 1],
            ],
            'paille_en_queue' => [
                ['plume_paille_queue', 0.85, 1, 2],
                ['elixir_volcan', 0.28, 1, 1],
                ['machette_salazie', 0.18, 1, 1],
                ['sceptre_cascades', 0.18, 1, 1],
            ],
            'tec_tec_foret', 'sanglier_salazie', 'zoizo_lunettes', 'papangue_feroce' => [
                ['plume_papangue', 0.85, 1, 2],
                ['elixir_volcan', 0.28, 1, 1],
                ['machette_salazie', 0.22, 1, 1],
                ['sceptre_cascades', 0.22, 1, 1],
            ],
            'tuit_tuit', 'gecko_bourbon' => [
                ['ecailles_gecko', 0.85, 1, 2],
                ['elixir_volcan', 0.35, 1, 1],
                ['cuirasse_mafate', 0.24, 1, 1],
            ],
            'golem_schiste' => [
                ['eclat_schiste', 0.90, 1, 2],
                ['elixir_volcan', 0.35, 1, 1],
                ['cuirasse_mafate', 0.28, 1, 1],
            ],
            'bibe_mafate' => [
                ['soie_bibe', 0.90, 1, 2],
                ['elixir_volcan', 0.40, 1, 1],
                ['cuirasse_mafate', 0.28, 1, 1],
                ['heaume_cilaos', 0.18, 1, 1],
            ],
            'oiseau_vert_cilaos', 'cerf_java', 'merle_peyi', 'gardien_cilaos' => [
                ['eclat_schiste', 0.80, 1, 2],
                ['nectar_piton', 0.35, 1, 1],
                ['heaume_cilaos', 0.26, 1, 1],
                ['amulette_cirques', 0.24, 1, 1],
            ],
            'gecko_manapany', 'tortue_bourbon' => [
                ['carapace_tortue', 0.90, 1, 2],
                ['nectar_piton', 0.42, 1, 1],
                ['lame_obsidienne', 0.26, 1, 1],
            ],
            'lezard_obsidienne', 'scolopendre_basalte' => [
                ['ecaille_obsidienne', 0.90, 1, 2],
                ['nectar_piton', 0.45, 1, 2],
                ['lame_obsidienne', 0.30, 1, 1],
            ],
            'petrel_barau', 'petrel_noir' => [
                ['plume_petrel', 0.90, 1, 2],
                ['nectar_piton', 0.50, 1, 2],
                ['lame_obsidienne', 0.25, 1, 1],
                ['couronne_cimes', 0.20, 1, 1],
            ],
            'papillon_salazie' => [
                ['aile_papillon', 0.90, 1, 2],
                ['nectar_piton', 0.50, 1, 2],
                ['couronne_cimes', 0.24, 1, 1],
            ],
            'colosse_nuages' => [
                ['ecaille_obsidienne', 1.0, 2, 3],
                ['nectar_piton', 0.85, 1, 2],
                ['plastron_nuages', 0.65, 1, 1],
                ['couronne_cimes', 0.65, 1, 1],
            ],
            'roussette_bourbon' => [
                ['croc_roussette', 0.90, 1, 2],
                ['nectar_piton', 0.55, 1, 2],
                ['plastron_nuages', 0.20, 1, 1],
            ],
            'ibis_reunion' => [
                ['plume_solitaire', 0.90, 1, 2],
                ['nectar_piton', 0.60, 1, 2],
                ['couronne_cimes', 0.25, 1, 1],
            ],
            'molosse_magma', 'chouette_bourbon' => [
                ['cendre_dolomieu', 0.85, 1, 2],
                ['nectar_piton', 0.65, 1, 2],
                ['lame_obsidienne', 0.25, 1, 1],
                ['couronne_cimes', 0.22, 1, 1],
            ],
            'seigneur_dolomieu' => [
                ['cendre_dolomieu', 1.0, 2, 4],
                ['nectar_piton', 1.0, 2, 3],
                ['tranchant_dolomieu', 1.0, 1, 1],
                ['coeur_magma', 0.50, 1, 1],
            ],
            default => [
                ['pince_crabe', 0.75, 1, 1],
                ['tisane_soin', 0.25, 1, 1],
            ],
        };
    }

    /**
     * Returns a 100% guaranteed World Boss LEGENDARY item code with smart class affinity
     * so Tanks, DPS, and Healers all receive exciting endgame gear when defeating a World Boss.
     */
    public function guaranteedLegendaryForWorldBoss(CharacterClass $class): string
    {
        $pool = match ($class) {
            CharacterClass::TANK => ['egide_fournaise', 'marteau_basalte', 'coeur_magma', 'couronne_dolomieu'],
            CharacterClass::DPS => ['tranchant_dolomieu', 'coeur_magma', 'couronne_dolomieu', 'egide_fournaise'],
            CharacterClass::HEAL => ['sceptre_piton', 'couronne_dolomieu', 'coeur_magma', 'egide_fournaise'],
        };

        return $pool[mt_rand(0, count($pool) - 1)];
    }
}
