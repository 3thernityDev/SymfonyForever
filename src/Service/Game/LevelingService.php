<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;

final class LevelingService
{
    public const int MAX_LEVEL = 20;
    public const int FREE_STAT_POINTS_PER_LEVEL = 3;

    public function __construct(
        private readonly StatCalculator $statCalculator,
    ) {
    }

    /**
     * Awards XP to a character, triggering one or more level-ups with automatic class stat growth.
     *
     * @return array{leveledUp: bool, newLevel: int, levelsGained: int}
     */
    public function grantXp(Character $character, int $amount): array
    {
        if ($amount <= 0 || $character->getLevel() >= self::MAX_LEVEL) {
            return ['leveledUp' => false, 'newLevel' => $character->getLevel(), 'levelsGained' => 0];
        }

        $character->setXp($character->getXp() + $amount);
        $levelsGained = 0;

        while ($character->getLevel() < self::MAX_LEVEL) {
            $needed = $this->statCalculator->xpForNextLevel($character->getLevel());
            if ($character->getXp() < $needed) {
                break;
            }

            $character->setXp($character->getXp() - $needed);
            $character->setLevel($character->getLevel() + 1);
            ++$levelsGained;

            $growth = $character->getCharacterClass()->levelGrowth();
            $character->setVitality($character->getVitality() + $growth['vitality']);
            $character->setStrength($character->getStrength() + $growth['strength']);
            $character->setIntelligence($character->getIntelligence() + $growth['intelligence']);
            $character->setDefense($character->getDefense() + $growth['defense']);
            $character->setSpirit($character->getSpirit() + $growth['spirit']);
            $character->setStatPoints($character->getStatPoints() + self::FREE_STAT_POINTS_PER_LEVEL);

            // Full HP/MP restore on level-up
            $stats = $this->statCalculator->compute($character);
            $character->setCurrentHp($stats['maxHp']);
            $character->setCurrentMp($stats['maxMp']);
            $character->setIsDead(false);
        }

        return [
            'leveledUp' => $levelsGained > 0,
            'newLevel' => $character->getLevel(),
            'levelsGained' => $levelsGained,
        ];
    }

    /**
     * Spends 1 free stat point on a chosen primary attribute.
     */
    public function allocateStat(Character $character, string $stat): bool
    {
        if ($character->getStatPoints() <= 0) {
            return false;
        }

        switch ($stat) {
            case 'vitality':
                $character->setVitality($character->getVitality() + 1);
                $character->setCurrentHp($character->getCurrentHp() + 12);
                break;
            case 'strength':
                $character->setStrength($character->getStrength() + 1);
                break;
            case 'intelligence':
                $character->setIntelligence($character->getIntelligence() + 1);
                break;
            case 'defense':
                $character->setDefense($character->getDefense() + 1);
                break;
            case 'spirit':
                $character->setSpirit($character->getSpirit() + 1);
                $character->setCurrentMp($character->getCurrentMp() + 10);
                break;
            default:
                return false;
        }

        $character->setStatPoints($character->getStatPoints() - 1);

        return true;
    }
}
