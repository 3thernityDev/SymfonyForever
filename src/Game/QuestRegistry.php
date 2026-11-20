<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Progressive 10-quest main storyline guiding players from Level 1 (Saint-Gilles Lagoon)
 * to Level 60+ (Piton de la Fournaise / Seigneur du Dolomieu Niv. 65), supplemented by
 * local town quests for every NPC across all 26 villages of La Réunion in their respective zone.
 */
final class QuestRegistry
{
    /** @var array<string, QuestDefinition> */
    private array $quests = [];

    public function __construct()
    {
        $definitions = [
            new QuestDefinition(
                code: 'q1_lagon_crabs',
                order: 1,
                title: 'Nettoyage du Lagon',
                description: 'Les Crabes de Récif envahissent la plage de Saint-Gilles ! Élimine-en 3 près du camp pour te faire la main au combat (Tab pour cibler, touches 1-4 pour lancer tes sorts).',
                completionText: 'Bien joué ! Tu maîtrises déjà tes premières compétences. Pense à vérifier ta fiche de stats (touche P) quand tu montes de niveau !',
                giverNpcCode: 'gramoun_tijean',
                turnInNpcCode: 'gramoun_tijean',
                minLevel: 1,
                recommendedLevel: 1,
                prerequisiteQuestCode: null,
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'crabe_recif',
                targetLabel: 'Crabes de Récif vaincus',
                targetCount: 3,
                guideX: -1995.0,
                guideZ: -305.0,
                rewardXp: 95,
            ),
            new QuestDefinition(
                code: 'q2_lagon_tangues',
                order: 2,
                title: 'Les Tangues des Filaos',
                description: 'Les Tangues Épineux se rapprochent des cases créoles à l\'est du camp. Va en vaincre 4 pour sécuriser la côte Ouest et atteindre le niveau 3.',
                completionText: 'Superbe travail ! Tu es désormais prêt à quitter le calme du Lagon pour explorer les hauts de l\'île.',
                giverNpcCode: 'gramoun_tijean',
                turnInNpcCode: 'gramoun_tijean',
                minLevel: 1,
                recommendedLevel: 2,
                prerequisiteQuestCode: 'q1_lagon_crabs',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'tangue_sauvage',
                targetLabel: 'Tangues Épineux vaincus',
                targetCount: 4,
                guideX: -1890.0,
                guideZ: -320.0,
                rewardXp: 280,
            ),
            new QuestDefinition(
                code: 'q3_route_salazie',
                order: 3,
                title: 'Cap vers le Cirque de Salazie',
                description: 'Sœur Rosalie a besoin d\'aide dans le Cirque de Salazie au Nord-Est ! Suis les sentiers vers le nord-est (ou utilise le Relais des Cirques sous la mini-carte) et rejoins-la.',
                completionText: 'Merci d\'avoir fait le voyage jusqu\'à Salazie ! Ton aide tombe à pic.',
                giverNpcCode: 'gramoun_tijean',
                turnInNpcCode: 'soeur_rosalie',
                minLevel: 2,
                recommendedLevel: 10,
                prerequisiteQuestCode: 'q2_lagon_tangues',
                objectiveType: QuestDefinition::TYPE_REACH_ZONE,
                targetCode: 'salazie',
                targetLabel: 'Rejoindre le Cirque de Salazie',
                targetCount: 1,
                guideX: 329.5,
                guideZ: -1007.0,
                rewardXp: 180,
            ),
            new QuestDefinition(
                code: 'q4_salazie_wilds',
                order: 4,
                title: 'Chasse dans la Brume',
                description: 'Des Sangliers de Salazie chargent nos voyageurs près des cascades. Élimine 4 Sangliers de Salazie pour libérer le passage vers Mafate.',
                completionText: 'Les cascades respirent enfin ! Tu devrais maintenant te rendre dans le Cirque de Mafate, à l\'ouest, pour parler à Ti-Coq le Guide.',
                giverNpcCode: 'soeur_rosalie',
                turnInNpcCode: 'soeur_rosalie',
                minLevel: 10,
                recommendedLevel: 13,
                prerequisiteQuestCode: 'q3_route_salazie',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'sanglier_salazie',
                targetLabel: 'Sangliers de Salazie vaincus',
                targetCount: 4,
                guideX: 340.0,
                guideZ: -1095.0,
                rewardXp: 2600,
            ),
            new QuestDefinition(
                code: 'q5_mafate_golems',
                order: 5,
                title: 'Les Remparts de Mafate',
                description: 'Les Golems de Schiste s\'éveillent dans le Cirque de Mafate. Brise 4 Golems de Schiste pour protéger nos îlets isolés.',
                completionText: 'Impressionnant ! Tu deviens une légende des cirques. Cap au sud désormais : Maître Thermalis t\'attend à Cilaos.',
                giverNpcCode: 'ticoq_guide',
                turnInNpcCode: 'ticoq_guide',
                minLevel: 18,
                recommendedLevel: 25,
                prerequisiteQuestCode: 'q4_salazie_wilds',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'golem_schiste',
                targetLabel: 'Golems de Schiste vaincus',
                targetCount: 4,
                guideX: -910.0,
                guideZ: -870.0,
                rewardXp: 7500,
                rewardStatPoints: 1,
            ),
            new QuestDefinition(
                code: 'q6_cilaos_stags',
                order: 6,
                title: 'Fureur à Cilaos',
                description: 'Les Cerfs de Java Enragés sèment la panique autour des thermes de Cilaos. Maîtrise 4 d\'entre eux afin de rouvrir les routes vers le Sud Sauvage.',
                completionText: 'Cilaos te remercie ! Tu as atteint la puissance pour affronter le Sud Sauvage : descends parler au Capitaine Basalte.',
                giverNpcCode: 'maitre_thermalis',
                turnInNpcCode: 'maitre_thermalis',
                minLevel: 27,
                recommendedLevel: 31,
                prerequisiteQuestCode: 'q5_mafate_golems',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'cerf_java',
                targetLabel: 'Cerfs de Java Enragés vaincus',
                targetCount: 4,
                guideX: -525.0,
                guideZ: 540.0,
                rewardXp: 12500,
            ),
            new QuestDefinition(
                code: 'q7_sud_sauvage',
                order: 7,
                title: 'Écailles du Grand Brûlé',
                description: 'Les Lézards d\'Obsidienne ont pris possession des falaises basaltiques du Sud Sauvage. Terrasse 4 Lézards d\'Obsidienne pour repousser l\'invasion.',
                completionText: 'Tu as dompté le Sud Sauvage ! Rejoins maintenant l\'Éclaireuse Maïdo sur les pentes du Piton des Neiges.',
                giverNpcCode: 'capitaine_basalte',
                turnInNpcCode: 'capitaine_basalte',
                minLevel: 36,
                recommendedLevel: 42,
                prerequisiteQuestCode: 'q6_cilaos_stags',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'lezard_obsidienne',
                targetLabel: 'Lézards d\'Obsidienne vaincus',
                targetCount: 4,
                guideX: 665.0,
                guideZ: 1790.0,
                rewardXp: 20500,
            ),
            new QuestDefinition(
                code: 'q8_piton_neiges',
                order: 8,
                title: 'Le Toit de l\'Océan Indien',
                description: 'Le Colosse des Nuages (Élite Niv. 54) règne au sommet du Piton des Neiges à 3 070 m. Gravis le pic et triomphe de ce géant (un groupe Tank/DPS/Heal est recommandé) !',
                completionText: 'Tu as conquis le Piton des Neiges ! L\'ultime épreuve t\'attend désormais à l\'Est : parle au Vulcanologue Piton sur la Plaine des Sables.',
                giverNpcCode: 'eclaireuse_maido',
                turnInNpcCode: 'eclaireuse_maido',
                minLevel: 45,
                recommendedLevel: 54,
                prerequisiteQuestCode: 'q7_sud_sauvage',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'colosse_nuages',
                targetLabel: 'Colosse des Nuages vaincu',
                targetCount: 1,
                guideX: -262.5,
                guideZ: -227.5,
                rewardXp: 34000,
                rewardStatPoints: 2,
            ),
            new QuestDefinition(
                code: 'q9_plaine_sables',
                order: 9,
                title: 'Traversée de la Plaine des Sables',
                description: 'Avant d\'approcher le Cratère Dolomieu, il faut nettoyer l\'accès : élimine 4 Molosses de Magma qui rôdent sur la Plaine des Sables.',
                completionText: 'Le chemin vers le cratère est ouvert ! Rassemble tes alliés pour le combat final.',
                giverNpcCode: 'vulcanologue_piton',
                turnInNpcCode: 'vulcanologue_piton',
                minLevel: 53,
                recommendedLevel: 59,
                prerequisiteQuestCode: 'q8_piton_neiges',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'molosse_magma',
                targetLabel: 'Molosses de Magma vaincus',
                targetCount: 4,
                guideX: 1180.0,
                guideZ: 845.0,
                rewardXp: 46000,
            ),
            new QuestDefinition(
                code: 'q10_seigneur_dolomieu',
                order: 10,
                title: 'Le Cœur du Dolomieu (Raid 10 Joueurs)',
                description: 'Le Seigneur du Dolomieu (World Boss · Niv. 65 · 440 000 PV) est impossible à vaincre seul ! Alliez-vous jusqu\'à 10 joueurs (Tanks, Healers et DPS) au Cratère Dolomieu pour briser sa Carapace Magmatique et obtenir à coup sûr un objet LÉGENDAIRE chacun.',
                completionText: 'Victoire légendaire ! Toute l\'île de La Réunion chante ton nom, champion de SymfonyForever !',
                giverNpcCode: 'vulcanologue_piton',
                turnInNpcCode: 'vulcanologue_piton',
                minLevel: 58,
                recommendedLevel: 60,
                prerequisiteQuestCode: 'q9_plaine_sables',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'seigneur_dolomieu',
                targetLabel: 'Seigneur du Dolomieu vaincu',
                targetCount: 1,
                guideX: 1540.0,
                guideZ: 962.5,
                rewardXp: 120000,
                rewardStatPoints: 3,
            ),
        ];

        foreach ($definitions as $quest) {
            $this->quests[$quest->code] = $quest;
        }

        $this->registerVillageZoneQuests();
    }

