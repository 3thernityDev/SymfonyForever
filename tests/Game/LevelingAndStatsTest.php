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
}
