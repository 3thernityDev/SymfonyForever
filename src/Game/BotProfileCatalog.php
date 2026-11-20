<?php

declare(strict_types=1);

namespace App\Game;

use App\Entity\ChatMessage;

/**
 * Curated Réunionnais gamer pseudonyms, zone/class distribution slots, and French chat library
 * for autonomous AI player-bots inhabiting La Réunion.
 */
final class BotProfileCatalog
{
    /**
     * 48 accent-free Réunionnais pseudonyms (easy to read and type to send party invites).
     *
     * @return list<string>
     */
    public static function pseudonyms(): array
    {
        return [
            'TiKaf974',
            'Zamalou',
            'DodoVolcan',
            'PitonBoy',
            'KreolBlade',
            'TiMarmay',
            'Tizaner974',
            'RougailSaucis',
            'CariPoulet',
            'MafateRunner',
            'CilaosZen',
            'SalazieMist',
            'LagonSurfer',
            'TiZourit',
            'KosaLaFe',
            'BoucanRider',
            'Fournaise974',
            'TangueFury',
            'PapangueSky',
            'SamoussaPro',
            'BouchonKing',
            'RhumArrange',
            'Goyavier974',
            'LetchiPower',
            'MarmitFeu',
            'TiBato974',
            'SaintGillesX',
            'SaintPierrot',
            'Tamponnais',
            'TiMaloya',
            'SegaWarrior',
            'KafMafate',
            'TiZorey974',
            'PitonNeiges',
            'GrandBrule',
            'Ermitage974',
            'TiCameleon',
            'Endormi974',
            'PimentZoiseau',
            'Dakatine974',
            'TiGratte',
            'Vavangue',
            'RavineBleue',
            'Takamaka974',
            'TiChouchou',
            'FlamboyantX',
            'BassinBleu',
            'Manapany974',
        ];
    }

    /**
     * 10 balanced archetype slots across all zones (4 in starter hub Lagon de l'Ouest).
     *
     * @return list<array{zone: string, minLevel: int, maxLevel: int, class: CharacterClass}>
     */
    public static function rosterSlots(): array
    {
        return [
            ['zone' => 'lagon_ouest', 'minLevel' => 1, 'maxLevel' => 3, 'class' => CharacterClass::TANK],
            ['zone' => 'lagon_ouest', 'minLevel' => 2, 'maxLevel' => 5, 'class' => CharacterClass::DPS],
            ['zone' => 'lagon_ouest', 'minLevel' => 4, 'maxLevel' => 7, 'class' => CharacterClass::HEAL],
            ['zone' => 'lagon_ouest', 'minLevel' => 6, 'maxLevel' => 9, 'class' => CharacterClass::DPS],
            ['zone' => 'salazie', 'minLevel' => 11, 'maxLevel' => 16, 'class' => CharacterClass::TANK],
            ['zone' => 'mafate', 'minLevel' => 20, 'maxLevel' => 25, 'class' => CharacterClass::DPS],
            ['zone' => 'cilaos', 'minLevel' => 29, 'maxLevel' => 34, 'class' => CharacterClass::HEAL],
            ['zone' => 'sud_sauvage', 'minLevel' => 38, 'maxLevel' => 43, 'class' => CharacterClass::DPS],
            ['zone' => 'piton_neiges', 'minLevel' => 47, 'maxLevel' => 52, 'class' => CharacterClass::TANK],
            ['zone' => 'fournaise', 'minLevel' => 56, 'maxLevel' => 60, 'class' => CharacterClass::HEAL],
        ];
    }

    /**
     * Natural island leveling progression order for solo bots.
     *
     * @return list<string>
     */
    public static function zoneProgressionOrder(): array
    {
        return [
            'lagon_ouest',
            'salazie',
            'mafate',
            'cilaos',
            'sud_sauvage',
            'piton_neiges',
            'fournaise',
        ];
    }

