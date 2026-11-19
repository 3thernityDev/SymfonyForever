<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Character;
use App\Game\SkillRegistry;

final class LevelingService
{
    public const int MAX_LEVEL = 60;
    public const int FREE_STAT_POINTS_PER_LEVEL = 3;
    public const int SKILL_POINT_INTERVAL = 3;
    public const int MAX_SKILL_RANK = 5;
    public const float SKILL_RANK_POWER_BONUS = 0.22;

    private readonly SkillRegistry $skillRegistry;

    public function __construct(
        private readonly StatCalculator $statCalculator,
        ?SkillRegistry $skillRegistry = null,
    ) {
        $this->skillRegistry = $skillRegistry ?? new SkillRegistry();
    }

    public static function skillPowerMultiplier(int $rank): float
    {
        return 1.0 + max(0, $rank - 1) * self::SKILL_RANK_POWER_BONUS;
    }

    /**
     * Awards XP to a character, triggering one or more level-ups with automatic class stat growth.
     * Grants 1 free skill point every 3 levels (Lv 3, 6, 9, 12, 15, 18).
     *
     * @return array{leveledUp: bool, newLevel: int, levelsGained: int, skillPointsGained: int}
     */
    public function grantXp(Character $character, int $amount): array
    {
        if ($amount <= 0 || $character->getLevel() >= self::MAX_LEVEL || ($character->isBot() && $character->isDead())) {
            return ['leveledUp' => false, 'newLevel' => $character->getLevel(), 'levelsGained' => 0, 'skillPointsGained' => 0];
        }

        $character->setXp($character->getXp() + $amount);
        $levelsGained = 0;
        $skillPointsGained = 0;

        while ($character->getLevel() < self::MAX_LEVEL) {
            $needed = $this->statCalculator->xpForNextLevel($character->getLevel());
            if ($character->getXp() < $needed) {
                break;
            }

            $character->setXp($character->getXp() - $needed);
            $character->setLevel($character->getLevel() + 1);
            ++$levelsGained;

            if ($character->getLevel() % self::SKILL_POINT_INTERVAL === 0) {
                $character->setSkillPoints($character->getSkillPoints() + 1);
                ++$skillPointsGained;
            }

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
            'skillPointsGained' => $skillPointsGained,
        ];
    }

    /**
     * Spends 1 skill point earned every 3 levels to permanently rank up a class skill (Rank 1..5).
     *
     * @return array{ok: bool, error?: string, newRank?: int}
     */
    public function upgradeSkill(Character $character, string $skillCode): array
    {
        if ($character->getSkillPoints() <= 0) {
            return ['ok' => false, 'error' => 'Aucun point de compétence disponible (1 point gagné tous les 3 niveaux).'];
        }

        $skill = $this->skillRegistry->get($skillCode);
        if ($skill === null || $skill->characterClass !== $character->getCharacterClass()) {
            return ['ok' => false, 'error' => 'Compétence invalide pour votre classe.'];
        }

        if ($character->getLevel() < $skill->unlockLevel) {
            return ['ok' => false, 'error' => sprintf('Débloquez %s au niveau %d avant de l\'améliorer.', $skill->name, $skill->unlockLevel)];
        }

        $currentRank = $character->getSkillLevel($skillCode);
        if ($currentRank >= self::MAX_SKILL_RANK) {
            return ['ok' => false, 'error' => sprintf('%s est déjà au rang maximum (Rang %d).', $skill->name, self::MAX_SKILL_RANK)];
        }

        $newRank = $currentRank + 1;
        $character->setSkillLevel($skillCode, $newRank);
        $character->setSkillPoints($character->getSkillPoints() - 1);

        return ['ok' => true, 'newRank' => $newRank];
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
