<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\Character;
use App\Entity\User;
use App\Game\CharacterClass;
use App\Service\Game\LevelingService;
use App\Service\Game\StatCalculator;
use PHPUnit\Framework\TestCase;

final class LevelingAndStatsTest extends TestCase
{
    private StatCalculator $calculator;
    private LevelingService $leveling;

    protected function setUp(): void
    {
        $this->calculator = new StatCalculator();
        $this->leveling = new LevelingService($this->calculator);
    }

    public function testClassArchetypesHaveDistinctStrengths(): void
    {
        $user = (new User())->setUsername('tester')->setEmail('t@reunion.re');
        $tank = new Character($user, 'Tanky', CharacterClass::TANK);
        $dps = new Character($user, 'Burner', CharacterClass::DPS);
        $heal = new Character($user, 'Tisane', CharacterClass::HEAL);

        $sTank = $this->calculator->compute($tank);
        $sDps = $this->calculator->compute($dps);
        $sHeal = $this->calculator->compute($heal);

        self::assertGreaterThan($sDps['maxHp'], $sTank['maxHp']);
        self::assertGreaterThan($sDps['damageReduction'], $sTank['damageReduction']);
        self::assertGreaterThan($sTank['attackPower'], $sDps['attackPower']);
        self::assertGreaterThan($sDps['spellPower'], $sHeal['spellPower']);
        self::assertGreaterThan($sTank['maxMp'], $sHeal['maxMp']);
    }

    public function testLevelUpIncreasesStatsGrantsFreePointsAndRestoresVitals(): void
    {
        $user = (new User())->setUsername('hero')->setEmail('h@reunion.re');
        $tank = new Character($user, 'Basalte', CharacterClass::TANK);
        $beforeVit = $tank->getVitality();
        $beforeDef = $tank->getDefense();
        $tank->setCurrentHp(20)->setCurrentMp(5);

        // Level 1 -> 2 requires 100 XP; Level 2 -> 3 requires floor(100 * 2^1.6) = 303 XP
        $res = $this->leveling->grantXp($tank, 420);

        self::assertTrue($res['leveledUp']);
        self::assertSame(3, $res['newLevel']);
        self::assertSame(2, $res['levelsGained']);
        self::assertSame(6, $tank->getStatPoints());
        self::assertSame($beforeVit + 8, $tank->getVitality());
        self::assertSame($beforeDef + 6, $tank->getDefense());

        $stats = $this->calculator->compute($tank);
        self::assertSame($stats['maxHp'], $tank->getCurrentHp());
        self::assertSame($stats['maxMp'], $tank->getCurrentMp());
    }

    public function testAllocateStatSpendsFreePointsAndBoostsDerivedStats(): void
    {
        $user = (new User())->setUsername('mage')->setEmail('m@reunion.re');
        $heal = new Character($user, 'Faham', CharacterClass::HEAL);
        $heal->setStatPoints(2);

        $beforeSp = $this->calculator->compute($heal)['spellPower'];
        self::assertTrue($this->leveling->allocateStat($heal, 'intelligence'));
        self::assertSame(1, $heal->getStatPoints());
        self::assertGreaterThan($beforeSp, $this->calculator->compute($heal)['spellPower']);

        self::assertFalse($this->leveling->allocateStat($heal, 'invalid_stat'));
        self::assertSame(1, $heal->getStatPoints());
    }

    public function testSkillPointsGrantedEveryThreeLevelsAndUpgradeCapsAtRankFive(): void
    {
        $user = (new User())->setUsername('skiller')->setEmail('sk@reunion.re');
        $dps = new Character($user, 'VolcanSkill', CharacterClass::DPS);

        // No skill points at Lv 1..2
        $xpLv2 = $this->calculator->xpForNextLevel(1);
        $this->leveling->grantXp($dps, $xpLv2);
        self::assertSame(2, $dps->getLevel());
        self::assertSame(0, $dps->getSkillPoints());

        $noPts = $this->leveling->upgradeSkill($dps, 'ember_strike');
        self::assertFalse($noPts['ok']);

        // Reach Lv 3 -> grants +1 skill point
        $xpLv3 = $this->calculator->xpForNextLevel(2);
        $res3 = $this->leveling->grantXp($dps, $xpLv3);
        self::assertSame(3, $dps->getLevel());
        self::assertSame(1, $res3['skillPointsGained']);
        self::assertSame(1, $dps->getSkillPoints());

        // Cannot upgrade volcanic_fury (unlocks at Lv 4) yet
        $lockedRes = $this->leveling->upgradeSkill($dps, 'volcanic_fury');
        self::assertFalse($lockedRes['ok']);
        self::assertSame(1, $dps->getSkillPoints());

        // Cannot upgrade another class skill
        $wrongCls = $this->leveling->upgradeSkill($dps, 'shield_bash');
        self::assertFalse($wrongCls['ok']);

        // Upgrade ember_strike Rank 1 -> Rank 2
        $upg1 = $this->leveling->upgradeSkill($dps, 'ember_strike');
        self::assertTrue($upg1['ok']);
        self::assertSame(2, $upg1['newRank']);
        self::assertSame(2, $dps->getSkillLevel('ember_strike'));
        self::assertSame(0, $dps->getSkillPoints());

        // Level up to Lv 9 directly -> grants points for Lv 6 & Lv 9 (+2 pts total)
        $neededTo9 = 0;
        for ($l = 3; $l < 9; ++$l) {
            $neededTo9 += $this->calculator->xpForNextLevel($l);
        }
        $res9 = $this->leveling->grantXp($dps, $neededTo9);
        self::assertSame(9, $dps->getLevel());
        self::assertSame(2, $res9['skillPointsGained']);
        self::assertSame(2, $dps->getSkillPoints());

        // Upgrade ember_strike to Rank 5 cap, then ensure Rank 6 is rejected
        $dps->setSkillPoints(4);
        self::assertSame(3, $this->leveling->upgradeSkill($dps, 'ember_strike')['newRank']);
        self::assertSame(4, $this->leveling->upgradeSkill($dps, 'ember_strike')['newRank']);
        self::assertSame(5, $this->leveling->upgradeSkill($dps, 'ember_strike')['newRank']);
        $maxCap = $this->leveling->upgradeSkill($dps, 'ember_strike');
        self::assertFalse($maxCap['ok']);
        self::assertSame(5, $dps->getSkillLevel('ember_strike'));
        self::assertSame(1, $dps->getSkillPoints());
    }
}