    /**
     * @return list<string>
     */
    public static function spontaneousGlobalMessages(): array
    {
        return [
            'Oté ! Quelqu\'un veut grouper pour faire les quêtes ?',
            'Le coucher de soleil sur le Lagon est vraiment magnifique.',
            'Attention aux Molosses à la Plaine des Sables, ils tapent fort !',
            'Qui est chaud pour monter un gros groupe Dolomieu plus tard ?',
            'N\'oubliez pas d\'améliorer vos sorts tous les 3 niveaux, ça change tout !',
            'Je cherche un Healer ou un DPS motivé pour avancer dans les cirques.',
            'Ça xp tranquille aujourd\'hui sur l\'île, bonne chasse à tous !',
            'Salut la team ! Besoin d\'un coup de main quelque part ?',
            'Gaffe au Seigneur du Dolomieu, il pardonne pas si on est pas niv. 60 en Tank !',
            'J\'adore l\'ambiance entre Saint-Gilles et Cilaos, bon jeu à vous !',
            'Si quelqu\'un veut quêter ensemble, hésitez pas à m\'inviter !',
            'Pensez à réparer et acheter des tisanes au campement avant de monter vers le Piton.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function spontaneousZoneMessages(string $zoneCode): array
    {
        return match ($zoneCode) {
            'lagon_ouest' => [
                'Les Crabes de Récif sont parfaits pour chauffer l\'auto-attaque !',
                'Salut Saint-Gilles ! Quelqu\'un vient de débarquer sur le lagon ?',
                'J\'ai croisé un Endormi de Bourbon près des filaos !',
                'Les Paille-en-Queue tournent déjà au-dessus de l\'Ermitage.',
            ],
            'salazie' => [
                'Les Tec-Tec et les sangliers de Salazie donnent bien de l\'XP.',
                'Un Oiseau Blanc de Bourbon vient de passer dans la brume !',
                'Quelqu\'un pour chasser les Papangues ensemble ?',
            ],
            'mafate' => [
                'Les Tuit-Tuit chantent près des remparts mais les Bibes Géantes mordent fort !',
                'J\'ai aperçu un Gecko Vert de Bourbon sur les rochers.',
                'Faites gaffe aux Golems de Schiste, ils ont pas mal d\'armure.',
            ],
            'cilaos' => [
                'Rien de tel qu\'une petite halte aux thermes de Cilaos.',
                'Les Cerfs de Java et les Merles Péi bougent vite par ici !',
                'Je garde mon mana prêt pour soigner si besoin.',
            ],
            'sud_sauvage' => [
                'Les Tortues Géantes de Bourbon encaissent vraiment dur !',
                'Attention aux Scolopendres et aux Geckos de Manapany sur la côte.',
            ],
            'piton_neiges' => [
                'Les Pétrels de Barau et Papillons La Pâture volent haut autour du sommet !',
                'Attention au Colosse des Nuages, c\'est un élite costaud.',
            ],
            'fournaise' => [
                'Entre les Roussettes Noires et les Solitaires de La Réunion, ça tape fort !',
                'Ne tentez pas le Seigneur du Dolomieu (Niv. 65) en solo, c\'est mortel direct !',
                'Prêt pour le raid World Boss dès qu\'on est assez nombreux !',
            ],
            default => [
                'Bonne chasse par ici !',
                'Salut tout le monde, comment ça avance ?',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function partyJoinGreetings(): array
    {
        return [
            'Salut ! Merci pour l\'invit, j\'arrive !',
            'Oté la team ! Prêt à casser du monstre avec vous !',
            'Salut ! Content de grouper avec vous !',
            'Coucou le groupe ! Je vous rejoins tout de suite.',
            'Merci pour l\'invite ! En route !',
        ];
    }

    /**
     * @return list<string>
     */
    public static function levelUpMessages(int $newLevel): array
    {
        return [
            sprintf('Ding ! Niveau %d atteint !', $newLevel),
            sprintf('Alé ! Je passe niveau %d !', $newLevel),
            sprintf('Niveau %d validé ! On continue l\'aventure !', $newLevel),
        ];
    }

    /**
     * @return list<string>
     */
    public static function lowHpCallouts(): array
    {
        return [
            'Aïe, je suis bas en PV, prudence !',
            'Attention je prends cher là, un petit soin serait top !',
            'Ouh là ça pique, je recule un poil !',
        ];
    }

    /**
     * Generates a contextual French reply to a human player chat message, or null if bots should stay silent.
     */
    public static function generateReply(
        string $incomingText,
        string $senderName,
        string $channel,
        string $zoneCode,
        CharacterClass $botClass,
        bool $directlyAddressed = false,
    ): ?string {
        $norm = self::normalize($incomingText);

        // 1. Greetings (salut, bonjour, coucou, oté, yo, ça va...)
        if (self::containsAny($norm, [
            'salut', 'slt', 'bonjour', 'bjr', 'coucou', 'cc', 'hello', 'hey', 'yo',
            'ote', 'kosa', 'ca va', 'comment i le', 'bonsoir', 'wesh', 're ', 're!',
        ])) {
            $options = [
                'Salut ! Comment i lé ?',
                sprintf('Salut %s ! Ça va super, et toi ?', $senderName),
                'Oté ! Salut, ça farm tranquille ?',
                'Coucou ! Si tu veux grouper hésite pas !',
                sprintf('Yo ! Salut %s ! Bonne chasse sur l\'île !', $senderName),
                'Salut ! Prêt pour l\'aventure ?',
            ];

            return $options[array_rand($options)];
        }

        // 2. Party / Raid / World Boss / Help / Role requests
        if (self::containsAny($norm, [
            'groupe', 'grp', 'group', 'invit', 'party', 'raid', 'boss', 'dolomieu',
            'fournaise', 'aide', 'help', 'tank', 'heal', 'dps', 'dispo', 'quelqu',
        ])) {
            if (str_contains($norm, 'dolomieu') || str_contains($norm, 'boss') || str_contains($norm, 'raid')) {
                $options = [
                    'Pour le Dolomieu faut un bon Tank niv. 60 et du Heal, je suis chaud !',
                    'Je suis partant pour le World Boss si on monte un gros groupe !',
                    sprintf('En tant que %s, je peux aider si on s\'organise bien !', $botClass->label()),
                ];

                return $options[array_rand($options)];
            }

            $options = [
                'Je suis chaud ! Envoie l\'invit si tu veux !',
                'Dispo pour grouper, invite-moi !',
                sprintf('Partant ! Je joue %s, invite quand tu veux.', $botClass->label()),
                'Avec plaisir ! Tu veux farmer dans quelle zone ?',
            ];

            return $options[array_rand($options)];
        }

        // 3. Thanks / Praise / GG
        if (self::containsAny($norm, ['merci', 'mrc', 'gg', 'bien joue', 'bravo', 'super', 'cool', 'top', 'nice'])) {
            $options = [
                'De rien ! Bonne chasse !',
                'GG ! Bien joué !',
                'Avec plaisir, on continue comme ça !',
                'Au top !',
            ];

            return $options[array_rand($options)];
        }

        // 4. Farewells
        if (self::containsAny($norm, ['bye', 'ciao', 'a+', 'au revoir', 'bonne nuit', 'tchao', 'deco'])) {
            $options = [
                'À plus ! Bonne soirée !',
                'Ciao, à bientôt sur La Réunion !',
                sprintf('Bonne continuation %s, à la prochaine !', $senderName),
            ];

            return $options[array_rand($options)];
        }

        // 5. Gameplay Questions & Tips
        if (self::containsAny($norm, ['?', 'ou ', 'xp', 'level', 'niv', 'loot', 'stuff', 'quete', 'legendaire', 'skill', 'sort'])) {
            $options = [
                'Au Lagon c\'est top pour débuter, ensuite direction Salazie ou Mafate !',
                'Pense à monter tes skills tous les 3 niveaux (touche P), ça booste vraiment les dégâts !',
                'Je farm tranquillement ici pour prendre quelques niveaux et de l\'équipement.',
                sprintf('Je suis dans le coin (%s), tu fais la quête principale ?', WorldCatalog::zones()[$zoneCode]['name'] ?? 'La Réunion'),
            ];

            return $options[array_rand($options)];
        }

        // 6. General fallback: guaranteed if directly pinged or in same Party; ~55% chance in public channels
        if (!$directlyAddressed && $channel !== ChatMessage::CHANNEL_PARTY && mt_rand(1, 100) > 55) {
            return null;
        }

        $fallbacks = [
            'Carrément !',
            'Grave, sympa comme coin ici !',
            'Haha ouais, fais gaffe aux mobs quand même !',
            'C\'est clair ! Tu farm quoi en ce moment ?',
            'Oté oui, bonne ambiance par ici !',
            'Je suis bien d\'accord avec toi !',
        ];

        return $fallbacks[array_rand($fallbacks)];
    }

    private static function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        return strtr($lower, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }

    /**
     * @param list<string> $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
