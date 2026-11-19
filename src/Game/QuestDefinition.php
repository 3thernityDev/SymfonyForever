<?php

declare(strict_types=1);

namespace App\Game;

final readonly class QuestDefinition
{
    public const string TYPE_KILL_MONSTER = 'KILL_MONSTER';
    public const string TYPE_REACH_ZONE = 'REACH_ZONE';

    public function __construct(
        public string $code,
        public int $order,
        public string $title,
        public string $description,
        public string $completionText,
        public string $giverNpcCode,
        public string $turnInNpcCode,
        public int $minLevel,
        public int $recommendedLevel,
        public ?string $prerequisiteQuestCode,
        public string $objectiveType, // KILL_MONSTER | REACH_ZONE
        public string $targetCode,    // monster template code OR zone code
        public string $targetLabel,
        public int $targetCount,
        public float $guideX,
        public float $guideZ,
        public int $rewardXp,
        public int $rewardStatPoints = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'order' => $this->order,
            'title' => $this->title,
            'description' => $this->description,
            'completionText' => $this->completionText,
            'giverNpcCode' => $this->giverNpcCode,
            'turnInNpcCode' => $this->turnInNpcCode,
            'minLevel' => $this->minLevel,
            'recommendedLevel' => $this->recommendedLevel,
            'prerequisiteQuestCode' => $this->prerequisiteQuestCode,
            'objectiveType' => $this->objectiveType,
            'targetCode' => $this->targetCode,
            'targetLabel' => $this->targetLabel,
            'targetCount' => $this->targetCount,
            'guideX' => $this->guideX,
            'guideZ' => $this->guideZ,
            'rewardXp' => $this->rewardXp,
            'rewardStatPoints' => $this->rewardStatPoints,
        ];
    }
}