    /**
     * Generates zone-matched quests for every village NPC across the 26 towns & villages of La Réunion.
     */
    private function registerVillageZoneQuests(): void
    {
        $zoneFaunaSpecs = [
            'lagon_ouest' => [
                ['crabe_recif', 'Crabes de Récif vaincus', 'Protection des Plages', 1, 2, 3, 140, -1975.0, -345.0],
                ['tangue_sauvage', 'Tangues Épineux vaincus', 'Veille sous les Filaos', 1, 3, 3, 250, -1890.0, -310.0],
                ['guepe_cotiere', 'Guêpes des Filaos vaincues', 'Essaims de la Côte Ouest', 2, 5, 3, 420, -1790.0, -265.0],
                ['endormi_bourbon', 'Endormis de Bourbon vaincus', 'Caméléons des Ravines', 3, 7, 3, 620, -1740.0, -420.0],
                ['paille_en_queue', 'Pailles-en-Queue Furieux vaincus', 'Ailes Blanches des Falaises', 4, 9, 3, 840, -1760.0, -140.0],
            ],
            'salazie' => [
                ['tec_tec_foret', 'Tec-Tec des Cascades vaincus', 'Plumes de la Ravine', 10, 11, 3, 1850, 255.0, -920.0],
                ['sanglier_salazie', 'Sangliers de Salazie vaincus', 'Défense des Chouchouteraies', 10, 13, 3, 2400, 390.0, -1080.0],
                ['zoizo_lunettes', 'Oiseaux Blancs de Bourbon vaincus', 'Nuées de Bélouve', 11, 15, 3, 3100, 435.0, -1010.0],
                ['papangue_feroce', 'Papangues Féroces vaincus', 'Rapaces du Cirque Vert', 12, 17, 3, 3850, 190.0, -860.0],
            ],
            'mafate' => [
                ['tuit_tuit', 'Tuit-Tuit des Remparts vaincus', 'Échos de la Rivière des Galets', 18, 19, 3, 5200, -965.0, -790.0],
                ['gecko_bourbon', 'Geckos Verts de Bourbon vaincus', 'Sentiers des Orangers', 18, 22, 3, 6300, -820.0, -750.0],
                ['golem_schiste', 'Golems de Schiste vaincus', 'Éboulis des Îlets', 19, 25, 3, 7300, -910.0, -870.0],
                ['bibe_mafate', 'Bibes Géantes de Mafate vaincues', 'Toiles des Ravines', 20, 27, 3, 8500, -1020.0, -770.0],
            ],
            'cilaos' => [
                ['oiseau_vert_cilaos', 'Oiseaux Lunettes Verts vaincus', 'Vendanges des Hauts', 27, 29, 3, 10200, -560.0, 470.0],
                ['cerf_java', 'Cerfs de Java Enragés vaincus', 'Brame sur le Plateau', 27, 31, 3, 11800, -525.0, 540.0],
                ['merle_peyi', 'Merles Péi des Thermes vaincus', 'Gardiens des Lentilles', 28, 33, 3, 13400, -365.0, 440.0],
                ['gardien_cilaos', 'Sentinelles des Thermes vaincues', 'Veilleurs du Dimitile', 29, 36, 3, 15200, -495.0, 380.0],
            ],
            'sud_sauvage' => [
                ['gecko_manapany', 'Geckos Verts de Manapany vaincus', 'Falaise de Manapany', 36, 38, 3, 17200, 465.0, 1650.0],
                ['tortue_bourbon', 'Tortues Géantes de Bourbon vaincues', 'Carapaces de Basalte', 36, 40, 3, 19000, 595.0, 1785.0],
                ['lezard_obsidienne', 'Lézards d\'Obsidienne vaincus', 'Coulées du Grand Sud', 37, 42, 3, 20800, 665.0, 1790.0],
                ['scolopendre_basalte', 'Scolopendres du Grand Brûlé vaincus', 'Venin sous les Vacoas', 38, 45, 3, 23200, 765.0, 1735.0],
            ],
            'piton_neiges' => [
                ['petrel_barau', 'Pétrels de Barau vaincus', 'Cimes du Coteau Kerveguen', 45, 48, 3, 27500, -325.0, -85.0],
                ['papillon_salazie', 'Papillons La Pâture vaincus', 'Brumes de Caverne Dufour', 46, 52, 3, 31000, -410.0, -205.0],
            ],
            'fournaise' => [
                ['roussette_bourbon', 'Roussettes Noires de Bourbon vaincues', 'Vols sur l\'Enclos', 53, 55, 3, 38000, 990.0, 790.0],
                ['ibis_reunion', 'Solitaires de La Réunion vaincus', 'Vestiges Volcaniques', 53, 57, 3, 41500, 1110.0, 745.0],
                ['molosse_magma', 'Molosses de Magma vaincus', 'Sentinelles des Scories', 54, 59, 3, 45000, 1180.0, 845.0],
                ['chouette_bourbon', 'Petits-Ducs de Bourbon vaincus', 'Gardiens de Sainte-Rose', 55, 60, 3, 48500, 1320.0, 935.0],
            ],
        ];

        $villagesByCode = [];
        foreach (WorldCatalog::villages() as $v) {
            $villagesByCode[$v['code']] = $v;
        }
        $zonesMeta = WorldCatalog::zones();

        $npcRegistry = new NpcRegistry();
        $order = 101;
        $specCursorByZone = [];

        foreach ($npcRegistry->all() as $npc) {
            // Primary camp NPCs already have main storyline quests, but ensure every secondary town NPC has its own local zone quest
            if (!str_starts_with($npc->code, 'npc_')) {
                continue;
            }

            $zCode = $npc->zoneCode;
            $specs = $zoneFaunaSpecs[$zCode] ?? $zoneFaunaSpecs['lagon_ouest'];
            $cursor = $specCursorByZone[$zCode] ?? 0;
            $specCursorByZone[$zCode] = $cursor + 1;

            [$targetCode, $targetLabel, $actionPrefix, $minLvl, $recLvl, $count, $xp, $gx, $gz] = $specs[$cursor % count($specs)];
            $village = $villagesByCode[$npc->villageCode] ?? null;
            $villageName = $village['name'] ?? $npc->villageCode;
            $zoneName = $zonesMeta[$zCode]['name'] ?? $zCode;

            $qCode = 'vq_'.$npc->code;
            $this->quests[$qCode] = new QuestDefinition(
                code: $qCode,
                order: $order++,
                title: sprintf('%s à %s', $actionPrefix, $villageName),
                description: sprintf(
                    'Les habitants de %s ont besoin d\'aide dans la région de %s ! Élimine %d créatures (%s) autour de notre village puis reviens faire ton rapport.',
                    $villageName,
                    $zoneName,
                    $count,
                    $targetLabel,
                ),
                completionText: sprintf(
                    'Grand merci au nom de tout le village de %s ! Notre territoire dans %s est beaucoup plus sûr grâce à toi.',
                    $villageName,
                    $zoneName,
                ),
                giverNpcCode: $npc->code,
                turnInNpcCode: $npc->code,
                minLevel: $minLvl,
                recommendedLevel: $recLvl,
                prerequisiteQuestCode: null,
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: $targetCode,
                targetLabel: $targetLabel,
                targetCount: $count,
                guideX: $gx,
                guideZ: $gz,
                rewardXp: $xp,
            );
        }
    }

    public function get(string $code): ?QuestDefinition
    {
        return $this->quests[$code] ?? null;
    }

    /**
     * @return list<QuestDefinition>
     */
    public function all(): array
    {
        $list = array_values($this->quests);
        usort($list, static fn (QuestDefinition $a, QuestDefinition $b): int => $a->order <=> $b->order);

        return $list;
    }

    /**
     * @return list<QuestDefinition>
     */
    public function forNpc(string $npcCode): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (QuestDefinition $q): bool => $q->giverNpcCode === $npcCode || $q->turnInNpcCode === $npcCode,
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toManifestArray(): array
    {
        $out = [];
        foreach ($this->all() as $q) {
            $out[$q->code] = $q->toArray();
        }

        return $out;
    }
}
