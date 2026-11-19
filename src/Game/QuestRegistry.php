<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Progressive 10-quest storyline guiding players from Level 1 (Saint-Gilles Lagoon)
 * all the way to Level 10+ (Piton de la Fournaise / Seigneur du Dolomieu).
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
                completionText: 'Bien joué ! Tu maîtrises déjà tes premières compétences. Pense à vérifier ta fiche de stats (touche C) quand tu montes de niveau !',
                giverNpcCode: 'gramoun_tijean',
                turnInNpcCode: 'gramoun_tijean',
                minLevel: 1,
                recommendedLevel: 1,
                prerequisiteQuestCode: null,
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'crabe_recif',
                targetLabel: 'Crabes de Récif vaincus',
                targetCount: 3,
                guideX: -545.0,
                guideZ: -80.0,
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
                guideX: -495.0,
                guideZ: -95.0,
                rewardXp: 230,
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
                recommendedLevel: 3,
                prerequisiteQuestCode: 'q2_lagon_tangues',
                objectiveType: QuestDefinition::TYPE_REACH_ZONE,
                targetCode: 'salazie',
                targetLabel: 'Rejoindre le Cirque de Salazie',
                targetCount: 1,
                guideX: 92.0,
                guideZ: -282.0,
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
                minLevel: 3,
                recommendedLevel: 4,
                prerequisiteQuestCode: 'q3_route_salazie',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'sanglier_salazie',
                targetLabel: 'Sangliers de Salazie vaincus',
                targetCount: 4,
                guideX: 95.0,
                guideZ: -305.0,
                rewardXp: 440,
            ),
            new QuestDefinition(
                code: 'q5_mafate_golems',
                order: 5,
                title: 'Les Remparts de Mafate',
                description: 'Les Golems de Schiste s\'éveillent dans le Cirque de Mafate. Brise 4 Golems de Schiste pour protéger nos îlets isolés.',
                completionText: 'Impressionnant ! Tu deviens une légende des cirques. Cap au sud désormais : Maître Thermalis t\'attend à Cilaos.',
                giverNpcCode: 'ticoq_guide',
                turnInNpcCode: 'ticoq_guide',
                minLevel: 4,
                recommendedLevel: 5,
                prerequisiteQuestCode: 'q4_salazie_wilds',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'golem_schiste',
                targetLabel: 'Golems de Schiste vaincus',
                targetCount: 4,
                guideX: -255.0,
                guideZ: -245.0,
                rewardXp: 680,
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
                minLevel: 5,
                recommendedLevel: 6,
                prerequisiteQuestCode: 'q5_mafate_golems',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'cerf_java',
                targetLabel: 'Cerfs de Java Enragés vaincus',
                targetCount: 4,
                guideX: -150.0,
                guideZ: 155.0,
                rewardXp: 920,
            ),
            new QuestDefinition(
                code: 'q7_sud_sauvage',
                order: 7,
                title: 'Écailles du Grand Brûlé',
                description: 'Les Lézards d\'Obsidienne ont pris possession des falaises basaltiques du Sud Sauvage. Terrasse 4 Lézards d\'Obsidienne pour repousser l\'invasion.',
                completionText: 'Tu as dompté le Sud Sauvage ! Rejoins maintenant l\'Éclaireuse Maïdo sur les pentes du Piton des Neiges.',
                giverNpcCode: 'capitaine_basalte',
                turnInNpcCode: 'capitaine_basalte',
                minLevel: 6,
                recommendedLevel: 7,
                prerequisiteQuestCode: 'q6_cilaos_stags',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'lezard_obsidienne',
                targetLabel: 'Lézards d\'Obsidienne vaincus',
                targetCount: 4,
                guideX: 195.0,
                guideZ: 510.0,
                rewardXp: 1220,
            ),
            new QuestDefinition(
                code: 'q8_piton_neiges',
                order: 8,
                title: 'Le Toit de l\'Océan Indien',
                description: 'Le Colosse des Nuages (Élite) règne au sommet du Piton des Neiges à 3 070 m. Gravis le pic et triomphe de ce géant (un groupe Tank/DPS/Heal est recommandé) !',
                completionText: 'Tu as conquis le Piton des Neiges ! L\'ultime épreuve t\'attend désormais à l\'Est : parle au Vulcanologue Piton sur la Plaine des Sables.',
                giverNpcCode: 'eclaireuse_maido',
                turnInNpcCode: 'eclaireuse_maido',
                minLevel: 7,
                recommendedLevel: 8,
                prerequisiteQuestCode: 'q7_sud_sauvage',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'colosse_nuages',
                targetLabel: 'Colosse des Nuages vaincu',
                targetCount: 1,
                guideX: -75.0,
                guideZ: -65.0,
                rewardXp: 1650,
                rewardStatPoints: 1,
            ),
            new QuestDefinition(
                code: 'q9_plaine_sables',
                order: 9,
                title: 'Traversée de la Plaine des Sables',
                description: 'Avant d\'approcher le Cratère Dolomieu, il faut nettoyer l\'accès : élimine 4 Molosses de Magma qui rôdent sur la Plaine des Sables.',
                completionText: 'Le chemin vers le cratère est ouvert ! Rassemble tes alliés pour le combat final.',
                giverNpcCode: 'vulcanologue_piton',
                turnInNpcCode: 'vulcanologue_piton',
                minLevel: 8,
                recommendedLevel: 9,
                prerequisiteQuestCode: 'q8_piton_neiges',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'molosse_magma',
                targetLabel: 'Molosses de Magma vaincus',
                targetCount: 4,
                guideX: 350.0,
                guideZ: 250.0,
                rewardXp: 2100,
            ),
            new QuestDefinition(
                code: 'q10_seigneur_dolomieu',
                order: 10,
                title: 'Le Cœur du Dolomieu',
                description: 'Le Seigneur du Dolomieu (World Boss) menace de plonger toute La Réunion sous la lave ! Forme un groupe Tank + DPS + Heal et terrasse-le au cœur du cratère.',
                completionText: 'Victoire légendaire ! Toute l\'île de La Réunion chante ton nom, champion de SymfonyForever !',
                giverNpcCode: 'vulcanologue_piton',
                turnInNpcCode: 'vulcanologue_piton',
                minLevel: 9,
                recommendedLevel: 10,
                prerequisiteQuestCode: 'q9_plaine_sables',
                objectiveType: QuestDefinition::TYPE_KILL_MONSTER,
                targetCode: 'seigneur_dolomieu',
                targetLabel: 'Seigneur du Dolomieu vaincu',
                targetCount: 1,
                guideX: 440.0,
                guideZ: 275.0,
                rewardXp: 3500,
                rewardStatPoints: 2,
            ),
        ];

        foreach ($definitions as $quest) {
            $this->quests[$quest->code] = $quest;
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
