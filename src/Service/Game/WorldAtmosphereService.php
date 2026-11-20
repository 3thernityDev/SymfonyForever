<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\WorldState;
use App\Game\WorldAtmosphere;
use App\Repository\WorldStateRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final class WorldAtmosphereService
{
    public function __construct(
        private readonly WorldStateRepository $worldStateRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *   timeOfDay: float,
     *   phase: string,
     *   isNight: bool,
     *   weather: string,
     *   weatherLabel: string,
     *   dayCycleSeconds: float,
     *   isTimeFrozen: bool,
     *   isTimeOverridden: bool,
     *   isWeatherOverridden: bool,
     *   weatherOverride: string|null
     * }
     */
    public function getCurrentAtmosphere(?float $now = null): array
    {
        $ts = $now ?? microtime(true);
        try {
            $state = $this->worldStateRepository->find(WorldState::GLOBAL_ID);
        } catch (TableNotFoundException) {
            $state = null;
        }

        return WorldAtmosphere::computeGlobal(
            $ts,
            $state?->getWeatherOverride(),
            $state?->getTimeOffsetHours() ?? 0.0,
            $state?->getFixedTimeOfDay(),
        );
    }

    /**
     * @return array{
     *   timeOfDay: float,
     *   phase: string,
     *   isNight: bool,
     *   weather: string,
     *   weatherLabel: string,
     *   dayCycleSeconds: float,
     *   isTimeFrozen: bool,
     *   isTimeOverridden: bool,
     *   isWeatherOverridden: bool,
     *   weatherOverride: string|null
     * }
     */
    public function applyChanges(
        ?string $weather,
        bool $hasWeather,
        ?float $hour,
        bool $resetTime,
        ?bool $freeze,
        ?float $now = null,
    ): array {
        $ts = $now ?? microtime(true);
        $state = $this->getOrCreateState();

        if ($hasWeather) {
            if ($weather === null || strtoupper($weather) === 'AUTO') {
                $state->setWeatherOverride(null);
            } else {
                $upper = strtoupper($weather);
                if (!WorldAtmosphere::isValidWeather($upper)) {
                    throw new \InvalidArgumentException('Météo invalide.');
                }
                $state->setWeatherOverride($upper);
            }
        }

        if ($resetTime) {
            $state->setFixedTimeOfDay(null);
            $state->setTimeOffsetHours(0.0);
        } elseif ($hour !== null) {
            $target = WorldAtmosphere::normalizeHours($hour);
            $shouldFreeze = $freeze ?? ($state->getFixedTimeOfDay() !== null);
            if ($shouldFreeze) {
                $state->setFixedTimeOfDay($target);
                $state->setTimeOffsetHours(0.0);
            } else {
                $natural = WorldAtmosphere::computeNaturalTimeOfDay($ts);
                $offset = fmod($target - $natural + 24.0, 24.0);
                $state->setFixedTimeOfDay(null);
                $state->setTimeOffsetHours(round($offset, 4));
            }
        } elseif ($freeze !== null) {
            $currentHour = WorldAtmosphere::computeGlobal(
                $ts,
                $state->getWeatherOverride(),
                $state->getTimeOffsetHours(),
                $state->getFixedTimeOfDay(),
            )['timeOfDay'];

            if ($freeze === true) {
                $state->setFixedTimeOfDay($currentHour);
            } else {
                $natural = WorldAtmosphere::computeNaturalTimeOfDay($ts);
                $offset = fmod($currentHour - $natural + 24.0, 24.0);
                $state->setFixedTimeOfDay(null);
                $state->setTimeOffsetHours(round($offset, 4));
            }
        }

        $state->setUpdatedAt($ts);
        $this->em->flush();

        return WorldAtmosphere::computeGlobal(
            $ts,
            $state->getWeatherOverride(),
            $state->getTimeOffsetHours(),
            $state->getFixedTimeOfDay(),
        );
    }

    /**
     * @return array{
     *   timeOfDay: float,
     *   phase: string,
     *   isNight: bool,
     *   weather: string,
     *   weatherLabel: string,
     *   dayCycleSeconds: float,
     *   isTimeFrozen: bool,
     *   isTimeOverridden: bool,
     *   isWeatherOverridden: bool,
     *   weatherOverride: string|null
     * }
     */
    public function resetAll(?float $now = null): array
    {
        $ts = $now ?? microtime(true);
        $state = $this->getOrCreateState();
        $state->resetToAuto($ts);
        $this->em->flush();

        return WorldAtmosphere::computeGlobal($ts);
    }

    private function getOrCreateState(): WorldState
    {
        $state = $this->worldStateRepository->find(WorldState::GLOBAL_ID);
        if (!$state instanceof WorldState) {
            $state = new WorldState(WorldState::GLOBAL_ID);
            $this->em->persist($state);
        }

        return $state;
    }
}
